<?php


namespace Wilon;

use Log;
use RuntimeException;

class Server
{

    /**
     * @var array
     */
    private $config = ['worker_num' => 1];

    /**
     * @var array
     */
    private $callbacks;

    /**
     * @var Process
     */
    private $master;
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
    private $clients;

    /**
     * @var
     */
    private $connections;

    /**
     * @var bool
     */
    private $running;

    /**
     * Server constructor.
     *
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;

        $this->master = new Process(function () {
            $pm = new Manager();
            if ( $this->config['worker_num'] ) {
                $pm->addBatch($this->config['worker_num'], function (int $workerId) {
                    if ( isset($this->callbacks['WorkerStart']) ) {
                        call_user_func($this->callbacks['WorkerStart'], $this, $workerId);
                    }
                    $sock = RUNTIME_PATH.'/'.$workerId.'.sock';
                    if ( file_exists($sock) ) {
                        unlink($sock);
                    }
                    $server = stream_socket_server("unix://$sock", $errno, $errstr);
                    if (! $server ) {
                        Log::error("stream_socket_server error", $errno, $errstr);
                        throw new RuntimeException("stream_socket_server error");
                    }
                    for (;;) {
                        $conn = @stream_socket_accept($server, -1);
                        while ($data = stream_get_line($conn, 1024, "\n")) {
                            [$event, $peer, $payload] = explode('|', $data);
                            $this->connections[$peer] = $conn;
                            switch ($event) {
                                case 'connect':
                                    if ( isset($this->callbacks['connect']) ) {
                                        call_user_func($this->callbacks['connect'], $this, $peer);
                                    }
                                    break;
                                case 'close':
                                    if ( isset($this->callbacks['close']) ) {
                                        call_user_func($this->callbacks['close'], $this, $peer);
                                    }
                                    break;
                                case 'receive':
                                    if ( isset($this->callbacks['receive']) ) {
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

    /**
     * Send the msg event
     *
     * @param $peer
     * @param string $msg
     * @return false|int
     */
    public function send($peer, string $msg)
    {
        $conn = $this->connections[$peer];
        return $this->sendEvent($conn, 'send', $peer, $msg);
    }

    /**
     * Close the conn event
     *
     * @param $peer
     * @return false|int
     */
    public function close($peer)
    {
        $conn = $this->connections[$peer];
        return $this->sendEvent($conn, 'close', $peer);
    }

    /**
     * Server bind event
     *
     * @param $event
     * @param callable $fn
     */
    public function on($event, callable $fn): void
    {
        $this->callbacks[$event] = $fn;
    }

    /**
     * Server configuration
     *
     * @param array $config
     */
    public function set(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }


    /**
     * Handle the request
     */
    public function handleSocket(): void
    {
        $server = stream_socket_server("tcp://$this->host:$this->port", $errno, $errstr);
        if (! $server ) {
            Log::error("stream_socket_server error", $errno, $errstr);
            throw new RuntimeException("stream_socket_server error");
        }

        $connections = [];
        while ($this->running) {
            if ($conn = @stream_socket_accept($server, empty($connections) ? -1 : 0, $peer)) {
                stream_set_blocking($conn, false);
                $client = $this->getClient($peer);
                $this->sendEvent($client, 'connect', $peer);
                $connections[$peer] = $conn;
                $connections[] = $client;
            }
            $readers = $connections;
            $writers = null;
            $except = null;
            if (@stream_select($readers, $writers, $except, 0, 0)) {
                foreach ($connections as $conn) {
                    // client
                    if (in_array($conn, $this->clients, true)) {
                        if ($data = stream_get_line($conn, 1024, "\n")) {
                            [$event, $peer, $payload] = explode('|', $data);
                            switch ($event) {
                                case 'send':
                                    $conn = $connections[$peer];
                                    fwrite($conn, $payload);
                                    break;
                                case 'close':
                                    unset($connections[$peer]);
                                    fclose($connections[$peer]);
                                    break;
                                default:
                                    Log::error('event', $event, 'not support');
                            }
                        }
                        continue;
                    }

                    // conn
                    $peer = stream_socket_get_name($conn, true);
                    if (feof($conn)) {
                        $client = $this->getClient($peer);
                        $this->sendEvent($client, 'close', $peer);
                        unset($connections[$peer]);
                        continue;
                    }
                    if ($data = fread($conn, 1024)) {
                        $client = $this->getClient($peer);
                        $this->sendEvent($client, 'receive', $peer, $data);
                    }
                }
            }

        }
    }

    public function getClient(string $peer)
    {
        $sock = $this->getSock($peer);
        if (! isset($this->clients[$peer]) ) {
            $client = @stream_socket_client("unix://$sock", $errno, $errstr);
            stream_set_blocking($client, false);
            if (! $client ) {
                Log::error("stream_socket_client error", $errno, $errstr);
                throw new RuntimeException("stream_socket_client error");
            }
            $this->clients[$peer] = $client;
        }

        return $this->clients[$peer];
    }

    /**
     * Get the socket
     *
     * @param string $peer
     * @return string
     */
    public function getSock(string $peer): string
    {
        $workerId = crc32($peer) % $this->config['worker_num'];
        return RUNTIME_PATH. '/' . $workerId. '.sock';
    }


    /**
     * Send event
     *
     * @param $client
     * @param string $event
     * @param string $peer
     * @param string $payload
     * @return false|int
     */
    public function sendEvent($client, string $event, string $peer, string $payload = '')
    {
        $data = implode('|', [$event, $peer, $payload]);
        return fwrite($client, $data."\n");
    }

    /**
     * Handle the signal
     */
    public function handleSignal(): void
    {
        pcntl_signal(SIGTERM, function () {
            $this->running = false;
            Process::kill($this->master->pid, SIGTERM);
        });
        pcntl_signal(SIGUSR1, function () {
            Process::kill($this->master->pid, SIGUSR1);
        });
        pcntl_signal(SIGUSR2, function () {
            Process::kill($this->master->pid, SIGUSR2);
        });
    }


    /**
     * Start the server
     */
    public function start(): void
    {
        // master process
        $this->master->start();

        // Handle signal
        $this->handleSignal();

        // handle request
        $this->handleSocket();

        // wait manager process end
        Process::wait();
    }
}