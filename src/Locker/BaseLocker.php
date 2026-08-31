<?php

namespace Teknasyon\Crond\Locker;

abstract class BaseLocker implements Locker
{
    protected $uniqIdFunction;
    protected $keyPrefix = 'crondlocker-';
    protected $hostKeyPrefix = 'crondhost-';
    protected $recoveryKeyPrefix = 'crondrecovery-';
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

    /**
     * @param string $hostname
     * @return string
     */
    public function getHostUniqId($hostname)
    {
        $hostname = substr(trim((string) $hostname), 0, 253);

        if ($hostname === '') {
            throw new \InvalidArgumentException('Locker::getHostUniqId hostname param not valid!');
        }

        return $this->hostKeyPrefix . $hostname;
    }

    /**
     * @param string $job
     * @return string
     */
    public function getRecoveryUniqId($job)
    {
        if (!$job) {
            throw new \InvalidArgumentException('Locker::getRecoveryUniqId job param not valid!');
        }
        $func = $this->uniqIdFunction;
        return $this->recoveryKeyPrefix . $func($job);
    }

    protected function generateLockValue($job)
    {
        return gethostname() . ';' . getmypid() . ';' . microtime(true) . ';' . $job;
    }

    /**
     * Splits a lock value into its parts.
     *
     * Returns nulls rather than raising warnings when the value does not belong to this job: strpos() answers false
     * for a foreign or corrupted value, and the previous substr($value, 0, false) turned that into an empty string
     * whose explode() left undefined offsets behind.
     */
    public function parseLockValue($job, $value)
    {
        $position = strpos((string) $value, ';' . $job);

        if ($position === false) {
            return [
                'hostname' => null,
                'pid' => null,
                'time' => null,
            ];
        }

        $parts = explode(';', substr((string) $value, 0, $position), 3);

        return [
            'hostname' => isset($parts[0]) ? $parts[0] : null,
            'pid' => isset($parts[1]) ? $parts[1] : null,
            'time' => isset($parts[2]) ? $parts[2] : null,
        ];
    }

    /**
     * The in-process map is keyed by the very same derivation as the storage key. Keying it differently (the old
     * md5($job) vs md5(strtolower($job))) made lock('MyJob') + unlock('myjob') throw and leak the Redis key.
     */
    protected function getLockedJob($job)
    {
        return $this->lockedJob[$this->getJobUniqId($job)] ?? null;
    }

    protected function setLockedJob($job, $value)
    {
        $this->lockedJob[$this->getJobUniqId($job)] = ['id' => $this->getJobUniqId($job), 'value' => $value];
    }

    protected function resetLockedJob($job)
    {
        unset($this->lockedJob[$this->getJobUniqId($job)]);
    }
}
