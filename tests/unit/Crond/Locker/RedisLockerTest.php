<?php

use Teknasyon\Crond\Locker\RedisLocker;
use PHPUnit\Framework\TestCase;
use CrondUnitTest\PHPUnitUtil;
use CrondUnitTest\MockRedis;

class RedisLockerTest extends TestCase
{
    /**
     * @var RedisLocker
     */
    private $redisLocker;

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function setRedisMock()
    {
        $redisMock = $this->createMock('\Redis');
        $redisMock->method('getHost')->willReturn('local');
        $redisMock->method('getPort')->willReturn(123);
        $redisMock->method('getDbNum')->willReturn(1);
        $redisMock->method('close')->willReturn(true);
        return $redisMock;
    }

    public function setUp(): void
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('isConnected')->willReturn(true);
        $this->redisLocker = new RedisLocker($redisMock);
    }

    public function testSetJobUniqIdFunctionException()
    {
        $this->expectException('\TypeError');
        $this->redisLocker->setJobUniqIdFunction(null);
    }

    public function testSetJobUniqIdFunction()
    {
        $this->assertTrue(
            $this->redisLocker->setJobUniqIdFunction(function ($str) {
                return strtoupper($str);
            }),
            'RedisLocker::setJobUniqIdFunction failed!'
        );
    }


    public function testGetLockerInfo()
    {
        $this->assertEquals(
            'RedisLocker',
            $this->redisLocker->getLockerInfo(),
            'RedisLocker::getLockerInfo failed!'
        );
    }

    public function testLockException1()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock(null);
    }

    public function testLockException2()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock('');
    }

    public function testLockException3()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock(false);
    }

    public function testLockException4()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock(true);
    }

    public function testLockException5()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock(array());
    }

    public function testLockException6()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock(array(1, 2));
    }

    public function testLockException7()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->lock(new \stdClass());
    }

    public function testLockSuccess()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('set')->willReturn(true);
        $redisMock->method('setnx')->willReturn(true);
        $redisLocker = new RedisLocker($redisMock);
        $this->assertTrue(
            $redisLocker->lock('test'),
            'RedisLocker::lock success test failed!'
        );
    }

    public function testLockFailed()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('set')->willReturn(false);
        $redisMock->method('setnx')->willReturn(false);

        $redisLocker = new RedisLocker($redisMock);
        $this->assertFalse(
            $redisLocker->lock('test'),
            'RedisLocker::lock fail test failed!'
        );
    }

    public function testLockFailedWithForce()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('set')->willReturn(false);
        $redisMock->method('setnx')->willReturn(false);

        $redisLocker = new RedisLocker($redisMock);
        $this->assertFalse(
            $redisLocker->lock('test', true),
            'RedisLocker::lock fail test failed!'
        );
    }

    public function testUnlockException1()
    {
        $this->expectException('\RuntimeException');
        $this->redisLocker->unlock('test');
    }

    public function testUnlockException2()
    {
        PHPUnitUtil::callMethod($this->redisLocker, 'setLockedJob', ['test1', 'value']);
        $this->expectException('\RuntimeException');
        $this->redisLocker->unlock('test');
    }

    public function testUnlockException3()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('eval')->willReturn(false);
        $redisLocker = new RedisLocker($redisMock);
        PHPUnitUtil::callMethod($redisLocker, 'setLockedJob', ['test', 'value']);
        $this->expectException('\RuntimeException');
        $redisLocker->unlock('test');
    }

    public function testUnlockSuccess()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('get')->willReturn('value');
        $redisLocker = new RedisLocker($redisMock);
        PHPUnitUtil::callMethod($redisLocker, 'setLockedJob', ['test', 'value']);
        $this->assertTrue(
            $redisLocker->unlock('test'),
            'RedisLocker::unlock success test failed!'
        );
    }

    public function testGetLockValue()
    {
        $redisMock = new MockRedis();
        $redisLocker = new RedisLocker($redisMock);
        $redisLocker->lock('test');
        $this->assertEquals(
            $redisMock->get('crondlocker-' . md5('test')),
            $redisLocker->getLockValue('test'),
            'RedisLocker::getLockValue failed!'
        );
    }

    public function testInvalidJobId()
    {
        $redisMock = new MockRedis();
        $redisLocker = new RedisLocker($redisMock);

        $this->expectException('\InvalidArgumentException');
        $redisLocker->getLockValue('');
    }

    public function testParseLockValue()
    {
        $redisMock = new MockRedis();
        $redisLocker = new RedisLocker($redisMock);
        $this->assertEquals(
            [
                'hostname' => '1',
                'pid' => '2',
                'time' => '3',
            ],
            $redisLocker->parseLockValue('test', '1;2;3;test'),
            'RedisLocker::parseLockValue failed!'
        );
    }

    public function testDisconnect()
    {
        $this->assertTrue($this->redisLocker->disconnect(), 'RedisLocker::disconnect failed');
    }
}
