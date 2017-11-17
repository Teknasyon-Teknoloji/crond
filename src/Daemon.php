<?php

namespace Teknasyon\Crond;

use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Teknasyon\Crond\Locker\BaseLocker;
use Teknasyon\Crond\Locker\Locker;

class Daemon
{
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

    public function __construct(array $cronConfigList, Locker $locker)
    {
        foreach ($cronConfigList as $cronId => $cronConfig) {
            $this->cronList[$cronId] = new CronJob(
                $cronId,
                $cronConfig['expression'],
                $cronConfig['cmd'],
                $cronConfig['locktime']
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

    protected function isValidCronJob(CronJob $cronJob)
    {
         return CronExpression::factory($cronJob->getExpression())->isDue();
    }

    protected function sleep()
    {
        $secToNextMinute = strtotime('next minute') - time();
        if ($secToNextMinute>2) {
            sleep(($secToNextMinute-2));
        } else {
            usleep(200000);
        }
    }

    private function getRunCmd($cronId)
    {
        $prefix = '';
        if (substr($_SERVER['argv'][0], 0, 1)!=DIRECTORY_SEPARATOR) {
            $prefix = getcwd() . DIRECTORY_SEPARATOR;
        }
        return 'php ' . $prefix . $_SERVER['argv'][0] . ' --' . $this->cronArgName . '=' . $cronId;
    }

    private function crond()
    {
        $this->log('info', 'Crond started');
        foreach ($this->cronList as $cronJob) {
            if (CronExpression::factory($cronJob->getExpression())->isDue()===false) {
                continue;
            }
            $this->log('info', $cronJob . ' is running...');
            unset($output);unset($retVal);
            if ($cronJob->getLockTime()==0) {
                @exec($cronJob->getCmd() . ' &> /dev/null &', $output, $retVal);
            } else {
                @exec($this->getRunCmd($cronJob->getId()) . ' &> /dev/null &', $output, $retVal);
            }

            if ($retVal!==0) {
                $this->log('error', $cronJob . ' failed!');
            }
        }
    }

    private function getCronIdArg()
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

    private function exec()
    {
        $cronId = $this->getCronIdArg();
        if (!$cronId || isset($this->cronList[$cronId])===false) {
            throw new \InvalidArgumentException(
                'Cron-id argument not found! ARGV: ' . json_encode($_SERVER['argv'])
            );
        }
        $cronJob  = $this->cronList[$cronId];
        if ($this->locker->lock($cronJob->getId(), $cronJob->getLockTime())===false) {
            throw new \RuntimeException(
                'Cron #' . $cronJob->getId() . ' lock failed!'
            );
        }

        @exec($cronJob->getCmd(), $output, $retval);
        if ($cronJob->getLockTime()==-1) {
            $this->locker->unlock($cronJob->getId());
        }
        if ($retval!==0) {
            throw new \RuntimeException(
                'Cron #' . $cronJob->getId() . ' run failed!'
                . ' Retval: ' . $retval.', Output: ' . json_encode($output)
            );
        }
    }

    public function run()
    {
        if ($this->isDaemon()) {
            $this->crond();
        } else {
            $this->exec();
        }
    }
}
