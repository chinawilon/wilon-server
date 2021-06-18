<?php

declare(ticks = 1);

namespace Wilon;

class Process
{
    /**
     * @var callable
     */
    private $fn;
    /**
     * @var int
     */
    public $pid;

    public function __construct(callable $fn)
    {
        $this->fn = $fn;
    }

    public static function kill(int $pid, int $signo): bool
    {
        return posix_kill($pid, $signo);
    }

    public static function wait(&$status = null): int
    {
        return pcntl_wait($status);
    }

    public static function signal($signo, $fn): bool
    {
        return pcntl_signal($signo, $fn);
    }

    public static function waitpid(int $pid, &$status = null, $options = null): int
    {
        return pcntl_waitpid($pid, $status, $options);
    }

    public function start(): void
    {
        $pid = pcntl_fork();
        if ( $pid > 0 ) {
            $this->pid = $pid;
            return ;
        }
        // exit process
        exit(call_user_func(
            $this->fn)
        );
    }
}