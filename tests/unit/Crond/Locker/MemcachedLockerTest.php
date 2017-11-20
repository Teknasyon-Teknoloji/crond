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

    public function testGetLockedJobId()
    {
        $this->assertEquals(
            null,
            $this->memcachedLocker->getLockedJobId(),
            'MemcachedLocker::getLockedJobId failed!'
        );
        $this->memcachedLocker->setLockedJobId('abc');
        $this->assertEquals(
            'abc',
            $this->memcachedLocker->getLockedJobId(),
            'MemcachedLocker::getLockedJobId failed!'
        );
    }

    public function testSetLockedJobId()
    {
        $this->memcachedLocker->setLockedJobId('def');
        $this->assertEquals(
            'def',
            $this->memcachedLocker->getLockedJobId(),
            'MemcachedLocker::getLockedJobId failed!'
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
            $memcachedLocker->getLockedJobId(),
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
            $memcachedLocker->getLockedJobId(),
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
        $this->memcachedLocker->setLockedJobId('test1');
        $this->expectException('\RuntimeException');
        $this->memcachedLocker->unlock('test');
    }

    public function testUnlockSuccess()
    {
        $memcachedMock = $this->setMemcachedMock();
        $memcachedMock->method('delete')->willReturn(true);
        $memcachedLocker = new MemcachedLocker($memcachedMock);
        $memcachedLocker->setLockedJobId($memcachedLocker->getJobUniqId('test'));
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
