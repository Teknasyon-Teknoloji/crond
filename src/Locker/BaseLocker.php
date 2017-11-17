<?php

namespace Teknasyon\Crond\Locker;

abstract class BaseLocker implements Locker
{
    protected $uniqIdFunction;
    protected $keyPrefix = 'sqslocker-';
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
        if ($this->uniqIdFunction) {
            $func = $this->uniqIdFunction;
            return $this->keyPrefix . $func($job);
        } else {
            throw new \InvalidArgumentException('Locker::uniqIdFunction not defined!');
        }
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
