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
        $status = $this->memcached->add(
            $this->getJobUniqId($job),
            (time() + 2)
        );

        $this->setLockedJobId(null);
        if ($status) {
            $this->setLockedJobId($this->getJobUniqId($job));
        }
        return $status;
    }

    public function unlock($job)
    {
        if ($this->getLockedJobId()==$this->getJobUniqId($job)) {
            $this->memcached->delete($this->getJobUniqId($job));
            $this->setLockedJobId(null);
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
