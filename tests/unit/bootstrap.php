<?php

namespace CrondUnitTest {

    use Psr\Log\LoggerInterface;

    class PHPUnitUtil
    {
        /**
         * @param $obj
         * @param $name
         * @param array $args
         * @return mixed
         * @throws \ReflectionException
         */
        public static function callMethod($obj, $name, array $args)
        {
            $class = new \ReflectionClass($obj);
            $method = $class->getMethod($name);
            $method->setAccessible(true);
            return $method->invokeArgs($obj, $args);
        }
    }

    class MockRedis extends \Redis
    {
        protected $setList = array();

        public function __construct(string $persistent_id = '', $on_new_object_cb = null)
        {

        }

        public function isConnected(): bool
        {
            return true;
        }

        public function set($key, $value, $options = 0): bool|string|\Redis
        {
            if (is_array($options) && in_array('nx', $options) && isset($this->setList[$key])) {
                return false;
            }
            $this->setList[$key] = $value;
            return true;
        }

        public function setnx($key, $value, $expiration = 0): bool|\Redis
        {
            if (isset($this->setList[$key])) {
                return false;
            }
            $this->setList[$key] = $value;
            return true;
        }

        public function add($key, $value, $expiration = 0, $udf_flags = 0)
        {
            $this->setList[$key] = $value;
            return true;
        }

        public function sAdd($key, ...$value1): false|int|\Redis
        {
            if (!isset($this->setList[$key])) {
                $this->setList[$key] = [];
            }
            $this->setList[$key][] = $value1;
            return true;
        }

        public function get($key, ?callable $cache_cb = null, $flags = 0): mixed
        {
            return isset($this->setList[$key]) ? $this->setList[$key] : null;
        }

        public function setOptions($options)
        {
            return true;
        }

        public function quit()
        {
            return true;
        }

        public function getServerList()
        {
            return [['host' => 'localhost', 'port' => '1', 'weight' => 10]];
        }

        public function sMembers($key): false|array|\Redis
        {
            return isset($this->setList[$key]) ? $this->setList[$key] : [];
        }

        public function del(array|string $key, string ...$other_keys): false|int|\Redis
        {
            unset($this->setList[$key]);
            return true;
        }

        public function sRem($key, ...$member1): false|int|\Redis
        {
            $arry = $this->setList[$key];
            $this->setList[$key] = array_diff($arry, [$member1]);
            return true;
        }

        public function eval($script, $args = array(), $num_keys = 0): mixed
        {
            return true;
        }

        /** @var array<string,int> key => last ttl set via expire() */
        public array $expireCalls = [];

        public function expire($key, $ttl, $mode = null): \Redis|bool
        {
            $this->expireCalls[$key] = $ttl;
            return true;
        }
    }

    /**
     * Base for tests that touch the daemon.
     *
     * Daemon reads $_SERVER['argv'] as input — isDaemon() and getCronIdArg() are driven entirely by it — so a test
     * that sets it and walks away decides what every later test class sees. That is not hypothetical: leaving a
     * --run-uniq-cron argument behind makes isDaemon() answer false for everyone after it.
     *
     * Extend this instead of TestCase and the hazard is handled once. Subclasses that define setUp()/tearDown()
     * must call the parent.
     */
    abstract class ArgvIsolatedTestCase extends \PHPUnit\Framework\TestCase
    {
        /**
         * @var array
         */
        private $originalArgv = [];

        protected function setUp(): void
        {
            parent::setUp();
            $this->originalArgv = $_SERVER['argv'];
        }

        protected function tearDown(): void
        {
            $_SERVER['argv'] = $this->originalArgv;
            parent::tearDown();
        }
    }

    /**
     * Daemon with the spawn and clock seams overridden, so scheduler tests stay hermetic: nothing is really
     * spawned and no real 50ms grace is waited. The real spawn path is covered by DaemonExecutionTest with
     * genuine child processes.
     */
    class TestableDaemon extends \Teknasyon\Crond\Daemon
    {
        /** @var array<int, array> argv arrays handed to the spawner */
        public $spawnCalls = [];

        /** @var string 'ok' | 'fail-start' | 'exit-nonzero' */
        public $spawnBehaviour = 'ok';

        /** @var \DateTimeInterface|null fixed clock for isDue evaluation */
        public $fixedNow = null;

        protected function currentTime()
        {
            return $this->fixedNow !== null ? $this->fixedNow : parent::currentTime();
        }

        public function checkDue(\Teknasyon\Crond\CronJob $cronJob, $now)
        {
            return $this->isCronJobDue($cronJob, $now);
        }

        protected function spawnDetachedRunner(array $args, $outputFile)
        {
            $this->spawnCalls[] = $args;

            if ($this->spawnBehaviour === 'fail-start') {
                return false;
            }

            return fopen('php://memory', 'r');
        }

        protected function probeSpawn($handle)
        {
            if ($this->spawnBehaviour === 'exit-nonzero') {
                return ['running' => false, 'exitcode' => 1, 'pid' => 4242];
            }

            return ['running' => true, 'exitcode' => -1, 'pid' => 4242];
        }

        protected function waitBeforeSpawnCheck()
        {
        }
    }

    class MockProcessProbe implements \Teknasyon\Crond\ProcessProbe
    {
        public $usable = true;
        public $runningPids = [];
        public $runningCommands = [];
        public $calls = [];

        public function isUsable()
        {
            $this->calls[] = 'isUsable';
            return $this->usable;
        }

        public function isProcessRunning($pid, $argumentMarker)
        {
            $this->calls[] = 'isProcessRunning:' . $pid;
            return isset($this->runningPids[$pid]) && strpos($this->runningPids[$pid], $argumentMarker) !== false;
        }

        public function isCommandRunning($cmd)
        {
            $this->calls[] = 'isCommandRunning:' . $cmd;
            return in_array($cmd, $this->runningCommands, true);
        }
    }

    /**
     * A locker that knows nothing about hosts, i.e. every Locker implemented outside this package before 2.3.
     */
    class MockPlainLocker extends \Teknasyon\Crond\Locker\BaseLocker
    {
        public $store = [];

        public function getLockerInfo()
        {
            return 'MockPlainLocker';
        }

        public function getLockValue($job)
        {
            return $this->store[$this->getJobUniqId($job)] ?? null;
        }

        public function lock($job)
        {
            if (isset($this->store[$this->getJobUniqId($job)])) {
                return false;
            }
            $this->store[$this->getJobUniqId($job)] = $this->generateLockValue($job);
            return true;
        }

        public function unlock($job)
        {
            unset($this->store[$this->getJobUniqId($job)]);
            return true;
        }

        public function disconnect()
        {
            return true;
        }
    }

    class MockLogger implements LoggerInterface
    {
        public $logLines = [
            'alert' => [],
            'critical' => [],
            'debug' => [],
            'emergency' => [],
            'error' => [],
            'info' => [],
            'log' => [],
            'notice' => [],
            'warning' => [],
        ];

        public function alert($message, array $context = array()): void
        {
            $this->logLines['alert'][] = $message;
        }

        public function critical($message, array $context = array()): void
        {
            $this->logLines['critical'][] = $message;
        }

        public function debug($message, array $context = array()): void
        {
            $this->logLines['debug'][] = $message;
        }

        public function emergency($message, array $context = array()): void
        {
            $this->logLines['emergency'][] = $message;
        }

        public function error($message, array $context = array()): void
        {
            $this->logLines['error'][] = $message;
        }

        public function info($message, array $context = array()): void
        {
            $this->logLines['info'][] = $message;
        }

        public function log($level, $message, array $context = array()): void
        {
            $this->logLines['log'][] = $message;
        }

        public function notice($message, array $context = array()): void
        {
            $this->logLines['notice'][] = $message;
        }

        public function warning($message, array $context = array()): void
        {
            $this->logLines['warning'][] = $message;
        }
    }

    $loader = include(realpath(__DIR__ . '/../../') . '/vendor/autoload.php');
}

namespace Teknasyon\Crond\Locker {
    function gethostname()
    {
        return 'crond.localhost';
    }

    function getmypid()
    {
        return '1';
    }
}

namespace Teknasyon\Crond {

    function gethostname()
    {
        return 'crond.localhost';
    }

    function getmypid()
    {
        return '1';
    }

    function php_sapi_name()
    {
        return 'cli';
    }

    /**
     * Stands in for the process table as well as for command execution.
     *
     * The overridden getmypid() answers '1', so pid 1 is this very process and must be visible: a probe that
     * cannot see itself reports the table as unreadable, and then nothing can be proven dead. Any other pid is
     * treated as gone, which is what the dead-lock scenarios rely on.
     */
    function exec($cmd, &$output = '', &$retval = 0)
    {
        if ($cmd == 'fail-cmd' || strpos($cmd, 'fail.php')) {
            $output = 'cmd not found';
            $retval = 1;
            return '';
        }

        if (strpos($cmd, 'ps -e -o pid=,args=') !== false) {
            $output = ['    1 php crond.php --run-uniq-cron=selftest'];
            $retval = 0;
            return $output[0];
        }

        if (strpos($cmd, 'ps -p ') !== false) {
            if (strpos($cmd, "ps -p '1'") !== false) {
                $retval = 0;
                return 'php crond.php --run-uniq-cron=selftest';
            }
            $retval = 1;
            return '';
        }

        $output = '';
        $retval = 0;
        return '';
    }

}
