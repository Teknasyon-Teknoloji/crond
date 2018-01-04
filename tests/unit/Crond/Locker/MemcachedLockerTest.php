<?php

use Teknasyon\Crond\Locker\MemcachedLocker;
use PHPUnit\Framework\TestCase;

class MemcachedLockerTest extends TestCase
{
    /**
     * @var MemcachedLocker
     */
    private $memcachedLocker;

    /**
     * @return PHPUnit_Framework_MockObject_MockObject
     */
    private function setMemcachedMock()
    {
        $memcachedMock = $this->createMock('\Memcached');
        $memcachedMock->method('setOptions')->willReturn(true);
        $memcachedMock->method('quit')->willReturn(true);
        $memcachedMock->method('getServerList')->willReturn(
            [['host'=>'localhost', 'port'=>'1', 'weight'=>10]]
        );
        return $memcachedMock;
    }

    public function setUp()
    {
        $this->memcachedLocker = new MemcachedLocker($this->setMemcachedMock());
    }

    public function testSetJobUniqIdFunctionException()
    {
        $this->expectException('\TypeError');
        $this->memcachedLocker->setJobUniqIdFunction(null);
    }

    public function testSetJobUniqIdFunction()
    {
        $this->memcachedLocker->setJobUniqIdFunction(function($str) {
            return strtoupper($str);
        });
        $this->assertEquals(
            'crondlocker-' . strtoupper('test'),
            $this->memcachedLocker->getJobUniqId('test'),
            'MemcachedLocker::setJobUniqIdFunction failed!'
        );
    }

    public function testGetJobUniqIdException()
    {
        $this->expectException('\InvalidArgumentException');
        $this->memcachedLocker->getJobUniqId(null);
    }

    public function testGetJobUniqId()
    {
        $this->assertEquals(
            'crondlocker-' . md5(strtolower('test')),
            $this->memcachedLocker->getJobUniqId('Test'),
            'MemcachedLocker::getJobUniqId failed!'
        );
    }

    public function testGetLockedJob()
    {
        $this->assertEquals(
            null,
            $this->memcachedLocker->getLockedJob('test'),
            'MemcachedLocker::getLockedJob failed!'
        );
        $this->memcachedLocker->setLockedJob('test', 'value');
        $this->assertEquals(
            ['id' => 'crondlocker-' . md5(strtolower('test')), 'value' => 'value'],
            $this->memcachedLocker->getLockedJob('test'),
            'MemcachedLocker::getLockedJob failed!'
        );
    }

    public function testSetLockedJob()
    {
        $this->memcachedLocker->setLockedJob('test', 'val');
        $this->assertEquals(
            ['id' => 'crondlocker-' . md5(strtolower('test')), 'value' => 'val'],
            $this->memcachedLocker->getLockedJob('test'),
            'MemcachedLocker::setLockedJob failed!'
        );
    }

    public function testGetLockerInfo()
    {
        $this->assertEquals(
            'Memcached ( ' . json_encode([['host'=>'localhost', 'port'=>'1', 'weight'=>10]]) . ' )',
            $this->memcachedLocker->getLockerInfo(),
            'MemcachedLocker::getLockerInfo failed!'
        );
    }

    public function testLockException()
    {
        $this->expectException('\RuntimeException');
        $this->memcachedLocker->lock('');
    }

    public function testLockSuccess()
    {
        $memcachedMock = $this->setMemcachedMock();
        $memcachedMock->method('add')->willReturn(true);
        $memcachedLocker = new MemcachedLocker($memcachedMock);
        $this->assertTrue(
            $memcachedLocker->lock('test'),
            'memcachedLocker::lock success test failed!'
        );
        $this->assertEquals(
            'crondlocker-' . md5(strtolower('test')),
            $memcachedLocker->getLockedJob('test')['id'],
            'memcachedLocker::lock success test failed!'
        );
    }

    public function testLockFailed()
    {
        $memcachedMock = $this->setMemcachedMock();
        $memcachedMock->method('add')->willReturn(false);
        $memcachedLocker = new MemcachedLocker($memcachedMock);
        $this->assertFalse(
            $memcachedLocker->lock('test'),
            'memcachedLocker::lock fail test failed!'
        );
        $this->assertEquals(
            null,
            $memcachedLocker->getLockedJob('test'),
            'memcachedLocker::lock fail test failed!'
        );
    }

    public function testUnlockException1()
    {
        $this->expectException('\RuntimeException');
        $this->memcachedLocker->unlock('test');
    }

    public function testUnlockException2()
    {
        $this->memcachedLocker->setLockedJob('test1', '');
        $this->expectException('\RuntimeException');
        $this->memcachedLocker->unlock('test');
    }

    public function testUnlockSuccess()
    {
        $memcachedMock = $this->setMemcachedMock();
        $memcachedMock->method('delete')->willReturn(true);
        $memcachedLocker = new MemcachedLocker($memcachedMock);
        $memcachedLocker->setLockedJob('test', 'value');
        $this->assertTrue(
            $memcachedLocker->unlock('test'),
            'memcachedLocker::unlock success test failed!'
        );
    }

    public function testDisconnect()
    {
        $this->assertTrue($this->memcachedLocker->disconnect(), 'MemcachedLocker::disconnect failed');
    }
}
