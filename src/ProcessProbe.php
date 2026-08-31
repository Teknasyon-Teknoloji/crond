<?php

namespace Teknasyon\Crond;

/**
 * Reads the local process table.
 *
 * Implement this to run the daemon somewhere `ps` is not the right tool, or to make liveness deterministic in a
 * test. Every method must answer only about the machine it runs on.
 */
interface ProcessProbe
{
    /**
     * Whether the process table can be read at all.
     *
     * A negative answer from the other two methods is treated as proof that a holder is gone, so a probe that
     * cannot see anything must say so here instead of silently reporting everything as not running.
     *
     * @return bool
     */
    public function isUsable();

    /**
     * @param int|string $pid
     * @param string $argumentMarker substring the process command line must contain, guarding against recycled pids
     * @return bool
     */
    public function isProcessRunning($pid, $argumentMarker);

    /**
     * @param string $cmd
     * @return bool
     */
    public function isCommandRunning($cmd);
}
