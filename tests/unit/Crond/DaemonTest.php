<?php

use PHPUnit\Framework\TestCase;
use Teknasyon\Crond\Daemon;
use Teknasyon\Crond\Locker\MemcachedLocker;

class DaemonTest extends TestCase
{
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

    public function testIsValidCronJob()
    {
        $this->markTestIncomplete(
            'This test has not been implemented yet.'
        );

        $memcachedMock = $this->setMemcachedMock();
        $memcachedMock->method('add')->willReturn(false);
        $memcachedLocker = new MemcachedLocker($memcachedMock);
        $daemon = new Daemon(['test' => ['cmd' => 'date', 'expression' => '0 * * * *']], $memcachedLocker);
    }
}
