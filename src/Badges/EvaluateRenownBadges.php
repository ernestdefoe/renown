<?php

namespace ErnestDefoe\Renown\Badges;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Awards FoF Badges that use Renown's metrics when XP, a level or a tier
 * changes. fof/badges' own listener only knows the events it ships with.
 * Quiet level changes (the replay, take-backs) are left to
 * `php flarum badges:recalculate`.
 */
class EvaluateRenownBadges
{
    public function __construct(private readonly ExtensionManager $extensions, private readonly Container $container)
    {
    }

    public function handle(object $event): void
    {
        if (($event->quiet ?? false) || ! $this->extensions->isEnabled('fof-badges') || ! class_exists('FoF\Badges\Trigger\MetricManager')) {
            return;
        }

        $user = $event->user ?? null;
        if (! $user instanceof User || ! $user->exists) {
            return;
        }

        $evaluator = $this->container->make('FoF\Badges\Trigger\TriggerEvaluator');
        $awarder = $this->container->make('FoF\Badges\BadgeAwarder');
        foreach ($evaluator->evaluateForEvent($user, get_class($event)) as $badge) {
            $awarder->award($user, $badge);
        }
    }
}
