<?php

namespace Teknasyon\Crond;

use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Teknasyon\Crond\Locker\BaseLocker;
use Teknasyon\Crond\Locker\Locker;

class Daemon
{
    private $uniqId;

    /**
     * @var CronJob[]
     */
    private $cronList;

    /**
     * @var BaseLocker
     */
    private $locker;
    private $logger;
    private $cronArgName = 'run-uniq-cron';
    /**
     * @var CronJob
     */
    private $lastRunnedCronJob;

    public function __construct(array $cronConfigList, Locker $locker)
    {
        $this->uniqId = md5(gethostname());
        foreach ($cronConfigList as $cronId => $cronConfig) {
            $this->cronList[$cronId] = new CronJob(
                $cronId,
                $cronConfig['expression'],
                $cronConfig['cmd'],
                (isset($cronConfig['lock']) && $cronConfig['lock']==false)?false:true
            );
        }
        $this->locker = $locker;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function log($type, $message)
    {
        if ($this->logger) {
            $this->logger->$type($message);
        }
    }

    public function isDaemon()
    {
        return php_sapi_name()=='cli'
            && strpos(trim(implode(' ', $_SERVER['argv'])), ' --' . $this->cronArgName . '=')===false;
    }

    public function getRunCmd($cronId)
    {
        $argv    = $_SERVER['argv'];
        $selfPhp = array_shift($argv);
        if (substr($selfPhp, 0, 1)!=DIRECTORY_SEPARATOR) {
            $selfPhp = getcwd() . DIRECTORY_SEPARATOR . $selfPhp;
        }
        if (count($argv)>0) {
            $args = ' ' . implode(' ', $argv);
        } else {
            $args = '';
        }
        return 'php ' . $selfPhp . $args . ' --' . $this->cronArgName . '=' . $cronId;
    }

    private function crond()
    {
        $this->log('info', 'Crond started');
        foreach ($this->cronList as $cronJob) {
            if (CronExpression::factory($cronJob->getExpression())->isDue()===false) {
                continue;
            }
            unset($output);unset($retVal);
            @exec($this->getRunCmd($cronJob->getId()) . ' &> /dev/null &', $output, $retVal);
            if ($retVal!==0) {
                $this->log('error', $cronJob . ' failed!');
            } else {
                $this->log('info', $cronJob . ' started');
            }
        }
    }

    public function getCronIdArg()
    {
        $cronId = null;
        foreach ($_SERVER['argv'] as $arg) {
            if (strpos(trim($arg), '--' . $this->cronArgName . '=')===0) {
                $cronId = explode('--' . $this->cronArgName . '=', $arg);
                $cronId = trim($cronId[1]);
                break;
            }
        }
        return $cronId;
    }

    /**
     * @return CronJob
     */
    public function getLastRunnedCronJob()
    {
        return $this->lastRunnedCronJob;
    }

    private function runJob()
    {
        $cronId = $this->getCronIdArg();
        if (!$cronId || isset($this->cronList[$cronId])===false) {
            throw new \InvalidArgumentException(
                'Cron-id argument not found! ARGV: ' . json_encode($_SERVER['argv'])
            );
        }
        $this->lastRunnedCronJob = $this->cronList[$cronId];

        $lockId = $cronId . ($this->lastRunnedCronJob->isLockRequired()===false?date('YmdHi'):'');
        if ($this->locker->lock($lockId)===false) {
            throw new \RuntimeException(
                'Cron #' . $cronId . ' lock failed! LockId: ' . $lockId
            );
        }
        @exec($this->lastRunnedCronJob->getCmd(), $output, $retval);
        $this->locker->unlock($lockId);
        if ($retval!==0) {
            throw new \RuntimeException(
                'Cron #' . $cronId . ' run failed!'
                . ' LockId: ' . $lockId . ', Retval: ' . $retval.', Output: ' . json_encode($output)
            );
        }
    }

    public function start()
    {
        if ($this->isDaemon()) {
            $this->crond();
        } else {
            $this->runJob();
        }
    }
}
