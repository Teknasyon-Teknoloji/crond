<?php

namespace Teknasyon\Crond;

use Cron\CronExpression;
use Psr\Log\LoggerInterface;
use Teknasyon\Crond\Locker\BaseLocker;
use Teknasyon\Crond\Locker\Locker;

class Worker
{
    /**
     * @var CronJob[]
     */
    private $cronList;

    /**
     * @var BaseLocker
     */
    private $locker;
    private $terminated    = false;
    private $maxIterations = 0;
    private $iterations    = 0;
    private $logger;
    private $cronArgName   = 'run-uniq-cron';

    public function __construct(array $cronConfigList, Locker $locker, $maxIterations = 0)
    {
        foreach ($cronConfigList as $cronId => $cronConfig) {
            $this->cronList[$cronId] = new CronJob(
                $cronId,
                $cronConfig['expression'],
                $cronConfig['cmd'],
                $cronConfig['locktime']
            );
        }
        $this->locker        = $locker;
        $this->maxIterations = $maxIterations;

        $this->handleSignals();
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

    protected function handleSignals()
    {
        if (!function_exists('pcntl_signal')) {
            throw new \Exception('Please make sure that \'pcntl\' is enabled if you want us to handle signals');
        }

        declare(ticks = 1);
        pcntl_signal(SIGTERM, [$this, 'terminate']);
        pcntl_signal(SIGINT,  [$this, 'terminate']);
    }

    public function isDaemon()
    {
        return php_sapi_name()=='cli'
            && strpos(trim(implode(' ', $_SERVER['argv'])), ' --' . $this->cronArgName . '=')===false;
    }

    protected function starting()
    {
        return true;
    }

    protected function finished()
    {
        return true;
    }

    protected function isRunning()
    {
        if ($this->terminated) {
            return false;
        }

        if ($this->maxIterations > 0) {
            return $this->iterations < $this->maxIterations;
        }

        return true;
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

    private function start()
    {
        $this->iterations = 0;
        $this->starting();
        while ($this->isRunning()) {
            ++$this->iterations;
            $this->log('info', $this->iterations . '. iteration started');
            foreach ($this->cronList as $cronJob) {
                if (CronExpression::factory($cronJob->getExpression())->isDue()===false) {
                    continue;
                }
                $this->log('info', $cronJob . ' running...');
                unset($output);unset($retVal);
                if ($cronJob->getLockTime()==0) {
                    @exec($cronJob->getCmd() . ' &> /dev/null &', $output, $retVal);
                } else {
                    @exec($this->getRunCmd($cronJob->getId()) . ' &> /dev/null &', $output, $retVal);
                }

                if ($retVal!==0) {
                    throw new \Exception($cronJob . ' start failed!');
                }
            }
            $this->sleep();
        }
        $this->finished();
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
            $this->start();
        } else {
            $this->exec();
        }
    }
}
