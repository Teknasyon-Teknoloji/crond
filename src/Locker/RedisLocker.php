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
        if ($this->redis->isConnected()===false) {
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

    public function lock($job, $timeout = 40)
    {
        if (!$job) {
            throw new \RuntimeException('Job for lock is invalid!');
        }

        if ($timeout>0) {
            $value   = time() + $timeout + 1;
            $options = array('nx', 'ex' => $timeout);
        } else {
            $options = array('nx');
            $value   = time() + 2;
        }

        $key    = $this->getJobUniqId($job);
        $status = $this->redis->set(
            $key,
            $value,
            $options
        );
        if ($status) {
            $this->setLockedJobId($this->getJobUniqId($job));
            return true;
        }

        $currentLockTimestamp = $this->redis->get($key);
        if ($currentLockTimestamp > time()) {
            return false;
        }
        $oldLockTimestamp = $this->redis->getSet($key, $value);
        if ($oldLockTimestamp > time()) {
            return false;
        }

        $this->setLockedJobId($this->getJobUniqId($job));
        return true;
    }

    public function unlock($job)
    {
        if ($this->getLockedJobId()==$this->getJobUniqId($job)) {
            $this->redis->delete($this->getJobUniqId($job));
            $this->setLockedJobId('');
        } else {
            throw new \RuntimeException('Job not locked by me!');
        }
    }

    public function disconnect()
    {
        $this->redis->close();
    }
}
