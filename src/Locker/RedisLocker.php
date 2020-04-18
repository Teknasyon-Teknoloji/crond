<?php

namespace Teknasyon\Crond\Locker;

class RedisLocker extends BaseLocker
{
    /**
     * @var \Redis;
     */
    private $redis;

    public function __construct(\Redis $redisClient)
    {
        parent::__construct();

        $this->redis = $redisClient;
        if ($this->redis->isConnected() === false) {
            throw new \RuntimeException('Redis Client not connected!');
        }
    }

    public function getLockerInfo()
    {
        return 'Redis ( '
            . $this->redis->getHost()
            . ':' . $this->redis->getPort()
            . ' -> ' . $this->redis->getDbNum()
            . ' )';
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
        if ($lockedJob && isset($lockedJob['id']) && $lockedJob['id'] == $this->getJobUniqId($job)
            && isset($lockedJob['value']) && $lockedJob['value']) {
            $result = $this->redis->eval(
                '
                    if redis.call("GET", KEYS[1]) == ARGV[1] then
                        return redis.call("DEL", KEYS[1])
                    else
                        return 0
                    end
                ',
                [$lockedJob['id'], $lockedJob['value']],
                1
            );
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
