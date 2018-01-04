<?php

namespace Teknasyon\Crond\Locker;

abstract class BaseLocker implements Locker
{
    protected $uniqIdFunction;
    protected $keyPrefix = 'crondlocker-';
    protected $lockedJobId = [];
    protected $lockedJobValue = [];
    protected $lockedJob = [];

    public function __construct()
    {
        $this->uniqIdFunction = function ($job) {
            return md5(strtolower($job));
        };
    }

    public function setJobUniqIdFunction(\Closure $function)
    {
        $this->uniqIdFunction = $function;
    }

    public function getJobUniqId($job)
    {
        if (!$job) {
            throw new \InvalidArgumentException('Locker::getJobUniqId job param not valid!');
        }
        $func = $this->uniqIdFunction;
        return $this->keyPrefix . $func($job);
    }

    public function generateLockValue($job)
    {
        return gethostname() . '-' . getmypid() . '-' . microtime(true) . '-' . $job;
    }

    public function getLockedJob($job)
    {
        return isset($this->lockedJob[md5($job)])?$this->lockedJob[md5($job)]:null;
    }

    public function setLockedJob($job, $value)
    {
        $this->lockedJob[md5($job)] = ['id' => $this->getJobUniqId($job), 'value' => $value];
    }

    public function resetLockedJob($job)
    {
        $this->lockedJob[md5($job)] = null;
    }
}
