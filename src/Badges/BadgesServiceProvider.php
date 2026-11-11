<?php

namespace ErnestDefoe\Renown\Badges;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * Adds Renown's metrics to FoF Badges' trigger builder.
 *
 * 🚨 Nothing here may load a fof/badges class when fof/badges is not
 * installed: the callback only runs when fof/badges builds its MetricManager.
 */
class BadgesServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving('FoF\Badges\Trigger\MetricManager', function ($metrics) {
            $metrics->register(new Metric('renown_level', 'renown_level'));
            $metrics->register(new Metric('renown_prestige', 'renown_prestige'));
            $metrics->register(new Metric('renown_lifetime_xp', 'renown_lifetime_xp'));
        });
    }
}
