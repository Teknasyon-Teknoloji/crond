<?php

use PHPUnit\Framework\TestCase;
use Teknasyon\Crond\CronJob;

class CronJobTest extends TestCase
{
    public function testInvalidIdException()
    {
        $this->expectException('\InvalidArgumentException');
        new CronJob(null, '', '');
    }

    public function testInvalidIdException2()
    {
        $this->expectException('\InvalidArgumentException');
        new CronJob('', '', '');
    }

    public function testInvalidIdException3()
    {
        $this->expectException('\InvalidArgumentException');
        new CronJob(false, '', '');
    }

    public function testInvalidExpressionException()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cronjob expression is not valid!');
        new CronJob('test', '* * ', '');
    }

    public function testInvalidExpressionException2()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cronjob expression is not valid!');
        new CronJob('test', null, '');
    }

    public function testInvalidExpressionException3()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cronjob expression is not valid!');
        new CronJob('test', '*/10 * * * * * *', '');
    }

    public function testGetId()
    {
        $cronJob = new CronJob('test', '*/10 * * * *', 'date');
        $this->assertEquals('test', $cronJob->getId(), 'CronJob::getId failed!');
    }

    public function testGetExpression()
    {
        $cronJob = new CronJob('test', '0 * * * *', 'date');
        $this->assertEquals('0 * * * *', $cronJob->getExpression(), 'CronJob::getExpression failed!');
    }

    public function testGetCmd()
    {
        $cronJob = new CronJob('test', '*/10 * * * *', 'date');
        $this->assertEquals('date', $cronJob->getCmd(), 'CronJob::getCmd failed!');

        $cronJob = new CronJob('test', '*/10 * * * *', 'date "test" -f test2');
        $this->assertEquals('date "test" -f test2', $cronJob->getCmd(), 'CronJob::getCmd failed!');
    }

    public function testIsLockRequired()
    {
        $cronJob = new CronJob('test', '0,10,20,35,45 * * * *', 'date');
        $this->assertTrue($cronJob->isLockRequired(), 'CronJob::isLockRequired failed!');

        $cronJob = new CronJob('test', '0,10,20,35,45 * * * *', 'date', false);
        $this->assertFalse($cronJob->isLockRequired(), 'CronJob::isLockRequired failed!');
    }

    public function testToString()
    {
        $cronJob = new CronJob('test', '0 * * * *', 'date');
        $this->assertEquals(
            'CronJob with lock required  #test ( 0 * * * * date )',
            '' . $cronJob,
            'CronJob::__toString failed!'
        );

        $cronJob = new CronJob('test2', '0/10 * * * *', 'date -f Ymd', false);
        $this->assertEquals(
            'CronJob #test2 ( 0/10 * * * * date -f Ymd )',
            '' . $cronJob,
            'CronJob::__toString failed!'
        );
    }
}
