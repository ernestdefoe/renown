<?php

namespace ErnestDefoe\Renown\Console;

use ErnestDefoe\Renown\Replay;
use Flarum\Console\AbstractCommand;

/**
 * Every minute (the scheduler): up to 45 seconds of the history replay while
 * one is waiting. Nothing to do costs one settings read.
 */
class ReplayCommand extends AbstractCommand
{
    public function __construct(private readonly Replay $replay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('renown:replay')
            ->setDescription('Continue a waiting Renown history replay for up to 45 seconds.');
    }

    protected function fire(): int
    {
        if ($this->replay->pending()) {
            $this->replay->run(45);
            $this->info('Renown replay: '.$this->replay->progress().'%');
        }

        return 0;
    }
}
