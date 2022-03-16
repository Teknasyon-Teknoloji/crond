<?php

namespace Teknasyon\Crond\Locker;

abstract class BaseLocker implements Locker
{
    protected $uniqIdFunction;
    protected $keyPrefix = 'crondlocker-';
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
        return true;
    }

    public function getJobUniqId($job)
    {
        if (!$job) {
            throw new \InvalidArgumentException('Locker::getJobUniqId job param not valid!');
        }
        $func = $this->uniqIdFunction;
        return $this->keyPrefix . $func($job);
    }

    protected function generateLockValue($job)
    {
        return gethostname() . ';' . getmypid() . ';' . microtime(true) . ';' . $job;
    }

    public function parseLockValue($job, $value)
    {
        $parsedValue = substr($value, 0, strpos($value, ';' . $job));
        list($hostname, $pid, $time) = explode(';', $parsedValue, 3);
        return [
            'hostname' => $hostname,
            'pid' => $pid,
            'time' => $time,
        ];
    }

    protected function getLockedJob($job)
    {
        return $this->lockedJob[md5($job)] ?? null;
    }

    protected function setLockedJob($job, $value)
    {
        $this->lockedJob[md5($job)] = ['id' => $this->getJobUniqId($job), 'value' => $value];
    }

    protected function resetLockedJob($job)
    {
        $this->lockedJob[md5($job)] = null;
    }
}
