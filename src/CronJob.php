<?php

namespace Teknasyon\Crond;

use Cron\CronExpression;

class CronJob
{
    private $id;
    private $expression;
    private $cmd;
    private $isLockRequired = false;

    /**
     * @var int seconds the job may run before it is terminated; 0 means no limit
     */
    private $timeout = 0;

    /**
     * @var string|null per-job output file; null falls back to the daemon-wide one
     */
    private $outputFile;

    public function __construct($id, $expression, $cmd, $isLockRequired = true, $timeout = 0, $outputFile = null)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Cronjob id required!');
        }

        if (CronExpression::isValidExpression($expression) === false) {
            throw new \InvalidArgumentException('Cronjob expression "' . $expression . '" is not valid!');
        }

        if (!is_string($cmd) || trim($cmd) === '') {
            // An empty cmd runs as `sh -c ''`, which exits 0 without doing anything — after taking the lock.
            throw new \InvalidArgumentException('Cronjob cmd required!');
        }

        $this->id = $id;
        $this->expression = $expression;
        $this->cmd = $cmd;
        $this->isLockRequired = $isLockRequired;
        $this->timeout = max(0, (int) $timeout);
        $this->outputFile = (is_string($outputFile) && $outputFile !== '') ? $outputFile : null;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getExpression()
    {
        return $this->expression;
    }

    /**
     * @return mixed
     */
    public function getCmd()
    {
        return $this->cmd;
    }

    /**
     * @return bool
     */
    public function isLockRequired()
    {
        return $this->isLockRequired;
    }

    /**
     * @return int seconds; 0 means no limit
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @return string|null
     */
    public function getOutputFile()
    {
        return $this->outputFile;
    }

    public function __toString()
    {
        return 'CronJob'
            . ' #' . $this->id
            . ($this->isLockRequired ? (' with lock-activated') : '')
            . ' ( ' . $this->expression . ' ' . $this->cmd . ' )';
    }
}
