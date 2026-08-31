<?php

namespace Teknasyon\Crond\Locker;

class RedisLocker extends BaseLocker implements RecoverableLocker
{
    /**
     * @var \Redis | \RedisCluster
     */
    private $redis;

    /**
     * @var int seconds after which a lock expires on its own; 0 keeps the historic never-expiring behaviour
     */
    private $lockTtl;

    /**
     * @var int lifetime of a host liveness marker; must exceed the crond interval by a couple of ticks
     */
    private $hostHeartbeatTtl;

    /**
     * @var int how long one host owns the right to recover a given lock
     */
    private $recoveryClaimTtl = 30;

    /**
     * @var array<string, float> last refresh time per lock key, for wall-clock rate limiting
     */
    private $lastLockRefreshAt = [];

    /**
     * @param \Redis|\RedisCluster $redisClient
     * @param int $lockTtl 0 (default) reproduces the behaviour of earlier versions: locks never expire
     * @param int $hostHeartbeatTtl
     */
    public function __construct($redisClient, $lockTtl = 0, $hostHeartbeatTtl = 180)
    {
        parent::__construct();
        $this->redis = $redisClient;
        $this->lockTtl = max(0, (int) $lockTtl);
        $this->hostHeartbeatTtl = max(1, (int) $hostHeartbeatTtl);
    }

    public function getLockerInfo()
    {
        return 'RedisLocker';
    }

    public function getLockValue($job)
    {
        return $this->redis->get($this->getJobUniqId($job));
    }

    public function lock($job)
    {
        if (!$job || (is_string($job) === false && is_numeric($job) === false)) {
            throw new \InvalidArgumentException('Job for lock is invalid!');
        }
        $this->resetLockedJob($job);

        $value = $this->generateLockValue($job);

        if ($this->lockTtl > 0) {
            $status = $this->redis->set($this->getJobUniqId($job), $value, ['nx', 'ex' => $this->lockTtl]);
        } else {
            $status = $this->redis->setnx($this->getJobUniqId($job), $value);
        }

        if ($status) {
            $this->setLockedJob($job, $value);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Releases a lock this process took earlier.
     *
     * Only a key still holding this process's own value is deleted. The two "cannot release" cases return false
     * instead of throwing — a key that expired (lockTtl) or was recovered elsewhere is a benign condition, and
     * throwing here used to mask the job's own result in the caller. A key that meanwhile belongs to someone
     * else is left strictly alone: deleting it would release a lock a live process depends on (the old code
     * returned true for that case without deleting, which read as a successful unlock).
     *
     * @param string $job
     * @return bool whether this call deleted the lock
     */
    public function unlock($job)
    {
        $lockedJob = $this->getLockedJob($job);
        if (!$lockedJob || !isset($lockedJob['id'], $lockedJob['value']) || !$lockedJob['value']) {
            // unlock() without a preceding lock() in this process is a caller bug, not a lock state.
            throw new \RuntimeException('Job not locked by me! Job not valid!');
        }

        $storedValue = $this->redis->get($lockedJob['id']);
        $this->resetLockedJob($job);

        if (!is_string($storedValue) || $storedValue === '') {
            return false;
        }

        if ($storedValue !== $lockedJob['value']) {
            return false;
        }

        $this->redis->del($lockedJob['id']);

        return true;
    }

    /**
     * @return bool whether this host already had a marker before this call
     */
    public function heartbeat()
    {
        $key = $this->getHostUniqId(gethostname());
        $wasWarm = (bool) $this->redis->get($key);

        $this->redis->set($key, '1', ['ex' => $this->hostHeartbeatTtl]);

        return $wasWarm;
    }

    /**
     * @param string $hostname
     * @return bool
     */
    public function isHostAlive($hostname)
    {
        $hostname = trim((string) $hostname);

        if ($hostname === '') {
            return false;
        }

        return (bool) $this->redis->get($this->getHostUniqId($hostname));
    }

    /**
     * @param string $job
     * @param string $expectedValue
     * @return bool
     */
    public function releaseLock($job, $expectedValue)
    {
        if ($this->lockTtl > 0) {
            // With an expiry the store cleans dead locks up on its own, and a manual delete opens a race against
            // a fresh owner that takes the key between the read and the delete. Recovery exists for the
            // never-expiring configuration only.
            return false;
        }

        // Two hosts can reach the same verdict in the same tick. Claiming the recovery first means only one of them
        // deletes, which no compare-and-delete on its own would guarantee.
        $claimed = $this->redis->set(
            $this->getRecoveryUniqId($job),
            gethostname() . ';' . getmypid(),
            ['nx', 'ex' => $this->recoveryClaimTtl]
        );

        if (!$claimed) {
            return false;
        }

        $key = $this->getJobUniqId($job);

        // Only single-key commands are used here. A single-key EVAL would be legal on a cluster, but scripting is
        // routed differently by \RedisCluster than by \Redis and this class is handed whichever the caller built.
        if ($this->redis->get($key) !== $expectedValue) {
            return false;
        }

        $this->redis->del($key);

        return true;
    }

    /**
     * Extends the lifetime of this process's own lock, wall-clock rate-limited to lockTtl/3.
     *
     * The compare uses this process's remembered value, so a key that meanwhile belongs to someone else is never
     * extended. (The read and the expire are two commands; the sliver between them is the same class of window
     * releaseLock() has, and it can only extend — never delete — a stranger's key by one ttl in the worst case.)
     *
     * @param string $job
     * @return bool whether the lock is still ours
     */
    public function refreshLock($job)
    {
        if ($this->lockTtl <= 0) {
            return true;
        }

        $lockedJob = $this->getLockedJob($job);
        if (!$lockedJob || !isset($lockedJob['id'], $lockedJob['value']) || !$lockedJob['value']) {
            return false;
        }

        $now = microtime(true);
        $interval = max(1, (int) floor($this->lockTtl / 3));
        $key = $lockedJob['id'];
        if (isset($this->lastLockRefreshAt[$key]) && ($now - $this->lastLockRefreshAt[$key]) < $interval) {
            return true;
        }
        $this->lastLockRefreshAt[$key] = $now;

        if ($this->redis->get($key) !== $lockedJob['value']) {
            return false;
        }

        $this->redis->expire($key, $this->lockTtl);

        return true;
    }

    /**
     * @param int $seconds
     * @return bool
     */
    public function setRecoveryClaimTtl($seconds)
    {
        $this->recoveryClaimTtl = max(1, (int) $seconds);
        return true;
    }

    public function disconnect()
    {
        $this->redis->close();
        return true;
    }
}
