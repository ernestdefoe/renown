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
    /** Whether any active badge is triggered by a Renown metric, and when that was asked (queue workers live long). */
    private static ?bool $used = null;

    private static int $askedAt = 0;

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

        // fof/badges runs two queries per evaluation even when no badge uses these metrics:
        // look once whether any does, so a reply on a forum without such badges costs nothing.
        if (self::$used === null || time() - self::$askedAt > 60) {
            self::$askedAt = time();
            self::$used = $this->container->make('db')->table('fof_badges')
                ->where('is_active', true)
                ->whereNotNull('trigger_config')
                ->where(fn ($q) => $q->where('trigger_config', 'like', '%renown_level%')
                    ->orWhere('trigger_config', 'like', '%renown_prestige%')
                    ->orWhere('trigger_config', 'like', '%renown_lifetime_xp%'))
                ->exists();
        }
        if (! self::$used) {
            return;
        }

        $evaluator = $this->container->make('FoF\Badges\Trigger\TriggerEvaluator');
        $awarder = $this->container->make('FoF\Badges\BadgeAwarder');
        foreach ($evaluator->evaluateForEvent($user, get_class($event)) as $badge) {
            $awarder->award($user, $badge);
        }
    }
}
