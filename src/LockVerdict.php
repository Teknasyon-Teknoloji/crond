<?php

namespace Teknasyon\Crond;

/**
 * The answer to "why could this job not take its lock?".
 *
 * Before 2.3 this was a boolean, which forced "I cannot tell" to be encoded as "not a deadlock". That is what made
 * a lock left behind by a replaced host look healthy forever: the holder's hostname no longer matched any running
 * host, so the check bailed out and reported no deadlock on every later run.
 */
enum LockVerdict: string
{
    /** Someone is really running this job right now. Waiting is correct, however long it takes. */
    case Alive = 'alive';

    /** The holder is provably gone. The lock will never be released by it and can be recovered. */
    case Dead = 'dead';

    /** Not decidable with the evidence at hand. Never act on this — report it. */
    case Unknown = 'unknown';

    public const REASON_HOLDER_IS_SELF = 'holder_is_self';
    public const REASON_HOLDER_PROCESS_RUNNING = 'holder_process_running';
    public const REASON_HOLDER_COMMAND_RUNNING = 'holder_command_running';
    public const REASON_HOLDER_HOST_ALIVE = 'holder_host_alive';
    public const REASON_LOCK_NOT_REQUIRED = 'lock_not_required';

    public const REASON_HOLDER_PROCESS_GONE = 'holder_process_gone';
    public const REASON_HOLDER_HOST_GONE = 'holder_host_gone';

    public const REASON_LOCK_VALUE_MISSING = 'lock_value_missing';
    public const REASON_LOCK_VALUE_INVALID = 'lock_value_invalid';
    public const REASON_PROCESS_PROBE_UNAVAILABLE = 'process_probe_unavailable';
    public const REASON_HOST_REGISTRY_COLD = 'host_registry_cold';
    public const REASON_HOST_REGISTRY_UNSUPPORTED = 'host_registry_unsupported';

    /**
     * Text appended to the "lock failed" exception message.
     *
     * The Dead wording is kept byte-for-byte from earlier versions: callers grep for it.
     */
    public function messageMarker(string $reason): string
    {
        return match ($this) {
            self::Dead => ' ( Deadlock found! )',
            self::Unknown => ' ( Lock state unknown: ' . $reason . ' )',
            self::Alive => '',
        };
    }
}
