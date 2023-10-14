# php-asyncprocess
[ReactPHP Promise](https://reactphp.org/promise/) implementation for truly asynchronous background processes.

This library allows to run commands in background shaped as ReactPHP Promises. Non-blocking.
Tested on Linux only.

Under the hood, it works this way:

1. A child process is forked using [pcntl_fork](https://www.php.net/manual/en/function.pcntl-fork.php). This runs the specified command and reports the result back to the parent process via a local HTTP call (using a one-off [reactphp/http](https://github.com/reactphp/http) server/client).

2. Once the parent process gets the result, it fulfils (or rejects, depending on the exit code) the Promise. Profit.

Example:

```php
use function React\Async\await;
$p = new \Greendrake\AsyncProcess\Promise('a=$( expr 10 - 3 ); echo $a'); // Kick off the process in background.
$result = await($p->get()); // Get the instance of React\Promise\Promise, wait for it to resolve.
echo $result; // outputs "7"
```
