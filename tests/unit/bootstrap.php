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

        public function isConnected()
        {
            return true;
        }

        public function set($key, $value, $expiration = 0)
        {
            if (is_array($expiration) && in_array('nx', $expiration) && isset($this->setList[$key])) {
                return false;
            }
            $this->setList[$key] = $value;
            return true;
        }

        public function setnx($key, $value, $expiration = 0)
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

        public function sAdd($key, ...$value1)
        {
            if (!isset($this->setList[$key])) {
                $this->setList[$key] = [];
            }
            $this->setList[$key][] = $value1;
            return true;
        }

        public function get($key, callable $cache_cb = null, $flags = 0)
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

        public function sMembers($key)
        {
            return isset($this->setList[$key]) ? $this->setList[$key] : [];
        }

        public function del($key1, ...$otherKeys)
        {
            unset($this->setList[$key1]);
            return true;
        }

        public function sRem($key, ...$member1)
        {
            $arry = $this->setList[$key];
            $this->setList[$key] = array_diff($arry, [$member1]);
            return true;
        }

        public function eval($script, $args = array(), $numKeys = 0)
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

    function exec($cmd, &$output = '', &$retval = 0)
    {
        if ($cmd == 'fail-cmd' || strpos($cmd, 'fail.php')) {
            $output = 'cmd not found';
            $retval = 1;
            return '';
        } elseif (strpos($cmd, 'ps -e') !== false && strpos($cmd, 'dead-lock-cmd') !== false) {
            $output = '';
            $retval = 0;
            return 0;
        } else {
            $output = '';
            $retval = 0;
            return '';
        }
    }

}
