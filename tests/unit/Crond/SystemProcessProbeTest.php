<?php

/**
 * Drives the real probe against the real `ps`, in a child process so the bootstrap's exec() override stays out
 * of the way.
 *
 * The scenario is a production incident: the runner holding a lock was alive, but `ps` — honouring a COLUMNS=80
 * in the environment — cut its command line before the `--run-uniq-cron=` marker, and cut the job's command line
 * before its end. Both lookups answered "not running", the probe still saw itself, and the daemon released a
 * lock whose holder had hours of work left.
 */
class SystemProcessProbeTest extends \PHPUnit\Framework\TestCase
{
    /** Long enough that the marker starts past column 80 even on a short repository path. */
    private const MARKER = ' --run-uniq-cron=AJobWhoseNameTogetherWithThePathPushesTheMarkerPastEightyColumns';

    /**
     * @var resource|null
     */
    private $sleeper;

    /**
     * @var array
     */
    private $sleeperArgs = [];

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The default probe is backed by ps.');
        }

        $this->sleeperArgs = [PHP_BINARY, __DIR__ . '/../fixtures/sleeper.php', ltrim(self::MARKER)];
        $this->sleeper = proc_open($this->sleeperArgs, [0 => ['file', '/dev/null', 'r']], $pipes);
        $this->assertIsResource($this->sleeper, 'The sleeper fixture could not be started.');
        // Give the interpreter time to come up; ps answers for the exec'd image either way, but a probe that
        // ran before the fork settled would prove nothing.
        usleep(200000);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->sleeper)) {
            proc_terminate($this->sleeper, 9);
            proc_close($this->sleeper);
        }
    }

    public function testTheProbeSeesItself()
    {
        $this->assertSame('1', $this->probe(['usable']));
    }

    public function testAHolderIsSeenEvenWhenColumnsWouldTruncateItsCommandLine()
    {
        $pid = (string) proc_get_status($this->sleeper)['pid'];
        $this->assertGreaterThan(80, strlen(implode(' ', $this->sleeperArgs)));

        $this->assertSame('1', $this->probe(['process', $pid, self::MARKER], ['COLUMNS' => '80']));
    }

    public function testAHoldersCommandIsSeenEvenWhenColumnsWouldTruncateIt()
    {
        $cmd = implode(' ', $this->sleeperArgs);
        $this->assertGreaterThan(80, strlen($cmd));

        $this->assertSame('1', $this->probe(['command', $cmd], ['COLUMNS' => '80']));
    }

    public function testARecycledPidRunningSomethingElseIsNotTheHolder()
    {
        $pid = (string) proc_get_status($this->sleeper)['pid'];

        $this->assertSame('0', $this->probe(['process', $pid, ' --run-uniq-cron=someOtherJob']));
    }

    /**
     * @param array $args
     * @param array $env
     * @return string
     */
    private function probe(array $args, array $env = [])
    {
        $process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/../fixtures/probe-runner.php'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + getenv()
        );
        $this->assertIsResource($process, 'The probe runner could not be started.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $this->assertSame(0, $exit, 'probe-runner failed: ' . $stderr);

        return trim($stdout);
    }
}
