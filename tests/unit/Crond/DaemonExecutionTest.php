<?php

use CrondUnitTest\ArgvIsolatedTestCase;
use CrondUnitTest\MockLogger;
use CrondUnitTest\MockRedis;
use Teknasyon\Crond\CronJob;
use Teknasyon\Crond\Daemon;
use Teknasyon\Crond\Locker\BaseLocker;
use Teknasyon\Crond\Locker\RedisLocker;

/**
 * Runs the daemon against real child processes on purpose: pipe draining, exit codes and detachment cannot be
 * proven against mocks. Every spawned command is tiny and self-terminating.
 */
class DaemonExecutionTest extends ArgvIsolatedTestCase
{
    private const JOB = 'execjob';

    /**
     * @var MockRedis
     */
    private $redis;

    /**
     * @var RedisLocker
     */
    private $locker;

    /**
     * @var MockLogger
     */
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new MockRedis();
        $this->locker = new RedisLocker($this->redis);
        $this->logger = new MockLogger();
        $_SERVER['argv'] = [self::JOB, '--run-uniq-cron=' . self::JOB];
    }

    public function testASuccessfulRunReleasesTheLockAndLogsTheFinish()
    {
        $daemon = $this->daemonWithCmd('echo hello-from-cron');

        $daemon->start();

        $this->assertNull($this->redis->get($this->locker->getJobUniqId(self::JOB)), 'The lock was not released.');
        $this->assertStringContainsString('run finished', end($this->logger->logLines['info']));
    }

    public function testAFailingJobReportsItsRealExitCodeAndStillReleasesTheLock()
    {
        $daemon = $this->daemonWithCmd('exit 3');

        try {
            $daemon->start();
            $this->fail('A non-zero exit was expected to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('run failed!', $e->getMessage());
            $this->assertStringContainsString('Retval: 3', $e->getMessage());
        }

        $this->assertNull($this->redis->get($this->locker->getJobUniqId(self::JOB)), 'The lock was not released.');
    }

    /**
     * The old code called unlock() before evaluating the exit code, and unlock() threw when the key was gone —
     * so a genuinely failing job was reported as an unlock problem and its exit code was lost.
     */
    public function testAVanishedLockCannotMaskTheJobsOwnFailure()
    {
        $locker = new class extends BaseLocker {
            public function getLockerInfo()
            {
                return 'VanishingLocker';
            }

            public function getLockValue($job)
            {
                return null;
            }

            public function lock($job)
            {
                return true;
            }

            public function unlock($job)
            {
                // The key expired or was recovered elsewhere while the job ran.
                return false;
            }

            public function disconnect()
            {
                return true;
            }
        };

        $daemon = new Daemon(
            [self::JOB => ['cmd' => 'exit 5', 'expression' => '* * * * *']],
            $locker
        );
        $daemon->setLogger($this->logger);

        try {
            $daemon->start();
            $this->fail('A non-zero exit was expected to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retval: 5', $e->getMessage());
        }

        $this->assertStringContainsString('already gone at unlock time', $this->logger->logLines['warning'][0]);
    }

    /**
     * The finally guarantee: whatever explodes between lock() and the end of the run, the lock is not leaked.
     * This exact leak — a throw after lock() with no unlock — is what kept production crons dead for weeks.
     */
    public function testAnExplosionDuringTheRunStillReleasesTheLock()
    {
        $daemon = new class(
            [self::JOB => ['cmd' => 'echo unused', 'expression' => '* * * * *']],
            $this->locker
        ) extends Daemon {
            protected function executeJobCommand(CronJob $cronJob, $lockId)
            {
                throw new \RuntimeException('boom mid-run');
            }
        };
        $daemon->setLogger($this->logger);

        try {
            $daemon->start();
            $this->fail('The mid-run explosion was expected to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom mid-run', $e->getMessage());
        }

        $this->assertNull($this->redis->get($this->locker->getJobUniqId(self::JOB)), 'The lock leaked.');
    }

    public function testAChattyJobIsNeitherStalledNorBufferedUnboundedly()
    {
        // 1 MB of output: far beyond any pipe buffer. An undrained pipe provably stalls the child; an unbounded
        // buffer is what used to push runners of long chatty jobs into memory_limit.
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo str_repeat("x", 1024 * 1024); exit(7);');
        $daemon = $this->daemonWithCmd($cmd);

        $startedAt = microtime(true);
        try {
            $daemon->start();
            $this->fail('Exit code 7 was expected to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retval: 7', $e->getMessage());
            $this->assertLessThan(
                Daemon::OUTPUT_TAIL_BYTES + 2048,
                strlen($e->getMessage()),
                'The exception carries more than the bounded output tail.'
            );
        }

        $this->assertLessThan(5.0, microtime(true) - $startedAt, 'The writer stalled against an undrained pipe.');
        $this->assertNull($this->redis->get($this->locker->getJobUniqId(self::JOB)));
    }

    public function testATimedOutJobIsTerminatedAndReportedAsTimedOut()
    {
        $daemon = $this->daemonWithCmd('sleep 30', ['timeout' => 1]);

        $startedAt = microtime(true);
        try {
            $daemon->start();
            $this->fail('The timeout was expected to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('run timed out!', $e->getMessage());
        }

        $this->assertLessThan(10.0, microtime(true) - $startedAt, 'The runner waited past the timeout.');
        $this->assertNull($this->redis->get($this->locker->getJobUniqId(self::JOB)), 'The lock was not released.');
    }

    /**
     * Spawn observability, end to end: a runner whose script does not exist dies within milliseconds, and the
     * daemon tick — the only process that can see it — must say so. This exact case used to log "started".
     */
    public function testARunnerThatCannotStartIsReportedBySpawnCheck()
    {
        $_SERVER['argv'] = ['/nonexistent/crond-self.php'];
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => date('i') . ' * * * *']],
            $this->locker
        );
        $daemon->setLogger($this->logger);

        $daemon->start();

        $this->assertNotEmpty($this->logger->logLines['error'], 'The dead-on-arrival runner produced no error.');
        $this->assertStringContainsString('spawn failed!', $this->logger->logLines['error'][0]);
    }

    /**
     * The 36fa9bf regression guard: a daemon tick must fire its runners and exit, never wait for them.
     */
    public function testTheDaemonTickDoesNotWaitForItsSpawnedRunners()
    {
        $_SERVER['argv'] = [__DIR__ . '/../fixtures/sleeper.php'];
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => date('i') . ' * * * *']],
            $this->locker
        );
        $daemon->setLogger($this->logger);

        $startedAt = microtime(true);
        $daemon->start();
        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(1.5, $elapsed, 'The tick waited for a 2-second runner: fire-and-forget is broken.');
        $this->assertStringContainsString('spawned', $this->logger->logLines['info'][1]);
    }

    /**
     * @param string $cmd
     * @param array $extraConfig
     * @return Daemon
     */
    private function daemonWithCmd($cmd, array $extraConfig = [])
    {
        $daemon = new Daemon(
            [self::JOB => $extraConfig + ['cmd' => $cmd, 'expression' => '* * * * *']],
            $this->locker
        );
        $daemon->setLogger($this->logger);

        return $daemon;
    }
}
