<?php

use PHPUnit\Framework\TestCase;
use Teknasyon\Crond\Daemon;
use Teknasyon\Crond\Locker\RedisLocker;

class DaemonTest extends TestCase
{
    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function setRedisMock()
    {
        $redisMock = $this->createMock('\Redis');
        $redisMock->method('getHost')->willReturn('local');
        $redisMock->method('getPort')->willReturn('123');
        $redisMock->method('getDbNum')->willReturn('1');
        $redisMock->method('close')->willReturn(true);
        return $redisMock;
    }

    public function testIsValidCronJob()
    {
        $this->assertInstanceOf(
            'Teknasyon\Crond\Daemon',
            $daemon = new Daemon(
                ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
                new RedisLocker($this->setRedisMock())
            ));
    }

    public function testInValidCronJobId1()
    {
        $this->expectException('\InvalidArgumentException');
        $daemon = new Daemon(
            ['test?üĞ' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
    }

    public function testInValidCronJobId2()
    {
        $this->expectException('\InvalidArgumentException');
        $daemon = new Daemon(
            ['' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
    }

    public function testInValidCronJobId3()
    {
        $this->expectException('\InvalidArgumentException');
        $daemon = new Daemon(
            [null => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
    }

    public function testInValidCronExpression()
    {
        $this->expectException('\InvalidArgumentException');
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * *  *']],
            new RedisLocker($this->setRedisMock())
        );
    }

    public function testSetLoggerException()
    {
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
        $this->expectException('\TypeError');
        $daemon->setLogger(new \stdClass());
    }

    public function testSetLogger()
    {
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
        $this->assertTrue($daemon->setLogger(new \CrondUnitTest\MockLogger()));
    }

    public function testIsDaemon()
    {
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
        $this->assertTrue($daemon->isDaemon());

    }

    public function testNotIsDaemon()
    {
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
        $_SERVER['argv'] = ['test', '--run-uniq-cron='];
        $this->assertFalse($daemon->isDaemon());
    }

    public function testGetRunCmd()
    {
        $prefix = getcwd() . DIRECTORY_SEPARATOR;

        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );

        $_SERVER['argv'] = [
            'crond.php',
            '-e=stage',
            'test',
            '--config=xml',
            '-d',
            '-f 1'
        ];
        $this->assertEquals(
            'php ' . $prefix . implode(' ', $_SERVER['argv']) . ' --run-uniq-cron=testId',
            $daemon->getRunCmd('testId'),
            'Daemon::getRunCmd failed!'
        );

        $_SERVER['argv'] = [
            '/tmp/crond.php',
            '-e=stage',
            'test',
            '--config=xml',
            '-d',
            '-f 1'
        ];
        $this->assertEquals(
            'php ' . implode(' ', $_SERVER['argv']) . ' --run-uniq-cron=testId',
            $daemon->getRunCmd('testId'),
            'Daemon::getRunCmd failed!'
        );
    }

    public function testGetCronIdArg()
    {
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker($this->setRedisMock())
        );
        $_SERVER['argv'] = ['test', '--run-uniq-cron=abc'];
        $this->assertEquals('abc', $daemon->getCronIdArg());

        $_SERVER['argv'] = ['test', '--foobar'];
        $this->assertNull($daemon->getCronIdArg());
    }

    public function testGetLastRunnedCronJob()
    {
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            new RedisLocker(new \CrondUnitTest\MockRedis())
        );
        $this->assertNull($daemon->getLastRunnedCronJob());

        $_SERVER['argv'] = ['test', '--run-uniq-cron=test'];
        $daemon->start();
        $this->assertEquals(
            new \Teknasyon\Crond\CronJob('test', '0 * * * *', 'date'),
            $daemon->getLastRunnedCronJob()
        );
    }

    public function testEchoCronJobs()
    {
        $locker = new RedisLocker(new \CrondUnitTest\MockRedis());

        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            $locker
        );

        $_SERVER['argv'] = ['--show-crons'];
        $daemon->start();
        $this->expectOutputString(
            'Cron Jobs : ' . PHP_EOL
            . 'Cron #test : ' . PHP_EOL
            . '0 * * * * date (LOCK REQUIRED)' . PHP_EOL
        );
    }

    public function testEchoCronJobs2()
    {
        $locker = new RedisLocker(new \CrondUnitTest\MockRedis());

        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => '0 * * * *']],
            $locker
        );

        $_SERVER['argv'] = ['--list-crons'];
        $daemon->start();
        $this->expectOutputString(
            'Cron Jobs : ' . PHP_EOL
            . 'Cron #test : ' . PHP_EOL
            . '0 * * * * date (LOCK REQUIRED)' . PHP_EOL
        );
    }

    public function testCrondNotDue()
    {
        $redis = new \CrondUnitTest\MockRedis();
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = [];
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => (intval(date('i')) + 2) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $daemon->start();

        $this->assertEquals('Crond started', $logger->logLines['info'][0], 'Crond log line failed');

    }

    public function testCrondDue()
    {
        $redis = new \CrondUnitTest\MockRedis();
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = [];
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => (date('i')) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $daemon->start();

        $this->assertEquals(
            'CronJob #test with lock-activated ( ' . (date('i')) . ' * * * * date ) started',
            $logger->logLines['info'][1],
            'Crond log line failed'
        );
    }

    public function testCrondDueFailed()
    {
        $redis = new \CrondUnitTest\MockRedis();
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = ['fail.php'];
        $daemon = new Daemon(
            ['test' => ['cmd' => 'fail-cmd', 'expression' => (date('i')) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $daemon->start();

        $this->assertEquals(
            'CronJob #test with lock-activated ( ' . (date('i')) . ' * * * * fail-cmd ) failed!',
            $logger->logLines['error'][0],
            'Crond log line failed'
        );
    }

    public function testCrondNoDeadLock()
    {
        $redis = new \CrondUnitTest\MockRedis();
        $redis->set('crondlocker-' . md5('test'), 'crond.localhost;1;' . microtime(true) . ';test');
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = ['test', '--run-uniq-cron=test'];
        $daemon = new Daemon(
            ['test' => ['cmd' => 'date', 'expression' => (date('i')) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $this->expectException('\RuntimeException');
        $daemon->start();
    }

    public function testNonExistCronId()
    {
        $redis = new \CrondUnitTest\MockRedis();
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = ['nonexistsjob', '--run-uniq-cron=nonexistsjob'];
        $daemon = new Daemon(
            ['testjob' => ['cmd' => 'testjob-cmd', 'expression' => (date('i')) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron-id argument not found! ARGV: ["nonexistsjob","--run-uniq-cron=nonexistsjob"]');
        $daemon->start();
    }

    public function testFailedCronCmd()
    {
        $redis = new \CrondUnitTest\MockRedis();
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = ['nonexistsjob', '--run-uniq-cron=nonexistsjob'];
        $daemon = new Daemon(
            ['testjob' => ['cmd' => 'testjob-cmd', 'expression' => (date('i')) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron-id argument not found! ARGV: ["nonexistsjob","--run-uniq-cron=nonexistsjob"]');
        $daemon->start();
    }

    public function testCrondDeadLock()
    {
        $lockKey = 'crondlocker-' . md5('deadlockjob');
        $lockValue = 'crond.localhost;123;' . microtime(true) . ';deadlockjob';
        $redis = new \CrondUnitTest\MockRedis();
        $redis->set($lockKey, $lockValue);
        $locker = new RedisLocker($redis);
        $logger = new \CrondUnitTest\MockLogger();

        $_SERVER['argv'] = ['deadlockjob', '--run-uniq-cron=deadlockjob'];
        $daemon = new Daemon(
            ['deadlockjob' => ['cmd' => 'dead-lock-cmd', 'expression' => (date('i')) . ' * * * *']],
            $locker
        );
        $daemon->setLogger($logger);

        $this->expectException('\RuntimeException');
        $this->expectExceptionMessage('Cron #deadlockjob lock failed! jobName: deadlockjob ( Deadlock found! ) LockId: ' . $lockKey);
        $daemon->start();
    }

}
