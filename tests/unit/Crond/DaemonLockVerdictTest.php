<?php

use CrondUnitTest\ArgvIsolatedTestCase;
use Teknasyon\Crond\Daemon;
use Teknasyon\Crond\LockVerdict;
use Teknasyon\Crond\Locker\RedisLocker;

/**
 * Covers what a failed lock attempt is judged to mean.
 *
 * The overridden gethostname() answers 'crond.localhost', so any other hostname in a lock value belongs to a
 * foreign host, and getmypid() answers '1'.
 */
class DaemonLockVerdictTest extends ArgvIsolatedTestCase
{
    private const JOB = 'lockjob';
    private const OWN_HOST = 'crond.localhost';
    private const FOREIGN_HOST = 'other.host';

    /**
     * @var \CrondUnitTest\MockRedis
     */
    private $redis;

    /**
     * @var \CrondUnitTest\MockProcessProbe
     */
    private $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new \CrondUnitTest\MockRedis();
        $this->probe = new \CrondUnitTest\MockProcessProbe();
        $_SERVER['argv'] = [self::JOB, '--run-uniq-cron=' . self::JOB];
    }

    public function testAHolderRunningOnThisHostIsAlive()
    {
        $this->probe->runningPids['123'] = 'php crond.php --run-uniq-cron=' . self::JOB;

        $daemon = $this->runWithLock(self::OWN_HOST, '123');

        $this->assertSame(LockVerdict::Alive, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOLDER_PROCESS_RUNNING, $daemon->getLastLockVerdictReason());
    }

    public function testAHolderWhoseCommandIsStillWorkingIsAlive()
    {
        $this->probe->runningCommands[] = 'lock-cmd';

        $daemon = $this->runWithLock(self::OWN_HOST, '123');

        $this->assertSame(LockVerdict::Alive, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOLDER_COMMAND_RUNNING, $daemon->getLastLockVerdictReason());
    }

    public function testAHolderGoneFromThisHostIsDead()
    {
        $daemon = $this->runWithLock(self::OWN_HOST, '123');

        $this->assertSame(LockVerdict::Dead, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOLDER_PROCESS_GONE, $daemon->getLastLockVerdictReason());
        $this->assertSame(
            ['hostname' => self::OWN_HOST, 'pid' => '123', 'time' => $daemon->getLastLockHolder()['time']],
            $daemon->getLastLockHolder()
        );
    }

    public function testAnUnreadableProcessTableIsNeverProofThatAHolderIsGone()
    {
        $this->probe->usable = false;

        $daemon = $this->runWithLock(self::OWN_HOST, '123');

        $this->assertSame(LockVerdict::Unknown, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_PROCESS_PROBE_UNAVAILABLE, $daemon->getLastLockVerdictReason());
    }

    public function testAHolderOnAHostThatStillReportsInIsAlive()
    {
        $this->warmRegistry();
        $this->redis->set('crondhost-' . self::FOREIGN_HOST, '1');

        $daemon = $this->runWithLock(self::FOREIGN_HOST, '123');

        $this->assertSame(LockVerdict::Alive, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOLDER_HOST_ALIVE, $daemon->getLastLockVerdictReason());
    }

    /**
     * The case earlier versions could not see: the holder's machine is gone, so nobody will ever release the lock.
     */
    public function testAHolderOnAVanishedHostIsDead()
    {
        $this->warmRegistry();

        $daemon = $this->runWithLock(self::FOREIGN_HOST, '123');

        $this->assertSame(LockVerdict::Dead, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOLDER_HOST_GONE, $daemon->getLastLockVerdictReason());
    }

    public function testAColdRegistryNeverConcludesThatAForeignHostIsGone()
    {
        $daemon = $this->runWithLock(self::FOREIGN_HOST, '123');

        $this->assertSame(LockVerdict::Unknown, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOST_REGISTRY_COLD, $daemon->getLastLockVerdictReason());
    }

    public function testALockerWithoutAHostRegistryCannotJudgeAForeignHolder()
    {
        $locker = new \CrondUnitTest\MockPlainLocker();
        $locker->store[$locker->getJobUniqId(self::JOB)] = self::FOREIGN_HOST . ';123;' . microtime(true) . ';' . self::JOB;

        $daemon = $this->daemon($locker);
        $this->startExpectingLockFailure($daemon);

        $this->assertSame(LockVerdict::Unknown, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_HOST_REGISTRY_UNSUPPORTED, $daemon->getLastLockVerdictReason());
    }

    public function testACorruptedLockValueIsUndecidable()
    {
        $locker = new RedisLocker($this->redis);
        $this->redis->set($locker->getJobUniqId(self::JOB), 'garbage-without-the-job-suffix');

        $daemon = $this->daemon($locker);
        $this->startExpectingLockFailure($daemon);

        $this->assertSame(LockVerdict::Unknown, $daemon->getLastLockVerdict());
        $this->assertSame(LockVerdict::REASON_LOCK_VALUE_INVALID, $daemon->getLastLockVerdictReason());
        $this->assertNull($daemon->getLastLockHolder());
    }

    public function testTheDeadlockMarkerIsKeptForCallersThatGrepForIt()
    {
        $this->warmRegistry();

        $daemon = $this->daemon(new RedisLocker($this->redis));
        $this->seedLock(self::FOREIGN_HOST, '123');

        try {
            $daemon->start();
            $this->fail('The daemon was expected to fail on the held lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(' ( Deadlock found! )', $e->getMessage());
        }
    }

    public function testAnUndecidableLockSaysSoInTheMessage()
    {
        $daemon = $this->daemon(new RedisLocker($this->redis));
        $this->seedLock(self::FOREIGN_HOST, '123');

        try {
            $daemon->start();
            $this->fail('The daemon was expected to fail on the held lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                ' ( Lock state unknown: ' . LockVerdict::REASON_HOST_REGISTRY_COLD . ' )',
                $e->getMessage()
            );
        }
    }

    public function testADeadLockIsKeptUntilRecoveryIsTurnedOn()
    {
        $this->warmRegistry();
        $locker = new RedisLocker($this->redis);
        $key = $locker->getJobUniqId(self::JOB);

        $daemon = $this->daemon($locker);
        $this->seedLock(self::FOREIGN_HOST, '123');
        $this->startExpectingLockFailure($daemon);

        $this->assertSame(LockVerdict::Dead, $daemon->getLastLockVerdict());
        $this->assertFalse($daemon->isLastLockReleased());
        $this->assertNotNull($this->redis->get($key), 'The lock was released without being asked to.');
    }

    public function testARecoveredDeadLockLetsTheJobRunAgain()
    {
        $this->warmRegistry();
        $locker = new RedisLocker($this->redis);
        $key = $locker->getJobUniqId(self::JOB);

        $daemon = $this->daemon($locker);
        $daemon->setAutoReleaseDeadLocks(true);
        $this->seedLock(self::FOREIGN_HOST, '123');

        try {
            $daemon->start();
            $this->fail('The daemon was expected to fail on the held lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(' ( Dead lock released! )', $e->getMessage());
        }

        $this->assertTrue($daemon->isLastLockReleased());
        $this->assertNull($this->redis->get($key), 'The dead lock was not released.');
        $this->assertTrue($locker->lock(self::JOB), 'The job cannot take the lock again after recovery.');
    }

    public function testALiveLockIsNeverReleasedEvenWithRecoveryTurnedOn()
    {
        $this->warmRegistry();
        $this->redis->set('crondhost-' . self::FOREIGN_HOST, '1');
        $locker = new RedisLocker($this->redis);
        $key = $locker->getJobUniqId(self::JOB);

        $daemon = $this->daemon($locker);
        $daemon->setAutoReleaseDeadLocks(true);
        $this->seedLock(self::FOREIGN_HOST, '123');
        $this->startExpectingLockFailure($daemon);

        $this->assertSame(LockVerdict::Alive, $daemon->getLastLockVerdict());
        $this->assertFalse($daemon->isLastLockReleased());
        $this->assertNotNull($this->redis->get($key), 'A lock held by a live host was released.');
    }

    /**
     * @return Daemon
     */
    private function runWithLock($hostname, $pid)
    {
        $daemon = $this->daemon(new RedisLocker($this->redis));
        $this->seedLock($hostname, $pid);
        $this->startExpectingLockFailure($daemon);

        return $daemon;
    }

    private function seedLock($hostname, $pid)
    {
        $locker = new RedisLocker($this->redis);
        $this->redis->set(
            $locker->getJobUniqId(self::JOB),
            $hostname . ';' . $pid . ';' . microtime(true) . ';' . self::JOB
        );
    }

    /**
     * Puts a marker for this host in place, so the registry can be trusted to answer for other hosts.
     */
    private function warmRegistry()
    {
        $this->redis->set('crondhost-' . self::OWN_HOST, '1');
    }

    /**
     * @return Daemon
     */
    private function daemon($locker)
    {
        $daemon = new Daemon(
            [self::JOB => ['cmd' => 'lock-cmd', 'expression' => '* * * * *', 'lock' => 1]],
            $locker
        );
        $daemon->setProcessProbe($this->probe);
        $daemon->setLogger(new \CrondUnitTest\MockLogger());

        return $daemon;
    }

    private function startExpectingLockFailure(Daemon $daemon)
    {
        try {
            $daemon->start();
            $this->fail('The daemon was expected to fail on the held lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('lock failed', $e->getMessage());
        }
    }
}
