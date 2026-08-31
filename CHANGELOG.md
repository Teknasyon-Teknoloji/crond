# Changelog

## 2.3.0 — unreleased

The theme of this release: crond's failure modes used to be silent. A lock leaked by a killed runner wedged its
job forever while every tick logged a warning-level "lock failed"; a runner that could not even start was logged
as `started` every minute. 2.3.0 makes lock state decidable, makes spawning observable, and stops the library
from leaking locks itself.

### Added

- **`LockVerdict`** — a failed lock attempt now yields a three-valued verdict (`Alive` / `Dead` / `Unknown`)
  with a machine-readable reason, exposed via `Daemon::getLastLockVerdict()`, `getLastLockVerdictReason()`,
  `getLastLockHolder()`, `isLastLockReleased()`. Every `Dead` is backed by positive evidence (process table or
  host registry); everything undecidable is `Unknown` and is never acted upon. Callers should switch on the
  verdict instead of grepping exception messages.
- **Host registry** — each `start()` records a short-lived liveness marker for its own host (`crondhost-*`).
  A lock held by a host without a marker is provably dead — the case the old hostname-based check could never
  see. Cold-registry protection: the first tick after a deploy or flush judges nothing.
- **`Daemon::setAutoReleaseDeadLocks(bool)`** (default **off**) — a lock proven dead is deleted (guarded by a
  recovery claim and a value compare, single-key commands only, cluster-safe) and the job returns on the next
  tick. Refused by `RedisLocker` when `lockTtl > 0`: an expiring lock cleans itself and manual deletion would
  race a fresh owner.
- **`RecoverableLocker`** interface (`heartbeat`, `isHostAlive`, `releaseLock`, `refreshLock`) — opt-in; a plain
  `Locker` keeps working and reports `Unknown` for foreign holders.
- **`ProcessProbe` / `SystemProcessProbe`** — liveness reads on the local process table, injectable via
  `Daemon::setProcessProbe()`. Uses `ps -o args=` (the old `cmd` keyword is rejected by BSD `ps`, which made
  every process look dead on macOS) and validates/escapes pids coming from lock values.
- **Optional `lockTtl`** on `RedisLocker` (default `0` = never expires, the historic behaviour). Since the
  runner refreshes the ttl from its wait loop (`refreshLock`, rate-limited, own-value-only), the ttl means
  "how soon after the holder dies does the lock free itself", not "longer than the slowest job".
- **`Daemon::setTimezone(?string)`** — schedules evaluated in an explicit timezone instead of the process-wide
  default; one clock per tick instead of per job. Spring-forward gaps are compensated by cron-expression; the
  fall-back hour fires twice (inherent cron semantics, documented in the README).
- **Per-job `timeout`** (seconds, default 0 = unlimited): SIGTERM, then SIGKILL after 5 s, reported as
  `run timed out!`. Best effort under the shell. **Per-job `output`** file, appended.
- **Config validation** at construction, naming the broken entry: missing/empty `cmd` (would run `sh -c ''` —
  exit 0, nothing done, after taking the lock), missing/empty/invalid `expression`, non-array entries, and cron
  ids differing only by case (they share one `md5(strtolower(id))` lock key; the second job could never run).
- **`Daemon::getRunArgs()`** — the runner invocation as an argv array; `getRunCmd()` now derives from it.
- Structured logging: lifecycle events (`run started` / `run finished` / `run failed!` / lock verdicts / spawns)
  carry PSR-3 context (`cronId`, `pid`, `retval`, `durationSeconds`, verdict fields) via a new protected
  `logWithContext()`; the old `log($type, $message)` is unchanged for subclasses.

### Changed

- **Spawning uses `proc_open`.** The daemon tick spawns runners from an argv array — no shell, no `&`, no
  escaping hazards, interpreter is `PHP_BINARY` instead of a hardcoded `php` (which a minimal cron `PATH` often
  cannot resolve — and the old backgrounded `exec` reported success anyway). After half a second the tick probes
  each spawn once: dead-on-arrival runners are now logged as `spawn failed!` with their exit code; the old
  `started` wording became `spawned` (with pid). The tick still fires and exits — it never waits for runners.
- **The runner waits in a `stream_select` loop** instead of a blocking `exec`: output is drained continuously
  (an undrained pipe blocks the child once the buffer fills) and only the last 8 KB is kept (a chatty job used
  to be buffered into `memory_limit`, which — see next point — leaked its lock). Stderr is captured into the
  same tail instead of leaking to the runner's stderr.
- **The lock is released in a `finally`.** Any throw, fatal-turned-exception or signal-induced shutdown between
  lock and unlock used to leak the lock permanently — manufacturing exactly the dead locks the verdict machinery
  diagnoses. The exit code is evaluated after the release, so an unlock problem can no longer mask `run failed!`.
- **`RedisLocker::unlock()` returns `bool` instead of throwing on benign states.** Key gone (ttl expiry,
  recovery elsewhere): `false`, no exception — it used to throw and thereby hide the job's own result. Key held
  by someone else: `false`, key untouched — it used to answer `true` without deleting, a silent lie. Calling
  `unlock()` without a preceding `lock()` still throws (caller bug). Comparisons are strict now.
- `BaseLocker`'s in-process map is keyed by the same derivation as the storage key — `lock('MyJob')` +
  `unlock('myjob')` used to throw and leak the key.
- `parseLockValue()` returns `['hostname' => null, 'pid' => null, 'time' => null]` for foreign/corrupted values
  instead of raising undefined-offset warnings.
- Exception messages: the `Dead` marker ` ( Deadlock found! )` is preserved **byte for byte** for callers that
  grep for it; `Unknown` appends ` ( Lock state unknown: <reason> )`; a recovered lock appends
  ` ( Dead lock released! )`. `lock failed!`, `run failed!` and `Cron-id argument not found!` prefixes are
  unchanged. The `lockValue:` shown for lock-free jobs now reads the correct (minute-scoped) key.
- Output files (daemon-wide and per-job) are opened in **append** mode; same-minute jobs no longer truncate each
  other.
- An empty cron config logs a warning instead of silently iterating nothing.

### Removed

- Dead code: the write-only `Daemon::$uniqId`.
- `composer.json`: `ext-redis` moved from `require` to `require-dev` + `suggest` — only `RedisLocker` needs it
  and the Locker interfaces are storage-agnostic. **Check that your application declares `ext-redis` itself if
  it uses `RedisLocker`** (every known in-house consumer already does).

### Notes for upgrading consumers

- Applications that grep the exception message for `Deadlock found` keep working, but should migrate to
  `getLastLockVerdict()` — the string check cannot distinguish `Unknown` from `Alive`.
- Subclasses overriding `protected log($type, $message)` keep compiling; messages logged through the new
  `logWithContext()` bypass such an override.
- `getRunCmd()` output changed format (escaped, `PHP_BINARY`-prefixed); it was previously broken for any path or
  argument containing a space.
