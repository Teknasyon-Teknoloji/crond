# crond
Distributed Cron Daemon with PHP

## Requirements
* PHP 8.2+
* ext-json
* dragonmantank/cron-expression
* psr/log
* ext-redis — only when using the bundled `RedisLocker`

## Usage

- Set your cron config:
``` php
$crons = [
    'my_cron_id1' => [
        'expression' => '* * * * *',
        'cmd' => '/usr/bin/php /pathto/myproject/mycron.php',
        'lock' => 0 // No need lock
    ],
    'my_cron_id2' => [
        'expression' => '*/10 * * * *',
        'cmd' => '/usr/bin/php /pathto/myproject/minutecron.php',
        'lock' => 1
    ],
    'my_cron_id3' => [
        'expression' => '* * * * *',
        'cmd' => '/usr/bin/php /pathto/myproject/infinitecron.php', // Like lock:1
        'timeout' => 900,                 // optional: seconds before the job is terminated; 0 = unlimited
        'output' => '/var/log/mycron.log' // optional: per-job output file (appended, rotate it yourself)
    ]
]
```
Config entries are validated at construction: a missing or empty `cmd` or `expression`, a non-array entry, and
two ids differing only by letter case (they would share one lock key) are all rejected with an exception naming
the broken entry.

- Create your Locker: use the bundled `\Teknasyon\Crond\Locker\RedisLocker`, or implement
  `\Teknasyon\Crond\Locker\Locker` yourself.
- Create `\Teknasyon\Crond\Daemon` with the cron config and the Locker:

``` php
<?php

use Teknasyon\Crond\Locker\RedisLocker;
use Teknasyon\Crond\Daemon;

$locker = new RedisLocker($redisClient);

try {
    $crond = new Daemon($cronConfig, $locker);
    $crond->setLogger($myPsrLoggerInterfacedObj);
    $crond->setTimezone('UTC'); // recommended; see "Timezone" below
    $crond->start();
} catch (\Exception $e) {
    // Error handling
}
```

## How jobs are spawned and watched (2.3+)

The per-minute daemon tick spawns one detached runner per due job with `proc_open` — no shell is involved, so
paths and arguments containing spaces or metacharacters survive intact, and the interpreter is `PHP_BINARY`
instead of whatever `php` the (often minimal) cron `PATH` happens to resolve. The tick then waits half a second
and probes each spawn once: a runner that could not even start (missing interpreter, unreadable script) dies
within milliseconds with a non-zero exit code, and this probe is the only place that failure can be observed —
before 2.3 such a tick cheerfully logged `started` every minute while nothing ever ran.

The runner executes the job `cmd` through the shell (unchanged), drains its stdout/stderr continuously (an
undrained pipe blocks the child as soon as the buffer fills), keeps only the last 8 KB of output, and releases
the lock in a `finally` — a crash, an OOM kill or a SIGTERM between lock and unlock can no longer leak the lock
forever. The exit code is evaluated after the unlock, so an unlock hiccup cannot mask a failing job either.

All lifecycle events are logged with PSR-3 context (`cronId`, `pid`, `retval`, `durationSeconds`, lock verdict
fields), so a log aggregator can query them without regexing message strings.

## Timezone

``` php
$crond->setTimezone('UTC');
```

Without it, schedules are evaluated in the process-wide default timezone — a global, mutable value the host
application may change at any point. With a DST timezone plumbed in, the underlying cron-expression library
compensates the spring-forward gap (a daily `30 2 * * *` fires once, at the first instant after the jump), but
the repeated fall-back hour fires **twice**, one hour apart — that is inherent cron semantics. Jobs scheduled
inside a DST transition window must be idempotent, or simply use UTC.

## Lock diagnosis and recovery (2.3+)

The Redis lock is taken with `SETNX` and released when the runner finishes. A runner killed in between — a deploy,
an instance replacement, an OOM kill — leaves the key behind, and from then on every tick fails to take the lock
and the job never runs again.

Before 2.3 the daemon could not report this. Its deadlock check gave up as soon as the lock's hostname differed
from the current one, which is exactly what happens once the holding machine is gone, so the failure looked the
same as a job that is merely still running.

### Reading the verdict

After a failed lock attempt the daemon tells you which of three things it established:

``` php
use Teknasyon\Crond\LockVerdict;

try {
    $crond->start();
} catch (\RuntimeException $e) {
    switch ($crond->getLastLockVerdict()) {
        case LockVerdict::Alive:    // someone is really running it; wait, however long it takes
        case LockVerdict::Dead:     // the holder is gone and will never release the lock
        case LockVerdict::Unknown:  // not decidable — report it, never act on it
    }

    $crond->getLastLockVerdictReason(); // one of the LockVerdict::REASON_* constants
    $crond->getLastLockHolder();        // ['hostname' => ..., 'pid' => ..., 'time' => ...]
}
```

The verdict never rests on how long a lock has been held, so a job that legitimately runs far longer than its own
schedule is never mistaken for a stuck one.

`Dead` still appends `( Deadlock found! )` to the exception message, unchanged, for callers that grep for it.
`Unknown` appends `( Lock state unknown: <reason> )`.

### Host registry

A holder on another machine cannot be judged with `ps`. Every `start()` records a short-lived marker for its own
host, and a lock whose holder has no marker is known to be gone. A runner executing a long job refreshes the
marker from inside its wait loop about once a minute, so stopping the per-minute tick on a host does not make its
still-running jobs look dead.

`RedisLocker` provides this out of the box. A locker of your own opts in by implementing
`Teknasyon\Crond\Locker\RecoverableLocker`; one that does not keeps working exactly as before and simply reports
`Unknown` for foreign holders.

A registry that has not yet seen its own host answers `Unknown` rather than declaring other hosts gone, so the
first tick after a deploy or a flushed store never releases a lock a running sibling still holds.

### Recovering a dead lock

Off by default, because a lock disappearing is visible to everything else sharing the store:

``` php
$crond->setAutoReleaseDeadLocks(true);
```

With it on, a lock proven dead is deleted and the job runs again on the next tick. The release only removes the
key while it still holds the value the verdict was based on, and only one host may recover a given lock, so a lock
taken in the meantime is never thrown away. Only single-key commands are used, so this works the same on `\Redis`
and on `\RedisCluster`.

**Auto-release is for never-expiring locks only.** With `lockTtl > 0` the store cleans dead locks up on its own,
and `RedisLocker` refuses manual recovery in that configuration — deleting by hand would race a fresh owner
taking the key.

## Expiring locks and the lease

Independently of the above, a lock can be given a lifetime. `0`, the default, keeps the historic behaviour of
locks that never expire:

``` php
$locker = new RedisLocker($redisClient, lockTtl: 120, hostHeartbeatTtl: 180);
```

Since 2.3 the runner **refreshes the ttl from inside its wait loop** (at most every `lockTtl / 3` seconds, and
only while the key still holds its own value), so the ttl no longer has to outlast the slowest job. Its meaning
changed accordingly: `lockTtl` is *how soon after the holding process dies the lock frees itself*. A short value
like 120 is fine for jobs of any duration — as long as Redis stays reachable.

That is the trade-off to understand before enabling it: the lease is only as reliable as your Redis. A few
consecutive refresh failures let the lock expire under a **still-running** job, and a second copy starts. For
very long jobs (hours to days) prefer `lockTtl = 0` and clean dead locks via the verdict machinery and
`setAutoReleaseDeadLocks(true)` instead. If you do enable a ttl, keep `lockTtl >= 5 ×` the refresh interval
implied above so transient Redis hiccups are tolerated.

## Per-job timeout

``` php
'my_cron' => ['expression' => '* * * * *', 'cmd' => '...', 'timeout' => 900]
```

`0` (default) means unlimited — the historic behaviour. A job past its timeout gets SIGTERM, then SIGKILL after a
5-second grace, and the run is reported as `run timed out!`. Best effort: the command runs through the shell, so
a compound command's grandchildren may survive the kill. Jobs with wildly different runtimes should get their own
values; there is deliberately no global timeout.

## Process probe

Liveness on the local machine is read from `ps`. Replace it where that is not the right tool:

``` php
$crond->setProcessProbe(new MyProcessProbe()); // Teknasyon\Crond\ProcessProbe
```

A probe that cannot read the process table must say so through `isUsable()`. "Nothing is running" and "I cannot
see anything" must never look alike — the first one releases locks.

## Output files

The daemon-wide `$outputFile` (constructor, default `/dev/null`) and the per-job `output` config key are opened in
**append** mode since 2.3 — two jobs due in the same minute no longer truncate each other's output. Nothing
rotates these files; if you point them at real paths, add them to logrotate.
