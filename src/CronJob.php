<?php

namespace Teknasyon\Crond;

class CronJob
{
    private $id;
    private $expression;
    private $cmd;
    private $lockTime = 0;

    public function __construct($id, $expression, $cmd, $lockTime = 0)
    {
        $this->id          = $id;
        $this->expression  = $expression;
        $this->cmd         = $cmd;
        $this->lockTime    = $lockTime;
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
     * @return int
     */
    public function getLockTime()
    {
        return $this->lockTime;
    }



    public function __toString()
    {
        return 'CronJob'
            . ($this->lockTime>0?(' with lock time ' . $this->lockTime . ' sec. '):'')
            . ' #' . $this->id
            . '( '. $this->expression.' '. $this->cmd.' )';
    }

}
