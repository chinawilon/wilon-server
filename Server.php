<?php


namespace Wilon;

use Log;
use RuntimeException;

class Server
{

    /**
     * My Server Configuration
     *
     * @var string[]
     */
    private $config = [
        'worker_num' => 1
    ];

    /**
     * @var array
     */
    private $callbacks = [];

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
    private $clients = [];

    /**
     * @var string
     */
    private $sock = RUNTIME_PATH.'/sock';

    /**
     * @var resource
     */
    private $mainClient;

    /**
     * MyServer constructor.
     *
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;

        // master
        $this->master = new Process(function () {
            $pm = new Manager();
            // worker
            if ( $this->config['worker_num'] ) {
                $pm->addBatch($this->config['worker_num'], function ($workerId){
                    // worker start
                    if ( isset($this->callbacks['WorkerStart']) ) {
                        call_user_func($this->callbacks['WorkerStart']);
                    }
                    // unix socket
                    $sock = RUNTIME_PATH.'/'.$workerId.'.sock';
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
                            [$event, $payload, $peer] = explode('|', $data);
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


    /**
     * Handle the socket
     */
    public function handleSocket(): void
    {
        // Accept TCP connect
        $server = stream_socket_server("tcp://$this->host:$this->port", $errno, $errstr);
        if (! $server ) {
            Log::error("stream_socket_server error", $errno, $errstr);
            throw new RuntimeException("stream_socket_server error");
        }

        /////////// sock start /////////////
        if ( file_exists($this->sock) ) {
            unlink($this->sock);
        }
        $sock = stream_socket_server("unix://$this->sock", $errno, $errstr);
        if (! $sock ) {
            Log::error("stream_socket_server error", $errno, $errstr);
            throw new RuntimeException("stream_socket_server error");
        }
        /////////// sock end /////////////

        $connections = [];
        $clients = [];
        for (;;) {
            // server
            if ($conn = @stream_socket_accept($server, empty($connections) ? -1 : 0, $peer)) {
                stream_set_blocking($conn, false);
                $client = $this->getClient($peer);
                $this->sendEvent($client, 'connect', $peer);
                $connections[$peer] = $conn;
            }

            $readers = $connections;
            $writers = null;
            $except = null;
            if (@stream_select($readers, $writers, $except, 0, 0)) {
                foreach ($connections as $conn) {
                    $peer = stream_socket_get_name($conn, true);
                    $client = $this->getClient($peer);
                    if (feof($conn)) {
                        unset($connections[$peer]);
                        $this->sendEvent($client, 'close', $peer);
                        continue;
                    }
                    if ($data = fread($conn, 1024)) {
                        $this->sendEvent($client, 'receive', $peer, $data);
                    }
                }
            }

            // sock accept
            if ($conn = @stream_socket_accept($sock, 0, $peer)) {
                stream_set_blocking($conn, false);
                $clients[] = $conn;
            }
            // continue if not accept
            if ( count($clients) === 0 ) {
                continue;
            }
            $readers = $clients;
            $writers = null;
            $except = null;
            if (@stream_select($readers, $writers, $except, 0, 0)) {
                foreach ($clients as $client) {
                    if ($data = stream_get_line($client, 1024, "\n")) {
                        [$event, $payload, $peer] = explode('|', $data);
                        if (! isset($connections[$peer])) {
                            continue;
                        }
                        $conn = $connections[$peer];
                        switch ($event) {
                            case 'send':
                                fwrite($conn, $payload);
                                break;
                            case 'close':
                                fclose($conn);
                                unset($connections[$peer]);
                                break;
                            default:
                                Log::error('event', $event, 'not support');
                        }
                    }
                }
            }

        }
    }


    /**
     * @param string $peer
     * @param string $msg
     * @return false|int
     */
    public function send(string $peer, string $msg)
    {
        $client = $this->getMainClient();
        return $this->sendEvent($client, 'send', $peer, $msg);
    }

    /**
     * @param string $peer
     * @return bool
     */
    public function close(string $peer): bool
    {
        $client = $this->getMainClient();
        return $this->sendEvent($client, 'close', $peer);
    }

    /**
     * Get main client
     *
     * @return false|resource
     */
    public function getMainClient()
    {
        if ($this->mainClient) {
            return $this->mainClient;
        }
        $client = @stream_socket_client("unix://$this->sock", $errno, $errstr);
        if (!$client) {
            Log::error("stream_socket_client error", $errno, $errstr);
            throw new RuntimeException("stream_socket_client error");
        }
        return $this->mainClient = $client;
    }

    /**
     * @param resource $client
     * @param string $event
     * @param string $payload
     * @param string $peer
     * @return false|int
     */
    public function sendEvent($client, string $event, string $peer, string $payload = '')
    {
        $payload = implode('|', [$event, $payload, $peer]);
        return fwrite($client, $payload."\n");
    }

    /**
     * Get the sock
     *
     * @param string $peer
     * @return string
     */
    public function getSock(string $peer): string
    {
        $workerId = crc32($peer) % $this->config['worker_num'];
        return RUNTIME_PATH.'/'.$workerId.'.sock';
    }

    /**
     * Get the client
     *
     * @param $peer
     * @return mixed
     */
    public function getClient($peer)
    {
        $sock = $this->getSock($peer);
        if (! isset( $this->clients[$sock]) ) {
            $client = @stream_socket_client("unix://$sock", $errno, $errstr);
            if (!$client) {
                Log::error("stream_socket_client error", $errno, $errstr);
                throw new RuntimeException("stream_socket_client error");
            }
            $this->clients[$sock] = $client;
        }
        return $this->clients[$sock];
    }

    /**
     * Set the configuration
     *
     * @param array $config
     */
    public function set(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Listen the event
     *
     * @param $event
     * @param callable $fn
     */
    public function on($event, callable $fn): void
    {
        $this->callbacks[$event] = $fn;
    }

    /**
     * Start the wilon
     *
     * @return mixed
     */
    public function start()
    {
        // Setup the master process
        $this->master->start();
        // Handle socket wilon
        $this->handleSocket();
    }
}