<?php

namespace Teknasyon\Crond\Locker;

class RedisLocker extends BaseLocker
{
    /**
     * @var \Redis | \RedisCluster
     */
    private $redis;

    public function __construct($redisClient)
    {
        parent::__construct();
        $this->redis = $redisClient;
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
        $status = $this->redis->setnx($this->getJobUniqId($job), $value);

        if ($status) {
            $this->setLockedJob($job, $value);
            return true;
        } else {
            return false;
        }
    }

    public function unlock($job)
    {
        $lockedJob = $this->getLockedJob($job);
        if (
            $lockedJob &&
            isset($lockedJob['id']) &&
            $lockedJob['id'] == $this->getJobUniqId($job) &&
            isset($lockedJob['value']) && $lockedJob['value']
        ) {
            $result = $this->redis->get($lockedJob['id']);
            if ($result == $lockedJob['value']) {
                $this->redis->del($lockedJob['id']);
            }

            if ($result) {
                $this->resetLockedJob($job);
                return true;
            } else {
                throw new \RuntimeException('Job not locked by me!');
            }
        } else {
            throw new \RuntimeException('Job not locked by me!');
        }
    }

    public function disconnect()
    {
        $this->redis->close();
        return true;
    }
}
