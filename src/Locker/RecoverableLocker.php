<?php

namespace Teknasyon\Crond\Locker;

/**
 * Optional capability a Locker can offer so the daemon can tell a dead lock from a busy one.
 *
 * Kept separate from the Locker interface on purpose: adding methods there would break every locker implemented
 * outside this package. A locker that does not implement this still works exactly as before, it just cannot help
 * the daemon decide anything about a lock held by another host.
 */
interface RecoverableLocker
{
    /**
     * Records that this host is alive right now.
     *
     * @return bool whether a marker for this host already existed, i.e. whether the registry can be trusted to
     *              answer isHostAlive() for other hosts yet. A freshly deployed or flushed registry knows nothing
     *              about anybody, and a missing marker must not be read as "that host is gone".
     */
    public function heartbeat();

    /**
     * @param string $hostname
     * @return bool
     */
    public function isHostAlive($hostname);

    /**
     * Removes a lock, but only while it still holds the exact value the caller based its verdict on.
     *
     * @param string $job
     * @param string $expectedValue
     * @return bool whether this call is the one that removed it
     */
    public function releaseLock($job, $expectedValue);

    /**
     * Extends the lifetime of a lock this process took earlier, so a lock with an expiry can survive a job that
     * legitimately runs longer than the expiry. Must extend only a key that still holds this process's own value
     * — never a stranger's — and may rate-limit itself. A no-op (returning true) when the lock has no expiry.
     *
     * @param string $job
     * @return bool whether the lock is still ours
     */
    public function refreshLock($job);
}
