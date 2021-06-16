# wilon-server

Mock swoole server(tcp/udp) style for programming.

## Example

```php
$server = new Wilon\Server('0.0.0.0', 8080);

// configuration
$server->set(['worker_num' => 4]);

// events
$server->on('WorkerStart', function(){});
$server->on('connect', function(){});
$server->on('close', function(){});
$server->on('receive', function(){});

// start server
$server->start();

```
