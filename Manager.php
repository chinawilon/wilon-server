<?php


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

    public function addBatch(int $workerNum, callable $fn): self
    {
        for ($i = 0; $i < $workerNum; $i++) {
            $this->startFuncMap[] = $fn;
        }
        return $this;
    }

    public function pids(): array
    {
        return $this->pids;
    }

    public function start(): void
    {
        $workerId = 0;
        foreach ($this->startFuncMap as $fn) {
            $process = new Process(function () use($fn, $workerId) {
                $fn($workerId);
            });
            $process->start();
            $this->pids[] = $process->pid;
            $workerId++;
        }

        //waiting for children process end
        for ($i = 0; $i <= $workerId; $i++) {
            Process::wait();
        }
    }
}