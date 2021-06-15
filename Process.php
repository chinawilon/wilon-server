<?php


namespace Wilon;


use Log;

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

    public function start(): void
    {
        $pid = pcntl_fork();
        if ( $pid > 0 ) {
            $this->pid = $pid;
            return ;
        }
        // children process
        call_user_func($this->fn);
    }
}