<?php

use Teknasyon\Crond\Locker\RedisLocker;
use PHPUnit\Framework\TestCase;

class RedisLockerTest extends TestCase
{
    /**
     * @var RedisLocker
     */
    private $redisLocker;

    /**
     * @return PHPUnit_Framework_MockObject_MockObject
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

    public function setUp()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('isConnected')->willReturn(true);
        $this->redisLocker = new RedisLocker($redisMock);
    }

    public function testConstructionException()
    {
        $this->expectException('\RuntimeException');
        $redisMock = $this->setRedisMock();
        $redisMock->method('isConnected')->willReturn(false);
        new RedisLocker($redisMock);
    }

    public function testSetJobUniqIdFunctionException()
    {
        $this->expectException('\TypeError');
        $this->redisLocker->setJobUniqIdFunction(null);
    }

    public function testSetJobUniqIdFunction()
    {
        $this->redisLocker->setJobUniqIdFunction(function($str) {
            return strtoupper($str);
        });
        $this->assertEquals(
            'crondlocker-' . strtoupper('test'),
            $this->redisLocker->getJobUniqId('test'),
            'MemcachedLocker::setJobUniqIdFunction failed!'
        );
    }

    public function testGetJobUniqIdException()
    {
        $this->expectException('\InvalidArgumentException');
        $this->redisLocker->getJobUniqId(null);
    }

    public function testGetJobUniqId()
    {
        $this->assertEquals(
            'crondlocker-' . md5(strtolower('test')),
            $this->redisLocker->getJobUniqId('Test'),
            'RedisLocker::getJobUniqId failed!'
        );
    }

    public function testGetLockedJobId()
    {
        $this->assertEquals(
            null,
            $this->redisLocker->getLockedJob(null),
            'RedisLocker::getLockedJobId failed!'
        );
        $this->redisLocker->setLockedJob('abc', 'val');
        $this->assertEquals(
        ['id' => 'crondlocker-' . md5(strtolower('abc')), 'value' => 'val'],
            $this->redisLocker->getLockedJob('abc'),
            'RedisLocker::getLockedJobId failed!'
        );
    }

    public function testSetLockedJobId()
    {
        $this->redisLocker->setLockedJob('def', 'val');
        $this->assertEquals(
            ['id' => 'crondlocker-' . md5(strtolower('def')), 'value' => 'val'],
            $this->redisLocker->getLockedJob('def'),
            'RedisLocker::setLockedJob failed!'
        );
    }

    public function testGetLockerInfo()
    {
        $this->assertEquals(
            'Redis ( local:123 -> 1 )',
            $this->redisLocker->getLockerInfo(),
            'RedisLocker::getLockerInfo failed!'
        );
    }

    public function testLockException()
    {
        $this->expectException('\RuntimeException');
        $this->redisLocker->lock('');
    }

    public function testLockSuccess()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('set')->willReturn(true);
        $redisLocker = new RedisLocker($redisMock);
        $this->assertTrue(
            $redisLocker->lock('test'),
            'RedisLocker::lock success test failed!'
        );
        $this->assertEquals(
            'crondlocker-' . md5(strtolower('test')),
            $redisLocker->getLockedJob('test')['id'],
            'RedisLocker::lock success test failed!'
        );
    }

    public function testLockFailed()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('set')->willReturn(false);

        $redisLocker = new RedisLocker($redisMock);
        $this->assertFalse(
            $redisLocker->lock('test'),
            'RedisLocker::lock fail test failed!'
        );
        $this->assertEquals(
            null,
            $redisLocker->getLockedJob('test'),
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
        $this->redisLocker->setLockedJob('test1', 'value');
        $this->expectException('\RuntimeException');
        $this->redisLocker->unlock('test');
    }

    public function testUnlockSuccess()
    {
        $redisMock = $this->setRedisMock();
        $redisMock->method('eval')->willReturn(true);
        $redisLocker = new RedisLocker($redisMock);
        $redisLocker->setLockedJob('test', 'value');
        $this->assertTrue(
            $redisLocker->unlock('test'),
            'RedisLocker::unlock success test failed!'
        );
        $this->assertNull(
            $redisLocker->getLockedJob('test')['id'],
            'RedisLocker::unlock success test failed!'
        );
    }

    public function testDisconnect()
    {
        $this->assertTrue($this->redisLocker->disconnect(), 'RedisLocker::disconnect failed');
    }
}
