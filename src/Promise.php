<?php
declare (strict_types = 1);
namespace Greendrake\AsyncProcess;
use Ds\Set;
use function React\Async\await;
use Psr\Http\Message\RequestInterface as Request;
use React\Http\Browser;
use React\Http\Server;
use React\Promise as BasePromise;
use React\Socket\ConnectionInterface;
use React\Socket\Server as SocketServer;

class Promise {

    protected BasePromise\Deferred $deferred;
    protected BasePromise\Promise $promise;
    protected ?int $pid = null;

    private static $sigHoldHandlerSetup = false;
    private static $sigHoldDefaultHandler;

    public function __construct(protected string $command) {
        $this->deferred = new BasePromise\Deferred;
        $this->promise = $this->deferred->promise();
        if (!self::$sigHoldHandlerSetup) {
            self::$sigHoldDefaultHandler = pcntl_signal_get_handler(SIGCHLD);
            // When the forked process exits, it sends the parent process SIGCHLD signal.
            // Handling it properly is required to avoid the exited children becoming zombies.
            // (For whatever reason it doesn't actually work for me, so there is a posix_kill call underneath, but trying it anyway).
            pcntl_signal(SIGCHLD, SIG_IGN);
            self::$sigHoldHandlerSetup = true;
        }
        $httpAddress = '127.0.0.1:' . self::findUnusedPort();
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new \RuntimeException('Could not fork process');
        } else if ($pid) {
            // The original, now parent process. Setup an HTTP server and wait for the forked process to post the result to it:
            $this->pid = $pid; // The PID of the forked process, not the parent.
            $result = null;
            $socket = new SocketServer($httpAddress);
            // This is a one-off request/response HTTP server.
            // After the connection is closed, close the socket and pass the response over to the deferred promise.
            $socket->on('connection', function (ConnectionInterface $conn) use ($socket, &$result) {
                $conn->on('close', function () use ($socket, &$result) {
                    $socket->close();
                    // We've made the forked process session leader, we've set SIGCHLD handler to SIG_IGN above,
                    // and yet it may remain a zombie process even after calling exit in itself. Fuck knows why.
                    // Kill the zombie in case it is still walking:
                    posix_kill($this->pid, SIGKILL);
                    // Now that the job is done, cleanup our mess — bring the original handler back:
                    pcntl_signal(SIGCHLD, self::$sigHoldDefaultHandler);
                    // Report the results (whatever they are) as per our promise:
                    if ($result['success']) {
                        $m = $result['code'] === 0 ? 'resolve' : 'reject';
                        $output = implode(PHP_EOL, $result['result']);
                        if ($m === 'reject') {
                            $output = new NonZeroExitException(sprintf('Exit code %s: %s', $result['code'], $output));
                        }
                        $this->deferred->$m($output);
                    } else {
                        $this->deferred->reject($result['error']);
                    }
                });
            });
            // Actually run the one-off HTTP server to wait for what the forked process has to say:
            $server = new Server(function (Request $request) use (&$result) {
                $result = unserialize((string) $request->getBody());
            });
            $server->listen($socket);
        } else {
            // The forked process.
            // Re-instate the default handler (otherwise the reported exit code will be -1, see https://stackoverflow.com/questions/77288724):
            pcntl_signal(SIGCHLD, self::$sigHoldDefaultHandler);
            $browser = new Browser;
            // Define the function that will report results back to the parent:
            $reportBack = function (int $forkExitCode = 0, ?int $jobExitCode = null, ?array $result = null, ?\Throwable $error = null) use ($browser, $httpAddress) {
                await($browser->post('http://' . $httpAddress, [], serialize([
                    'success' => $error === null,
                    'result' => $result,
                    'code' => $jobExitCode,
                    'error' => $error,
                ]))->catch(function () {
                    // Don't give a fuck. This is the forked background process, and if anything is wrong, no one is gonna hear anyway.
                }));
                // This forked process will probably be killed by the parent before it reaches this point,
                // but, just in case, we put an explicit exit here to make sure it does not do anything else:
                exit($forkExitCode);
            };
            if (false === ($pid = getmypid())) {
                // This is very unlikely, but let's handle it.
                $reportBack(
                    forkExitCode : 1,
                    error: new \RuntimeException('Could not get child process PID within itself')
                );
            } else {
                try {
                    $this->pid = $pid;
                    // Make this process the session leader so that it does not depend on the parent anymore:
                    if (posix_setsid() < 0) {
                        $reportBack(
                            forkExitCode: 1,
                            error: new \RuntimeException('Could not make background process ' . $pid . ' session leader')
                        );
                    } else {
                        // Do the actual job however long it takes, suppress STDERR (otherwise it'll make its way to the parent's shell):
                        exec($this->command . ' 2> /dev/null', $output, $resultCode);
                        if ($output === false) {
                            $reportBack(
                                forkExitCode: 1,
                                error: new \RuntimeException(sprintf('Could not run the command "%s" (PID %s)', $this->command, $this->pid))
                            );
                        } else {
                            $reportBack(
                                jobExitCode: $resultCode,
                                result: $output
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $reportBack(
                        forkExitCode: 1,
                        error: $e
                    );
                }
            }
        }
    }

    public function getPid(): int {
        return $this->pid;
    }

    public function get(): BasePromise\PromiseInterface
    {
        return $this->promise;
    }

    private static function findUnusedPort(): int {
        $tried = new Set;
        $add = function (int $port) use ($tried) {
            $tried->add($port);
            return true;
        };
        do {
            $port = mt_rand(1024, 65535);
        } while ($tried->contains($port) || (self::isPortOpen($port) && $add($port)));
        return $port;
    }

    private static function isPortOpen(int $port): bool {
        $result = false;
        try {
            if ($pf = fsockopen('127.0.0.1', $port)) {
                $result = true;
                fclose($pf);
            }
        } catch (\ErrorException $e) {
            if (!str_contains($e->getMessage(), 'Connection refused')) {
                throw $e; // Unexpected exception
            }
        }
        return $result;
    }

}