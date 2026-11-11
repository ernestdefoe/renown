<?php

namespace ErnestDefoe\Renown\Console;

use ErnestDefoe\Renown\Replay;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * php flarum renown:rebuild [--fresh]
 *
 * Runs the history replay to the end with a progress line; resumes where the
 * scheduler or the admin page got to. --fresh replays history again with
 * today's XP values (live XP stays).
 */
class RebuildCommand extends AbstractCommand
{
    public function __construct(private readonly Replay $replay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('renown:rebuild')
            ->setDescription('Give members the Renown XP their history earned (resumes a replay in progress).')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Replay history again from the start with the current XP values.');
    }

    protected function fire(): int
    {
        if ($this->input->getOption('fresh') || ! $this->replay->pending()) {
            $this->replay->request((bool) $this->input->getOption('fresh'));
        }

        while ($this->replay->pending()) {
            $this->replay->run(5);
            $state = $this->replay->state();
            $this->output->write("\r".str_pad(($state['phase'] ?? '').' '.$this->replay->progress().'%', 24));
        }
        $this->output->writeln('');
        $this->info('Renown: history replayed.');

        return 0;
    }
}
