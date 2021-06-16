<?php

declare(ticks = 1);

namespace Wilon;


use Log;

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
    private $running = true;

    public function __construct()
    {
        $this->handleSignal();
    }

    public function handleSignal(): void
    {
        // stop all sigterm
        pcntl_signal(SIGTERM, function () use(&$restart) {
            $this->running = false;
            Log::info('manager sigterm');
            foreach ($this->pids as $pid) {
                Process::kill($pid, SIGTERM);
            }
        });

        // sigusr1
        pcntl_signal(SIGUSR1, function () {
            foreach ($this->pids as $pid) {
                Process::kill($pid, SIGTERM);
            }
        });

        // sigusr2
        pcntl_signal(SIGUSR2, function () {
            foreach ($this->pids as $pid) {
                Process::kill($pid, SIGTERM);
            }
        });
    }

    public function addBatch(int $workerNum, callable $fn): self
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->startFuncMap[] = $fn;
        }
        return $this;
    }

    public function process($workerId, callable $fn): Process
    {
        $process = new Process(function () use($workerId, $fn) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_signal(SIGUSR2, SIG_DFL);
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

        while ($this->running) {
            if ( ($pid = Process::waitpid(-1, $status, WNOHANG)) <= 0) {
                sleep(1);
                continue;
            }
            if (pcntl_wifexited($status) || pcntl_wifsignaled($status) || pcntl_wifstopped($status)) {
                $workerId = array_search($pid, $this->pids, true);
                $process = $this->process($workerId, $this->startFuncMap[$workerId]);
                $this->pids[$workerId] = $process->pid;
            }
        }
    }
}