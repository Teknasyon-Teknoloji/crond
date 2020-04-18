<?php

namespace Teknasyon\Crond\Locker;

interface Locker
{
    public function getLockerInfo();

    public function getLockValue($job);

    public function lock($job);

    public function unlock($job);

    public function disconnect();

    public function parseLockValue($job, $value);
}
