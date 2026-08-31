<?php

use CrondUnitTest\MockRedis;
use PHPUnit\Framework\TestCase;
use Teknasyon\Crond\Locker\RecoverableLocker;
use Teknasyon\Crond\Locker\RedisLocker;

/**
 * Covers the host registry, the dead lock release and the optional lock expiry added in 2.3.
 *
 * The overridden gethostname() answers 'crond.localhost'.
 */
class RedisLockerRecoveryTest extends TestCase
{
    private const JOB = 'recoveryjob';
    private const OWN_HOST = 'crond.localhost';

    /**
     * @var MockRedis
     */
    private $redis;

    /**
     * @var RedisLocker
     */
    private $locker;

    public function setUp(): void
    {
        $this->redis = new MockRedis();
        $this->locker = new RedisLocker($this->redis);
    }

    public function testRedisLockerOffersRecovery()
    {
        $this->assertInstanceOf(RecoverableLocker::class, $this->locker);
    }

    public function testTheFirstHeartbeatReportsAColdRegistryAndTheNextOneAWarmRegistry()
    {
        $this->assertFalse($this->locker->heartbeat(), 'A registry with no marker yet must report itself cold.');
        $this->assertSame('1', $this->redis->get('crondhost-' . self::OWN_HOST));
        $this->assertTrue($this->locker->heartbeat());
    }

    public function testHostLivenessFollowsTheMarker()
    {
        $this->assertFalse($this->locker->isHostAlive('other.host'));

        $this->redis->set('crondhost-other.host', '1');

        $this->assertTrue($this->locker->isHostAlive('other.host'));
    }

    public function testAnEmptyHostnameIsNeverAlive()
    {
        $this->assertFalse($this->locker->isHostAlive('   '));
    }

    public function testAMatchingLockIsReleased()
    {
        $key = $this->locker->getJobUniqId(self::JOB);
        $this->redis->set($key, 'gone.host;123;1;' . self::JOB);

        $this->assertTrue($this->locker->releaseLock(self::JOB, 'gone.host;123;1;' . self::JOB));
        $this->assertNull($this->redis->get($key));
    }

    public function testALockThatChangedSinceTheVerdictIsKept()
    {
        $key = $this->locker->getJobUniqId(self::JOB);
        $this->redis->set($key, 'somebody.else;9;2;' . self::JOB);

        $this->assertFalse($this->locker->releaseLock(self::JOB, 'gone.host;123;1;' . self::JOB));
        $this->assertSame('somebody.else;9;2;' . self::JOB, $this->redis->get($key));
    }

    /**
     * Two hosts can reach the same verdict in the same tick; only the one that claims the recovery may delete.
     */
    public function testOnlyOneHostMayRecoverTheSameLock()
    {
        $key = $this->locker->getJobUniqId(self::JOB);
        $value = 'gone.host;123;1;' . self::JOB;
        $this->redis->set($key, $value);

        $secondHost = new RedisLocker($this->redis);

        $this->assertTrue($this->locker->releaseLock(self::JOB, $value));
        $this->assertFalse(
            $secondHost->releaseLock(self::JOB, $value),
            'A second host must not be able to recover the same lock.'
        );
    }

    public function testLockKeepsUsingSetnxWhenNoExpiryIsConfigured()
    {
        $redis = $this->createMock('\Redis');
        $redis->expects($this->once())->method('setnx')->willReturn(true);
        $redis->expects($this->never())->method('set');

        $locker = new RedisLocker($redis);

        $this->assertTrue($locker->lock(self::JOB));
    }

    public function testAConfiguredExpiryMakesTheLockExpireOnItsOwn()
    {
        $redis = $this->createMock('\Redis');
        $redis->expects($this->never())->method('setnx');
        $redis->expects($this->once())
            ->method('set')
            ->with($this->anything(), $this->anything(), ['nx', 'ex' => 90])
            ->willReturn(true);

        $locker = new RedisLocker($redis, 90);

        $this->assertTrue($locker->lock(self::JOB));
    }

    /**
     * strpos() answers false for a value that does not belong to this job, and substr($value, 0, false) used to
     * turn that into an empty string whose explode() left undefined offsets behind.
     */
    public function testAForeignLockValueParsesIntoNullsInsteadOfRaisingWarnings()
    {
        $this->assertSame(
            ['hostname' => null, 'pid' => null, 'time' => null],
            $this->locker->parseLockValue(self::JOB, 'garbage-without-the-job-suffix')
        );
    }

    public function testRefreshIsANoOpForNeverExpiringLocks()
    {
        $this->locker->lock(self::JOB);

        $this->assertTrue($this->locker->refreshLock(self::JOB));
        $this->assertSame([], $this->redis->expireCalls, 'A lock without a ttl has nothing to refresh.');
    }

    public function testRefreshExtendsOurOwnLock()
    {
        $locker = new RedisLocker($this->redis, 60);
        $locker->lock(self::JOB);

        $this->assertTrue($locker->refreshLock(self::JOB));
        $this->assertSame(
            60,
            $this->redis->expireCalls[$locker->getJobUniqId(self::JOB)],
            'The refresh must re-arm the ttl on the lock key.'
        );
    }

    public function testRefreshIsWallClockRateLimited()
    {
        $locker = new RedisLocker($this->redis, 60);
        $locker->lock(self::JOB);

        $locker->refreshLock(self::JOB);
        $locker->refreshLock(self::JOB);
        $locker->refreshLock(self::JOB);

        $this->assertCount(
            1,
            $this->redis->expireCalls,
            'Back-to-back refresh calls inside the interval must produce a single expire.'
        );
    }

    public function testRefreshNeverExtendsAStrangersLock()
    {
        $locker = new RedisLocker($this->redis, 60);
        $locker->lock(self::JOB);
        // The key meanwhile belongs to someone else (our ttl expired, they locked).
        $this->redis->set($locker->getJobUniqId(self::JOB), 'stranger.host;9;1;' . self::JOB);

        $this->assertFalse($locker->refreshLock(self::JOB));
        $this->assertSame([], $this->redis->expireCalls, "A stranger's lock must never be extended.");
    }

    public function testManualRecoveryIsRefusedWhenLocksExpireOnTheirOwn()
    {
        // With a ttl the store cleans dead locks up by itself, and a manual delete races a fresh owner taking
        // the key between the read and the delete. autoReleaseDeadLocks is for never-expiring locks only.
        $locker = new RedisLocker($this->redis, 60);
        $key = $locker->getJobUniqId(self::JOB);
        $value = 'gone.host;123;1;' . self::JOB;
        $this->redis->set($key, $value);

        $this->assertFalse($locker->releaseLock(self::JOB, $value));
        $this->assertSame($value, $this->redis->get($key), 'The lock must be left for the ttl to clean up.');
    }

    public function testATruncatedLockValueParsesWithoutUndefinedOffsets()
    {
        $this->assertSame(
            ['hostname' => 'onlyhost', 'pid' => null, 'time' => null],
            $this->locker->parseLockValue(self::JOB, 'onlyhost;' . self::JOB)
        );
    }
}
