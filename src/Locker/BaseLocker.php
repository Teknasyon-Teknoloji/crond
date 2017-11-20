<?php

namespace Teknasyon\Crond\Locker;

abstract class BaseLocker implements Locker
{
    protected $uniqIdFunction;
    protected $keyPrefix = 'crondlocker-';
    protected $lockedJobId;

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

    /**
     * @return mixed
     */
    public function getLockedJobId()
    {
        return $this->lockedJobId;
    }

    /**
     * @param mixed $lockedJobId
     */
    public function setLockedJobId($lockedJobId)
    {
        $this->lockedJobId = $lockedJobId;
    }
}
