<?php

namespace Teknasyon\Crond;

/**
 * Default probe, backed by `ps`.
 *
 * `args` is used rather than the `cmd` keyword earlier versions used: both work on Linux, but the BSD `ps` shipped
 * on macOS rejects `cmd` outright, and a rejected keyword produced empty output — which read as "the process is
 * gone" for every single pid.
 *
 * `-ww` asks for unlimited width. procps-ng writes full lines to a pipe only until a COLUMNS variable is found in
 * the environment; with COLUMNS=80 it cut a 112-column runner line before its `--run-uniq-cron=` marker, and the
 * job's own command line before its end. Both lookups then answered "not running" for a holder that was alive,
 * and the lock was released from under it.
 */
class SystemProcessProbe implements ProcessProbe
{
    /**
     * @return bool
     */
    public function isUsable()
    {
        return $this->commandLineOf(getmypid()) !== '';
    }

    /**
     * @param int|string $pid
     * @param string $argumentMarker
     * @return bool
     */
    public function isProcessRunning($pid, $argumentMarker)
    {
        if (preg_match('/^\d+$/', (string) $pid) !== 1 || (int) $pid <= 0) {
            return false;
        }

        $commandLine = $this->commandLineOf((int) $pid);

        if ($commandLine === '') {
            return false;
        }

        return $argumentMarker === '' || strpos($commandLine, $argumentMarker) !== false;
    }

    /**
     * @param string $cmd
     * @return bool
     */
    public function isCommandRunning($cmd)
    {
        $cmd = trim((string) $cmd);

        if ($cmd === '') {
            return false;
        }

        $lines = array();
        exec('ps -ww -e -o pid=,args= 2>/dev/null', $lines);

        if (!is_array($lines)) {
            return false;
        }

        $ownPid = (int) getmypid();

        foreach ($lines as $line) {
            $line = trim((string) $line);
            $separator = strpos($line, ' ');

            if ($separator === false) {
                continue;
            }

            // The scan runs from a process whose own command line can contain the string being looked for. Earlier
            // versions filtered that with `grep -v grep`, which missed the caller itself; skipping our own pid is
            // both exact and free.
            if ((int) substr($line, 0, $separator) === $ownPid) {
                continue;
            }

            if (strpos(substr($line, $separator + 1), $cmd) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int|string|false $pid
     * @return string
     */
    private function commandLineOf($pid)
    {
        if (preg_match('/^\d+$/', (string) $pid) !== 1) {
            return '';
        }

        // The pid reaches a shell and can originate from a lock value written by another host, so it is validated
        // above and escaped here.
        return trim((string) exec('ps -ww -p ' . escapeshellarg((string) $pid) . ' -o args= 2>/dev/null'));
    }
}
