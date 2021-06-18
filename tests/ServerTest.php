<?php

use PHPUnit\Framework\TestCase;
use Wilon\Log;
use Wilon\Server;

class ServerTest extends TestCase
{
    public function testBuildProcess(): void
    {
        $server = new Server('0.0.0.0', 8080);
        $server->set(['worker_num' => 4]);
        $server->on('connect', function (Server $server, string $peer) {
            Log::info($peer, 'connect');
        });
        $server->on('close', function (Server $server, string $peer) {
            Log::info($peer, 'closed');
        });
        $server->on('receive', function (Server $server, string $peer, string $data) {
            $server->send($peer, $data);
        });
        $server->start();
    }
}