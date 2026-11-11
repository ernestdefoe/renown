<?php

namespace ErnestDefoe\Renown\Integration;

use ErnestDefoe\Renown\Curve;
use ErnestDefoe\Renown\Event\LevelChanged;
use ErnestDefoe\Renown\Event\PrestigeTaken;
use ErnestDefoe\Renown\Ranks;
use Flarum\Locale\TranslatorInterface;

/**
 * ernestdefoe/buzz: "Ana reached Veteran" (a new rank, off by default: it's
 * frequent) and "Ana took Prestige: Flare" (on). Quiet level changes (the
 * replay, a take-back) never broadcast.
 */
class Buzz
{
    public function __construct(private readonly Ranks $ranks, private readonly TranslatorInterface $translator)
    {
    }

    public function level(LevelChanged $event): void
    {
        $rank = Curve::rank($event->to);
        if ($event->quiet || $rank <= Curve::rank($event->from)) {
            return;
        }

        resolve(\Ernestdefoe\Buzz\Recorder::class)->record('renown.rank', $event->user, ['rank' => $this->name('ranks', $rank)], [
            'route' => ['name' => 'user.renown', 'params' => ['username' => $event->user->username]],
        ]);
    }

    public function prestige(PrestigeTaken $event): void
    {
        resolve(\Ernestdefoe\Buzz\Recorder::class)->record('renown.prestige', $event->user, ['tier' => $this->name('tiers', $event->tier)], [
            'route' => ['name' => 'user.renown', 'params' => ['username' => $event->user->username]],
        ]);
    }

    private function name(string $kind, int $step): string
    {
        $list = $kind === 'ranks' ? $this->ranks->ranks() : $this->ranks->tiers();

        return $list[$step - 1]['name'] ?: $this->translator->trans('ernestdefoe-renown.lib.'.$kind.'.'.$step);
    }
}
