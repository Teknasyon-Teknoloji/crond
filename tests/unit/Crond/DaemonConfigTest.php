<?php

use CrondUnitTest\ArgvIsolatedTestCase;
use CrondUnitTest\MockLogger;
use CrondUnitTest\MockRedis;
use CrondUnitTest\TestableDaemon;
use Teknasyon\Crond\CronJob;
use Teknasyon\Crond\Daemon;
use Teknasyon\Crond\Locker\RedisLocker;

/**
 * A broken config entry must be rejected loudly at construction, naming the entry — not discovered weeks later
 * as a job that silently never ran or a lock that silently never released.
 */
class DaemonConfigTest extends ArgvIsolatedTestCase
{
    /**
     * @var RedisLocker
     */
    private $locker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locker = new RedisLocker(new MockRedis());
    }

    public function testAMissingCmdIsRejectedWithTheCronId()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron #brokenjob cmd is missing or empty!');
        new Daemon(['brokenjob' => ['expression' => '* * * * *']], $this->locker);
    }

    public function testAnEmptyCmdIsRejected()
    {
        // `sh -c ''` exits 0 without doing anything, so this validation is the only guard against a job that
        // "succeeds" every night while doing nothing.
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron #brokenjob cmd is missing or empty!');
        new Daemon(['brokenjob' => ['cmd' => '   ', 'expression' => '* * * * *']], $this->locker);
    }

    public function testAMissingExpressionIsRejectedWithTheCronId()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron #brokenjob expression is missing or empty!');
        new Daemon(['brokenjob' => ['cmd' => 'date']], $this->locker);
    }

    public function testAnInvalidExpressionNamesTheBrokenEntry()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron #brokenjob: Cronjob expression "* *" is not valid!');
        new Daemon(['brokenjob' => ['cmd' => 'date', 'expression' => '* *']], $this->locker);
    }

    public function testANonArrayConfigEntryIsRejected()
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron #brokenjob config must be an array!');
        new Daemon(['brokenjob' => '* * * * *'], $this->locker);
    }

    public function testCronIdsDifferingOnlyByCaseAreRejected()
    {
        // Lock keys are md5(strtolower(id)): these two jobs would share one lock and the second could never run.
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Cron #MYJOB collides with #myjob: cron ids are case-insensitive for locking!');
        new Daemon(
            [
                'myjob' => ['cmd' => 'date', 'expression' => '* * * * *'],
                'MYJOB' => ['cmd' => 'date', 'expression' => '* * * * *'],
            ],
            $this->locker
        );
    }

    public function testAnEmptyConfigIsAnnouncedInsteadOfSilentlyDoingNothing()
    {
        $_SERVER['argv'] = ['crond.php'];
        $logger = new MockLogger();
        $daemon = new TestableDaemon([], $this->locker);
        $daemon->setLogger($logger);

        $daemon->start();

        $this->assertStringContainsString('no cron jobs configured', $logger->logLines['warning'][0]);
    }

    public function testTimeoutAndOutputTravelFromConfigToTheJob()
    {
        $_SERVER['argv'] = ['crond.php'];
        $daemon = new TestableDaemon(
            ['job' => ['cmd' => 'date', 'expression' => '0 0 1 1 *', 'timeout' => 90, 'output' => '/tmp/job.log']],
            $this->locker
        );

        $_SERVER['argv'] = ['job', '--run-uniq-cron=job'];
        try {
            $daemon->start();
        } catch (\Throwable $e) {
            // The job itself may run and fail here; only the config plumbing is under test.
        }

        $job = $daemon->getLastRunnedCronJob();
        $this->assertSame(90, $job->getTimeout());
        $this->assertSame('/tmp/job.log', $job->getOutputFile());
    }

    public function testAnInvalidTimezoneIsRejected()
    {
        $daemon = new Daemon(['job' => ['cmd' => 'date', 'expression' => '* * * * *']], $this->locker);

        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage('Timezone "Mars/OlympusMons" is not valid!');
        $daemon->setTimezone('Mars/OlympusMons');
    }

    public function testTheTimezoneActuallyReachesTheScheduleEvaluation()
    {
        $daemon = new TestableDaemon(['job' => ['cmd' => 'date', 'expression' => '30 2 * * *']], $this->locker);
        $job = new CronJob('job', '30 2 * * *', 'date');
        // January on purpose: no DST anywhere near, so the only variable is the timezone plumbing itself.
        $now = new \DateTime('2026-01-15 02:30:00', new \DateTimeZone('UTC'));

        $daemon->setTimezone('UTC');
        $this->assertTrue($daemon->checkDue($job, $now), 'Due at 02:30 UTC when evaluated in UTC');

        $daemon->setTimezone('Europe/Berlin');
        $this->assertFalse($daemon->checkDue($job, $now), '02:30 UTC is 03:30 in Berlin — not due there');
    }

    /**
     * DST nights in Europe/Berlin, measured against the real cron-expression library — Europe/Istanbul would
     * prove nothing here, it has had no DST since 2016.
     *
     * Spring forward 2026-03-29 (02:00 -> 03:00): the 02:30 wall-clock minute never occurs, and the library
     * compensates by firing once at the first instant after the gap (03:30 CEST) — the daily job is NOT lost.
     * Fall back 2026-10-25 (03:00 -> 02:00): 02:30 occurs twice, an hour apart, and the job fires BOTH times;
     * a daily job in a DST timezone must be idempotent, or be scheduled in UTC.
     */
    public function testDstNightsFireOnceOnSpringForwardAndTwiceOnFallBack()
    {
        $daemon = new TestableDaemon(['job' => ['cmd' => 'date', 'expression' => '30 2 * * *']], $this->locker);
        $daemon->setTimezone('Europe/Berlin');
        $job = new CronJob('job', '30 2 * * *', 'date');

        $this->assertSame(
            1,
            $this->dueCountBetween($daemon, $job, '2026-03-28 23:00:00', 5 * 60),
            'The spring-forward gap is compensated: the job fires once, at the first instant after the jump'
        );
        $this->assertSame(
            1,
            $this->dueCountBetween($daemon, $job, '2026-03-26 23:00:00', 5 * 60),
            'A normal night fires exactly once'
        );
        $this->assertSame(
            2,
            $this->dueCountBetween($daemon, $job, '2026-10-24 22:00:00', 6 * 60),
            'The repeated fall-back hour fires the job twice — inherent cron semantics, documented on purpose'
        );
    }

    /**
     * @param TestableDaemon $daemon
     * @param CronJob $job
     * @param string $utcStart
     * @param int $minutes
     * @return int
     */
    private function dueCountBetween(TestableDaemon $daemon, CronJob $job, $utcStart, $minutes)
    {
        $due = 0;
        $cursor = new \DateTimeImmutable($utcStart, new \DateTimeZone('UTC'));
        for ($i = 0; $i < $minutes; $i++) {
            if ($daemon->checkDue($job, $cursor)) {
                $due++;
            }
            $cursor = $cursor->modify('+1 minute');
        }

        return $due;
    }
}
