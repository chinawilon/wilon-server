<?php

declare(ticks=1);

namespace Wilon;


class Manager
{
    /**
     * @var array
     */
    private $startFuncMap;

    /**
     * @var array
     */
    private $pids;

    /**
     * @var bool
     */
    private $restart = true;

    public function __construct()
    {
        $this->handleSignal();
    }


    public function handleSignal(): void
    {
        Process::signal(SIGTERM, function () {
            $this->restart = false;
            foreach ($this->pids as $pid) {
                Process::kill($pid, SIGTERM);
            }
        });
        Process::signal(SIGUSR1, function () {
            foreach ($this->pids as $pid) {
                Process::kill($pid, SIGTERM);
            }
        });
        Process::signal(SIGUSR2, function () {
            foreach ($this->pids as $pid) {
                Process::kill($pid, SIGTERM);
            }
        });
    }

    public function addBatch(int $workerNum, callable $fn): void
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->startFuncMap[] = $fn;
        }
    }

    public function process(int $workerId, callable $fn): Process
    {
        $process = new Process(function () use($workerId, $fn) {
            Process::signal(SIGTERM, SIG_DFL);
            Process::signal(SIGUSR1, SIG_DFL);
            Process::signal(SIGUSR2, SIG_DFL);
            $fn($workerId);
        });
        $process->start();
        return $process;
    }

    public function start(): void
    {
        foreach ($this->startFuncMap as $workerId => $fn) {
            $process = $this->process($workerId, $fn);
            $this->pids[$workerId] = $process->pid;
        }

        for (;;) {
            $pid = Process::waitpid(-1, $status, WNOHANG);
            // all children process end
            if ( $pid < 0 ) {
                break;
            }
            // all process ok
            if ( $pid === 0 ) {
                sleep(1);
                continue;
            }

            // restart and fork new process
            if ( $this->restart  && (pcntl_wifexited($status) || pcntl_wifsignaled($status)) ) {
                $workerId = array_search($pid, $this->pids, true);
                $process = $this->process($workerId, $this->startFuncMap[$workerId]);
                $this->pids[$workerId] = $process->pid;
            }
        }
    }
}