<?php

namespace Teknasyon\Crond\Locker;

class MemcachedLocker extends BaseLocker
{
    /**
     * @var \Memcached;
     */
    private $memcached;

    public function __construct(\Memcached $memcached)
    {
        parent::__construct();

        $this->memcached = $memcached;
        $this->memcached->setOptions([
            \Memcached::OPT_TCP_NODELAY => true,
            \Memcached::OPT_NO_BLOCK => true,
            \Memcached::OPT_CONNECT_TIMEOUT => 60
        ]);
    }

    public function getLockerInfo()
    {
        return 'Memcached ( ' . json_encode($this->memcached->getServerList()) . ' )';
    }

    public function lock($job)
    {
        if (!$job) {
            throw new \RuntimeException('Job for lock is invalid!');
        }

        $this->resetLockedJob($job);

        $value  = $this->generateLockValue($job);
        $status = $this->memcached->add(
            $this->getJobUniqId($job),
            $value
        );

        if ($status) {
            $this->setLockedJob($job, $value);
        }
        return $status;
    }

    public function unlock($job)
    {
        $lockedJob = $this->getLockedJob($job);
        if ($lockedJob && isset($lockedJob['id']) && $lockedJob['id']==$this->getJobUniqId($job)) {
            $this->memcached->delete($lockedJob['id']);
            $this->resetLockedJob($job);
            return true;
        } else {
            throw new \RuntimeException('Job not locked by me!');
        }
    }

    public function disconnect()
    {
        $this->memcached->quit();
        return true;
    }
}
