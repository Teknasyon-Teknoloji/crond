<?php

namespace Teknasyon\Crond;

use Cron\CronExpression;

class CronJob
{
    private $id;
    private $expression;
    private $cmd;
    private $isLockRequired = false;

    public function __construct($id, $expression, $cmd, $isLockRequired = true)
    {
        if (!$id) {
            throw new \InvalidArgumentException('Cronjob id required!');
        }

        if (CronExpression::isValidExpression($expression)===false) {
            throw new \InvalidArgumentException('Cronjob expression is not valid!');
        }
        $this->id             = $id;
        $this->expression     = $expression;
        $this->cmd            = $cmd;
        $this->isLockRequired = $isLockRequired;
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

    public function __toString()
    {
        return 'CronJob'
            . ' #' . $this->id
            . ($this->isLockRequired?(' with lock-activated '):'')
            . ' ( '. $this->expression.' '. $this->cmd.' )';
    }

}
