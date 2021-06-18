<?php

declare(ticks=1);

namespace Wilon;

use RuntimeException;

class Server
{
    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;

    /**
     * @var array
     */
    private $callbacks;

    /**
     * @var array
     */
    private $config = ['worker_num' => 1];

    /**
     * @var array
     */
    private $clients;
    /**
     * @var Process
     */
    private $master;

    /**
     * @var array
     */
    private $socks;
    /**
     * @var bool
     */
    private $running = true;

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
        $this->buildProcess();
    }

    public function buildProcess(): void
    {
        // worker process
        $this->master = new Process(function () {
            $pm = new Manager();
            if ( $this->config['worker_num'] ) {
                $pm->addBatch($this->config['worker_num'], function ($workerId) {
                    if (isset($this->callbacks['WorkerStart'])) {
                        call_user_func($this->callbacks['WorkerStart'], $this, $workerId);
                    }
                    $sock = WILON_RUNTIME_PATH.'/'.$workerId.'.sock';
                    if ( file_exists($sock) ) {
                        unlink($sock);
                    }
                    // socket bind listen
                    $server = stream_socket_server("unix://$sock", $errno, $errstr);
                    if (! $server ) {
                        Log::error("stream_socket_server error", $errno, $errstr);
                        throw new RuntimeException("stream_socket_server error");
                    }

                    for (;;) {
                        $conn = @stream_socket_accept($server, -1);
                        while ($data = stream_get_line($conn, 1024, "\n")) {
                            [$event, $peer, $payload] = explode('|', $data);
                            $this->socks[$peer] = $conn;
                            switch ($event) {
                                case 'connect':
                                    if (isset($this->callbacks['connect'])) {
                                        call_user_func($this->callbacks['connect'], $this, $peer);
                                    }
                                    break;
                                case 'close':
                                    if (isset($this->callbacks['close'])) {
                                        call_user_func($this->callbacks['close'], $this, $peer);
                                    }
                                    break;
                                case 'receive':
                                    if (isset($this->callbacks['receive'])) {
                                        call_user_func($this->callbacks['receive'], $this, $peer, $payload);
                                    }
                                    break;
                                default:
                                    Log::error('event', $event, 'not support');
                            }
                        }
                    }
                });
            }
            $pm->start();
        });
    }

    public function sendEvent($client, string $event, string $peer, string $data = '')
    {
        $payload = implode('|', [$event, $peer, $data]);
        return fwrite($client, $payload."\n");
    }

    public function on($event, callable $fn): void
    {
        $this->callbacks[$event] = $fn;
    }

    public function set(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    // worker
    public function close(string $peer): bool
    {
        $sock = $this->socks[$peer];
        return $this->sendEvent($sock, 'close', $peer);
    }

    // worker
    public function send(string $peer, string $msg)
    {
        $sock = $this->socks[$peer];
        return $this->sendEvent($sock, 'send', $peer, $msg);
    }

    // master
    public function handleSocket(): void
    {
        $server = stream_socket_server("tcp://$this->host:$this->port", $errno, $errstr);
        if (! $server ) {
            Log::error("stream_socket_server error", $errno, $errstr);
            throw new RuntimeException("stream_socket_server error");
        }

        $connections = [];
        $socks = [];
        while ($this->running) {
            if ($conn = @stream_socket_accept($server, empty($connections) ? -1 : 0, $peer)) {
                stream_set_blocking($conn, false);
                $sock = $this->getClient($peer);
                $connections[] = $sock;
                $socks[] = $sock;
                $this->sendEvent($sock, 'connect', $peer);
                $connections[$peer] = $conn;
            }
            $readers = $connections;
            $writers = null;
            $except = null;
            if (@stream_select($readers, $writers, $except, 0, 0)) {
                foreach ($connections as $conn) {
                    // client sock
                    if (in_array($conn, $socks, true)) {
                        if ( $data = stream_get_line($conn, 1024, "\n") ) {
                            [$event, $peer, $payload] = explode('|', $data);
                            $conn = $connections[$peer];
                            switch ($event) {
                                case 'send':
                                    fwrite($conn, $payload);
                                    break;
                                case 'close':
                                    fclose($conn);
                                    unset($this->socks[$peer]);
                                    break;
                                default:
                                    Log::error('event', $event, 'not support');
                            }
                        }
                        continue;
                    }

                    // tcp sock
                    $peer = stream_socket_get_name($conn, true);
                    $client = $this->getClient($peer);
                    if (feof($conn)) {
                        $this->sendEvent($client, 'close', $peer);
                        unset($connections[$peer]);
                        continue;
                    }
                    if ($data = fread($conn, 1024)) {
                        $this->sendEvent($client, 'receive', $peer, $data);
                    }
                }
            }
        }

        // server end
        Log::info('Master process end !');
    }

    public function getClient(string $peer)
    {
        $sock = $this->getSock($peer);
        if (! isset($this->clients[$sock] )) {
            $client = @stream_socket_client("unix://$sock", $errno, $errstr);
            stream_set_blocking($client, false);
            if (! $client ) {
                Log::error("stream_socket_client error", $errno, $errstr);
                throw new RuntimeException("stream_socket_client error");
            }
            $this->clients[$sock] = $client;
        }
        return $this->clients[$sock];
    }

    public function getSock(string $peer): string
    {
        $workerId = crc32($peer) % $this->config['worker_num'];
        return WILON_RUNTIME_PATH.'/'.$workerId.'.sock';
    }


    public function shutdown(): void
    {
        $this->running = false;
    }

    public function handleSignal(): void
    {
        Process::signal(SIGCHLD, function ($signo, $siginfo) {
            if ( isset($siginfo['pid'] )) {
                // manager process end
                Process::wait();
                Log::info('Manager process end !');
                Log::info('Shutdown master process...');
                $this->shutdown();
            }
        });
        Process::signal(SIGTERM, function () {
            Process::kill($this->master->pid, SIGTERM);
        });
        Process::signal(SIGUSR1, function () {
            Process::kill($this->master->pid, SIGUSR1);
        });
        Process::signal(SIGUSR2, function () {
            Process::kill($this->master->pid, SIGUSR2);
        });
    }

    public function start(): void
    {
        // master process
        $this->master->start();

        // handle signal
        $this->handleSignal();

        // handle request
        $this->handleSocket();
    }
}