<?php

namespace Teknasyon\Crond;

use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Teknasyon\Crond\Locker\BaseLocker;
use Teknasyon\Crond\Locker\Locker;
use Teknasyon\Crond\Locker\RecoverableLocker;

class Daemon
{
    /** How long a daemon tick waits before probing its spawned runners once. This is the only process that can
     *  observe a runner that could not start, and the window must cover a PHP startup that fails late: a missing
     *  script dies only after the interpreter is up, measured at ~125ms on a developer machine — hence half a
     *  second, which is nothing against the tick's one-minute budget. */
    const SPAWN_CHECK_DELAY_US = 500000;

    /** stream_select timeout while waiting for job output: wakes immediately on output, at worst once a second. */
    const SELECT_TIMEOUT_SECONDS = 1;

    /** Wait between status polls when the job closed its pipes but is still running (a job that daemonized its own
     *  child). Without it the loop would spin at 100% CPU for the whole runtime of the job. */
    const PIPE_IDLE_WAIT_US = 200000;

    /** Only the tail of the job output is kept; a chatty job must not push the runner into memory_limit. */
    const OUTPUT_TAIL_BYTES = 8192;

    /** Seconds between SIGTERM and SIGKILL when a per-job timeout fires. */
    const TERMINATE_GRACE_SECONDS = 5;

    /** How often a long-running job refreshes this host's liveness marker from inside the run loop. */
    const HEARTBEAT_REFRESH_SECONDS = 60;

    /**
     * @var CronJob[]
     */
    private $cronList = [];

    /**
     * @var Locker
     */
    private $locker;
    private $logger;
    private $cronArgName = 'run-uniq-cron';
    /**
     * @var CronJob
     */
    private $lastRunnedCronJob;

    private $outputFile;

    /**
     * @var string|null IANA timezone the cron expressions are evaluated in; null keeps the historic behaviour of
     *                  using whatever date_default_timezone_get() happens to answer
     */
    private $timezone;

    /**
     * @var ProcessProbe
     */
    private $processProbe;

    /**
     * @var bool releasing a lock changes what other processes see, so it stays opt-in
     */
    private $autoReleaseDeadLocks = false;

    /**
     * @var bool whether the host registry already knew this host when the current run started
     */
    private $hostRegistryWarm = false;

    /**
     * @var float when this host's liveness marker was last written
     */
    private $lastHeartbeatAt = 0.0;

    /**
     * @var LockVerdict|null
     */
    private $lastLockVerdict;

    /**
     * @var string|null
     */
    private $lastLockVerdictReason;

    /**
     * @var array|null hostname, pid and time of the process holding the lock we could not take
     */
    private $lastLockHolder;

    /**
     * @var bool
     */
    private $lastLockReleased = false;

    /**
     * @param array $cronConfigList
     * @param Locker $locker
     * @param $outputFile
     */
    public function __construct(array $cronConfigList, Locker $locker, $outputFile = '/dev/null')
    {
        $this->outputFile = $outputFile;
        $lockKeyOwners = [];
        foreach ($cronConfigList as $cronId => $cronConfig) {
            if (preg_match('/[^a-zA-Z0-9_\-.]/', (string) $cronId)) {
                throw new \InvalidArgumentException(
                    'CronId #' . $cronId . ' is invalid! Use only "a-z","A-z","0-9","-","_" and "."'
                );
            }
            if (!is_array($cronConfig)) {
                throw new \InvalidArgumentException('Cron #' . $cronId . ' config must be an array!');
            }

            $cmd = isset($cronConfig['cmd']) ? $cronConfig['cmd'] : null;
            if (!is_string($cmd) || trim($cmd) === '') {
                // An empty cmd would otherwise fail much later and completely silently: `sh -c ''` exits 0
                // without doing anything.
                throw new \InvalidArgumentException('Cron #' . $cronId . ' cmd is missing or empty!');
            }

            $expression = isset($cronConfig['expression']) ? $cronConfig['expression'] : null;
            if (!is_string($expression) || trim($expression) === '') {
                throw new \InvalidArgumentException('Cron #' . $cronId . ' expression is missing or empty!');
            }

            // Lock keys are derived from md5(strtolower(id)): two ids differing only by case would share one lock
            // and the second job could never run while the first holds it — permanently, and without any hint why.
            $caseKey = strtolower((string) $cronId);
            if (isset($lockKeyOwners[$caseKey])) {
                throw new \InvalidArgumentException(
                    'Cron #' . $cronId . ' collides with #' . $lockKeyOwners[$caseKey]
                    . ': cron ids are case-insensitive for locking!'
                );
            }
            $lockKeyOwners[$caseKey] = (string) $cronId;

            try {
                $this->cronList[$cronId] = new CronJob(
                    $cronId,
                    $expression,
                    $cmd,
                    !((isset($cronConfig['lock']) && $cronConfig['lock'] == false)),
                    isset($cronConfig['timeout']) ? (int) $cronConfig['timeout'] : 0,
                    (isset($cronConfig['output']) && is_string($cronConfig['output']) && $cronConfig['output'] !== '')
                        ? $cronConfig['output'] : null
                );
            } catch (\InvalidArgumentException $e) {
                // Re-thrown with the cron id: with dozens of jobs in one config, "expression not valid" alone does
                // not say which entry is broken.
                throw new \InvalidArgumentException('Cron #' . $cronId . ': ' . $e->getMessage(), 0, $e);
            }
        }
        $this->locker = $locker;
    }

    /**
     * @param LoggerInterface $logger
     * @return bool
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        return true;
    }

    protected function log($type, $message)
    {
        $this->logWithContext($type, $message);
    }

    /**
     * PSR-3 context makes the structured detail (cron id, pid, exit code, lock verdict) queryable in a log
     * aggregator instead of being regex-fodder inside the message. Kept separate from log() so subclasses that
     * overrode the old two-argument signature keep compiling.
     *
     * @param string $type
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logWithContext($type, $message, array $context = [])
    {
        $this->logger?->{$type}($message, $context);
    }

    /**
     * @param ProcessProbe $processProbe
     * @return bool
     */
    public function setProcessProbe(ProcessProbe $processProbe)
    {
        $this->processProbe = $processProbe;
        return true;
    }

    /**
     * @return ProcessProbe
     */
    public function getProcessProbe()
    {
        if ($this->processProbe === null) {
            $this->processProbe = new SystemProcessProbe();
        }
        return $this->processProbe;
    }

    /**
     * Lets the daemon delete a lock once it has proven the holder is gone, so the job runs again on the next tick
     * instead of staying stuck until somebody clears the key by hand.
     *
     * Off by default: a lock disappearing is visible to everything else using the same store.
     *
     * @param bool $enabled
     * @return bool
     */
    public function setAutoReleaseDeadLocks($enabled)
    {
        $this->autoReleaseDeadLocks = (bool) $enabled;
        return true;
    }

    /**
     * Sets the timezone the cron expressions are evaluated in. Without it the schedule follows the process-wide
     * default timezone — a global, mutable value the host application may change at any point, and one that makes
     * daily jobs silently skip (spring forward) or run twice (fall back) around DST transitions.
     *
     * @param string|null $timezone IANA name, e.g. 'UTC' or 'Europe/Berlin'; null restores the historic behaviour
     * @return bool
     */
    public function setTimezone($timezone)
    {
        if ($timezone !== null) {
            try {
                new \DateTimeZone($timezone);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException('Timezone "' . $timezone . '" is not valid!');
            }
        }
        $this->timezone = $timezone;
        return true;
    }

    /**
     * @return LockVerdict|null verdict of the most recent failed lock attempt
     */
    public function getLastLockVerdict()
    {
        return $this->lastLockVerdict;
    }

    /**
     * @return string|null one of the LockVerdict::REASON_* constants
     */
    public function getLastLockVerdictReason()
    {
        return $this->lastLockVerdictReason;
    }

    /**
     * @return array|null ['hostname' => ..., 'pid' => ..., 'time' => ...]
     */
    public function getLastLockHolder()
    {
        return $this->lastLockHolder;
    }

    /**
     * @return bool whether the most recent failed lock attempt ended with the dead lock being released
     */
    public function isLastLockReleased()
    {
        return $this->lastLockReleased;
    }

    public function isDaemon()
    {
        return php_sapi_name() == 'cli'
            && !str_contains(trim(implode(' ', $_SERVER['argv'])), ' --' . $this->cronArgName . '=');
    }

    /**
     * The runner invocation as an argv array — the canonical form. Spawning from the array keeps the shell out
     * entirely, so paths and arguments containing spaces or metacharacters cannot be re-split or interpreted.
     *
     * PHP_BINARY replaces the old hardcoded 'php': under system cron PATH is often too narrow to resolve `php`,
     * and the failure was invisible because a shell-backgrounded exec always reported success.
     *
     * @param string $cronId
     * @return array
     */
    public function getRunArgs($cronId)
    {
        $argv = $_SERVER['argv'];
        $selfPhp = (string) array_shift($argv);
        if ($selfPhp === '' || substr($selfPhp, 0, 1) != DIRECTORY_SEPARATOR) {
            $selfPhp = getcwd() . DIRECTORY_SEPARATOR . $selfPhp;
        }

        return array_merge(
            [PHP_BINARY !== '' ? PHP_BINARY : 'php', $selfPhp],
            array_values($argv),
            ['--' . $this->cronArgName . '=' . $cronId]
        );
    }

    /**
     * Shell-safe string form of getRunArgs(), for logging and for running the same invocation by hand.
     *
     * @param string $cronId
     * @return string
     */
    public function getRunCmd($cronId)
    {
        return implode(' ', array_map('escapeshellarg', $this->getRunArgs($cronId)));
    }

    private function crond()
    {
        $this->log('info', 'Crond started');

        if (empty($this->cronList)) {
            // An empty config is a config that failed to load somewhere upstream, not a quiet no-op.
            $this->log('warning', 'Crond has no cron jobs configured!');
            return;
        }

        // One clock for the whole tick: evaluating each job against a fresh "now" lets a slow loop cross the
        // minute boundary mid-list, silently dropping that minute for every job after the crossing.
        $now = $this->currentTime();

        $spawned = [];
        foreach ($this->cronList as $cronJob) {
            if ($this->isCronJobDue($cronJob, $now) === false) {
                continue;
            }

            $handle = $this->spawnDetachedRunner($this->getRunArgs($cronJob->getId()), $this->outputFileFor($cronJob));
            if ($handle === false) {
                $this->logWithContext('error', $cronJob . ' spawn failed!', ['cronId' => $cronJob->getId()]);
                continue;
            }

            $spawned[] = ['job' => $cronJob, 'handle' => $handle];
        }

        if ($spawned === []) {
            return;
        }

        // One short grace for the whole tick, then one status probe per child. A runner that never started leaves
        // no other trace: it took no lock, wrote no log, and the same silent death would repeat every minute.
        $this->waitBeforeSpawnCheck();
        foreach ($spawned as $entry) {
            $status = $this->probeSpawn($entry['handle']);
            if (is_array($status) && $status['running'] === false && (int) $status['exitcode'] !== 0) {
                $this->logWithContext('error', $entry['job'] . ' spawn failed!', [
                    'cronId' => $entry['job']->getId(),
                    'exitcode' => (int) $status['exitcode'],
                ]);
            } else {
                $this->logWithContext('info', $entry['job'] . ' spawned', [
                    'cronId' => $entry['job']->getId(),
                    'pid' => is_array($status) && isset($status['pid']) ? $status['pid'] : null,
                ]);
            }
        }
    }

    /**
     * @return \DateTime
     */
    protected function currentTime()
    {
        return new \DateTime('now');
    }

    /**
     * @param CronJob $cronJob
     * @param \DateTimeInterface $now
     * @return bool
     */
    protected function isCronJobDue(CronJob $cronJob, $now)
    {
        $cronExpression = new CronExpression($cronJob->getExpression());
        return $cronExpression->isDue($now, $this->timezone);
    }

    /**
     * Starts a runner in the background without involving a shell.
     *
     * proc_close() is never called on purpose: closing would wait for the child, and the whole point of the
     * daemon tick is to fire and exit. The child is reparented and lives on (verified empirically); the returned
     * handle exists only so the spawn can be probed once before the tick ends.
     *
     * @param array $args
     * @param string $outputFile
     * @return resource|false
     */
    protected function spawnDetachedRunner(array $args, $outputFile)
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outputFile, 'a'],
            2 => ['file', $outputFile, 'a'],
        ];

        $pipes = [];
        $handle = @proc_open($args, $descriptors, $pipes);

        return is_resource($handle) ? $handle : false;
    }

    /**
     * @param resource $handle
     * @return array|null
     */
    protected function probeSpawn($handle)
    {
        if (!is_resource($handle)) {
            return null;
        }

        return proc_get_status($handle);
    }

    /**
     * @return void
     */
    protected function waitBeforeSpawnCheck()
    {
        usleep(self::SPAWN_CHECK_DELAY_US);
    }

    /**
     * @param CronJob $cronJob
     * @return string
     */
    private function outputFileFor(CronJob $cronJob)
    {
        $outputFile = $cronJob->getOutputFile();

        return $outputFile !== null ? $outputFile : $this->outputFile;
    }

    public function getCronIdArg()
    {
        $cronId = null;
        foreach ($_SERVER['argv'] as $arg) {
            if (str_starts_with(trim($arg), '--' . $this->cronArgName . '=')) {
                $cronId = explode('--' . $this->cronArgName . '=', $arg);
                $cronId = trim($cronId[1]);
                break;
            }
        }
        return $cronId;
    }

    /**
     * @return CronJob
     */
    public function getLastRunnedCronJob()
    {
        return $this->lastRunnedCronJob;
    }

    /**
     * Decides whether the process that holds $lockId is still alive.
     *
     * Every branch that answers Dead is backed by positive evidence, and every branch that cannot find such
     * evidence answers Unknown rather than guessing. That distinction is the whole point of this method: a lock
     * whose holder simply lives on another host used to be reported as "no deadlock", which is indistinguishable
     * from "that host was replaced an hour ago and is never coming back".
     *
     * @param string $lockId
     * @param string|null $lockedJobValue the value read by the caller, so the verdict and any release that follows
     *                                    are about one and the same lock
     * @return LockVerdict
     */
    private function checkLock($lockId, $lockedJobValue)
    {
        $this->lastLockHolder = null;

        if ($this->lastRunnedCronJob->isLockRequired() === false) {
            // The key carries the current minute, so it cannot outlive the schedule.
            return $this->verdict(LockVerdict::Alive, LockVerdict::REASON_LOCK_NOT_REQUIRED);
        }

        if (!$lockedJobValue) {
            return $this->verdict(LockVerdict::Unknown, LockVerdict::REASON_LOCK_VALUE_MISSING);
        }

        $parsedJobValue = $this->locker->parseLockValue($lockId, $lockedJobValue);
        if (!$parsedJobValue['hostname'] || !$parsedJobValue['pid']) {
            return $this->verdict(LockVerdict::Unknown, LockVerdict::REASON_LOCK_VALUE_INVALID);
        }

        $this->lastLockHolder = $parsedJobValue;

        if ($parsedJobValue['hostname'] != gethostname()) {
            return $this->checkForeignHost($parsedJobValue['hostname']);
        }

        if ($parsedJobValue['pid'] == getmypid()) {
            return $this->verdict(LockVerdict::Alive, LockVerdict::REASON_HOLDER_IS_SELF);
        }

        $probe = $this->getProcessProbe();

        if ($probe->isProcessRunning($parsedJobValue['pid'], ' --' . $this->cronArgName . '=' . $lockId)) {
            return $this->verdict(LockVerdict::Alive, LockVerdict::REASON_HOLDER_PROCESS_RUNNING);
        }

        if ($probe->isCommandRunning($this->lastRunnedCronJob->getCmd())) {
            return $this->verdict(LockVerdict::Alive, LockVerdict::REASON_HOLDER_COMMAND_RUNNING);
        }

        // "Not in the process table" is the evidence a release would rest on, so it counts only when the process
        // table can be read at all. A probe that answers nothing must not look like an empty process table.
        if (!$probe->isUsable()) {
            return $this->verdict(LockVerdict::Unknown, LockVerdict::REASON_PROCESS_PROBE_UNAVAILABLE);
        }

        return $this->verdict(LockVerdict::Dead, LockVerdict::REASON_HOLDER_PROCESS_GONE);
    }

    /**
     * A holder on another machine can only be judged through the host registry: `ps` says nothing about it.
     *
     * @param string $hostname
     * @return LockVerdict
     */
    private function checkForeignHost($hostname)
    {
        if (!$this->locker instanceof RecoverableLocker) {
            return $this->verdict(LockVerdict::Unknown, LockVerdict::REASON_HOST_REGISTRY_UNSUPPORTED);
        }

        if ($this->locker->isHostAlive($hostname)) {
            return $this->verdict(LockVerdict::Alive, LockVerdict::REASON_HOLDER_HOST_ALIVE);
        }

        // A registry that has not yet seen this host has no opinion about any other host either, and concluding
        // "gone" from it would release a lock a running sibling still holds.
        if (!$this->hostRegistryWarm) {
            return $this->verdict(LockVerdict::Unknown, LockVerdict::REASON_HOST_REGISTRY_COLD);
        }

        return $this->verdict(LockVerdict::Dead, LockVerdict::REASON_HOLDER_HOST_GONE);
    }

    /**
     * @param LockVerdict $verdict
     * @param string $reason
     * @return LockVerdict
     */
    private function verdict(LockVerdict $verdict, $reason)
    {
        $this->lastLockVerdict = $verdict;
        $this->lastLockVerdictReason = $reason;

        return $verdict;
    }

    /**
     * @param LockVerdict $verdict
     * @param string $lockId
     * @param string $lockValue
     * @return bool
     */
    private function recoverLock(LockVerdict $verdict, $lockId, $lockValue)
    {
        if ($verdict !== LockVerdict::Dead || !$this->autoReleaseDeadLocks) {
            return false;
        }

        if (!$this->locker instanceof RecoverableLocker) {
            return false;
        }

        return (bool) $this->locker->releaseLock($lockId, $lockValue);
    }

    private function runJob()
    {
        $cronId = $this->getCronIdArg();
        if (!$cronId || isset($this->cronList[$cronId]) === false) {
            throw new \InvalidArgumentException(
                'Cron-id argument not found! ARGV: ' . json_encode($_SERVER['argv'])
            );
        }
        $this->lastRunnedCronJob = $this->cronList[$cronId];

        $lockId = $cronId . ($this->lastRunnedCronJob->isLockRequired() === false ? date('YmdHi') : '');
        $locked = $this->locker->lock($lockId);
        if ($locked === false) {
            $this->failOnHeldLock($cronId, $lockId);
        }

        $startedAt = microtime(true);
        $this->logWithContext('info', 'Cron #' . $cronId . ' run started', [
            'cronId' => $cronId,
            'lockId' => $lockId,
            'pid' => getmypid(),
        ]);

        $result = null;
        try {
            // Everything from here on runs under the lock; the finally is what guarantees the lock cannot be
            // leaked by a throw, a fatal turned exception, or a SIGTERM-induced shutdown between lock and unlock.
            $result = $this->executeJobCommand($this->lastRunnedCronJob, $lockId);
        } finally {
            $this->releaseOwnLock($cronId, $lockId);
        }

        $durationSeconds = round(microtime(true) - $startedAt, 3);

        if ($result['timedOut']) {
            $this->logWithContext('error', 'Cron #' . $cronId . ' run timed out!', [
                'cronId' => $cronId,
                'timeoutSeconds' => $this->lastRunnedCronJob->getTimeout(),
                'durationSeconds' => $durationSeconds,
            ]);
            throw new \RuntimeException(
                'Cron #' . $cronId . ' run timed out!'
                . ' LockId: ' . $lockId . ', Timeout: ' . $this->lastRunnedCronJob->getTimeout()
                . 's, Output: ' . json_encode($result['output'])
            );
        }

        if ($result['exitcode'] !== 0) {
            $this->logWithContext('error', 'Cron #' . $cronId . ' run failed!', [
                'cronId' => $cronId,
                'retval' => $result['exitcode'],
                'durationSeconds' => $durationSeconds,
            ]);
            throw new \RuntimeException(
                'Cron #' . $cronId . ' run failed!'
                . ' LockId: ' . $lockId . ', Retval: ' . $result['exitcode']
                . ', Output: ' . json_encode($result['output'])
            );
        }

        $this->logWithContext('info', 'Cron #' . $cronId . ' run finished', [
            'cronId' => $cronId,
            'retval' => 0,
            'durationSeconds' => $durationSeconds,
        ]);
    }

    /**
     * Builds the verdict for a lock that could not be taken, optionally recovers it, logs it queryably, throws.
     *
     * @param string $cronId
     * @param string $lockId
     * @return never
     */
    private function failOnHeldLock($cronId, $lockId)
    {
        // Read once, before the verdict: the value the verdict is about is also the value a release must match,
        // and it is what earlier versions reported here under the wrong key for lock-free jobs.
        $lockValue = $this->locker->getLockValue($lockId);
        $verdict = $this->checkLock($lockId, $lockValue);
        $this->lastLockReleased = $this->recoverLock($verdict, $lockId, $lockValue);

        // getJobUniqId() lives on BaseLocker, not on the Locker interface; a locker built directly on the
        // interface must not fatal here of all places.
        $lockKey = $this->locker instanceof BaseLocker
            ? $this->locker->getJobUniqId($lockId)
            : '(locker without key derivation)';

        $level = match ($verdict) {
            LockVerdict::Alive => 'info',
            LockVerdict::Unknown => 'warning',
            LockVerdict::Dead => 'error',
        };
        $this->logWithContext($level, 'Cron #' . $cronId . ' lock failed', [
            'cronId' => $cronId,
            'lockId' => $lockId,
            'lockVerdict' => $verdict->value,
            'lockVerdictReason' => $this->lastLockVerdictReason,
            'lockHolder' => $this->lastLockHolder,
            'lockReleased' => $this->lastLockReleased,
        ]);

        throw new \RuntimeException(
            'Cron #' . $cronId . ' lock failed! jobName: ' . $lockId . ', lockId: ' . $lockKey
            . ', lockValue: ' . $lockValue
            . $verdict->messageMarker((string) $this->lastLockVerdictReason)
            . ($this->lastLockReleased ? ' ( Dead lock released! )' : '')
        );
    }

    /**
     * Runs the job command and waits for it without buffering its output unboundedly.
     *
     * The command is a user-supplied string and has always been executed through the shell; string-form proc_open
     * keeps that contract byte for byte. The wait is a stream_select loop, not a sleep poll: a pipe left undrained
     * blocks the child as soon as the pipe buffer fills (verified: a 1 MB writer stalled for the whole wait).
     *
     * @param CronJob $cronJob
     * @param string $lockId
     * @return array{exitcode: int, output: string, timedOut: bool}
     */
    protected function executeJobCommand(CronJob $cronJob, $lockId)
    {
        $pipes = [];
        $process = @proc_open($cronJob->getCmd(), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false || !is_resource($process)) {
            throw new \RuntimeException('Cron #' . $cronJob->getId() . ' spawn failed!');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $timeout = (int) $cronJob->getTimeout();
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
        $killAt = null;
        $timedOut = false;

        while (true) {
            $read = [];
            foreach ($pipes as $pipe) {
                if (is_resource($pipe) && !feof($pipe)) {
                    $read[] = $pipe;
                }
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                // @: EINTR raises a warning and returns false; the loop just goes around again.
                if (@stream_select($read, $write, $except, self::SELECT_TIMEOUT_SECONDS) !== false) {
                    foreach ($read as $pipe) {
                        $chunk = fread($pipe, 65536);
                        if (is_string($chunk) && $chunk !== '') {
                            $output = substr($output . $chunk, -self::OUTPUT_TAIL_BYTES);
                        }
                    }
                }
            } else {
                usleep(self::PIPE_IDLE_WAIT_US);
            }

            $status = proc_get_status($process);
            if ($status['running'] === false) {
                // The exit code is only valid on the first status call after death; later calls answer -1.
                return ['exitcode' => (int) $status['exitcode'], 'output' => $output, 'timedOut' => $timedOut];
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                if ($timedOut === false) {
                    $timedOut = true;
                    $killAt = microtime(true) + self::TERMINATE_GRACE_SECONDS;
                    // Best effort: the shell form means compound commands may leave grandchildren behind.
                    @proc_terminate($process);
                } elseif ($killAt !== null && microtime(true) >= $killAt) {
                    $killAt = null;
                    @proc_terminate($process, 9);
                }
            }

            $this->refreshLockIfDue($lockId);
            $this->refreshHostHeartbeatIfDue();
        }
    }

    /**
     * Releases this run's own lock without ever letting the release mask what the job did.
     *
     * @param string $cronId
     * @param string $lockId
     * @return void
     */
    private function releaseOwnLock($cronId, $lockId)
    {
        try {
            if ($this->locker->unlock($lockId) === false) {
                // Expired lockTtl or a recovery elsewhere — worth a line, but the run's own outcome matters more.
                $this->logWithContext('warning', 'Cron #' . $cronId . ' lock was already gone at unlock time', [
                    'cronId' => $cronId,
                    'lockId' => $lockId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logWithContext('warning', 'Cron #' . $cronId . ' unlock failed! ' . $e->getMessage(), [
                'cronId' => $cronId,
                'lockId' => $lockId,
            ]);
        }
    }

    /**
     * @param string $lockId
     * @return void
     */
    private function refreshLockIfDue($lockId)
    {
        if (!$this->locker instanceof RecoverableLocker) {
            return;
        }

        try {
            $this->locker->refreshLock($lockId);
        } catch (\Throwable $e) {
            // A refresh hiccup must never kill a running job; the lock survives on its TTL headroom.
            $this->logWithContext('warning', 'Cron lock refresh failed! ' . $e->getMessage(), ['lockId' => $lockId]);
        }
    }

    /**
     * Keeps this host's liveness marker fresh while a long job runs. The marker is otherwise written only by the
     * per-minute daemon tick — if that tick is stopped during a multi-hour job, the marker would expire and
     * another host with auto-release enabled would take the silence as proof that this host is gone.
     *
     * @return void
     */
    private function refreshHostHeartbeatIfDue()
    {
        if (!$this->locker instanceof RecoverableLocker) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastHeartbeatAt) < self::HEARTBEAT_REFRESH_SECONDS) {
            return;
        }
        $this->lastHeartbeatAt = $now;

        try {
            $this->locker->heartbeat();
            $this->hostRegistryWarm = true;
        } catch (\Throwable $e) {
            $this->logWithContext('warning', 'Crond host heartbeat failed! ' . $e->getMessage());
        }
    }

    private function isListCronJobs()
    {
        return in_array('--show-crons', $_SERVER['argv']) || in_array('--list-crons', $_SERVER['argv']);
    }

    private function echoCronJobs()
    {
        echo 'Cron Jobs : ' . PHP_EOL;

        foreach ($this->cronList as $cronId => $cronJob) {
            echo 'Cron #' . $cronJob->getId() . ' : ' . PHP_EOL;
            echo $cronJob->getExpression() . ' ' . $cronJob->getCmd()
                . ($cronJob->isLockRequired() ? ' (LOCK REQUIRED)' : '') . PHP_EOL;
        }
    }

    /**
     * Publishes this host's liveness marker so other hosts can later tell whether it disappeared.
     *
     * A store that cannot record it is not fatal here; it only means foreign holders stay undecidable, which is
     * exactly what the cold-registry branch reports.
     *
     * @return void
     */
    private function markHostAlive()
    {
        if (!$this->locker instanceof RecoverableLocker) {
            $this->hostRegistryWarm = false;
            return;
        }

        try {
            $this->hostRegistryWarm = (bool) $this->locker->heartbeat();
            $this->lastHeartbeatAt = microtime(true);
        } catch (\Throwable $e) {
            $this->hostRegistryWarm = false;
            $this->log('warning', 'Crond host heartbeat failed! ' . $e->getMessage());
        }
    }

    public function start()
    {
        $this->lastLockVerdict = null;
        $this->lastLockVerdictReason = null;
        $this->lastLockHolder = null;
        $this->lastLockReleased = false;

        if ($this->isListCronJobs()) {
            $this->echoCronJobs();
        } else {
            $this->markHostAlive();
            if ($this->isDaemon()) {
                $this->crond();
            } else {
                $this->runJob();
            }
        }
    }
}
