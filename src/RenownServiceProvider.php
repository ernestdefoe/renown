<?php

namespace ErnestDefoe\Renown;

use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;

/**
 * The first visit of the day: core saves `last_seen_at` on the member's row
 * every few minutes (never more), so the moment it saves on a new day is
 * the visit. Nothing runs on any other request.
 */
class RenownServiceProvider extends AbstractServiceProvider
{
    public function boot(ExtensionManager $extensions): void
    {
        User::saved(function (User $user) {
            if ($user->wasChanged('last_seen_at') && (int) $user->getAttribute('renown_day') !== Curve::day()) {
                resolve(Activity::class)->visited($user);
            }
        });

        if ($extensions->isEnabled('ernestdefoe-kudos') && class_exists(\ErnestDefoe\Kudos\Kudos::class)) {
            \ErnestDefoe\Kudos\Kudos::deleted(fn ($kudos) => resolve(Activity::class)->kudosRemoved((int) $kudos->id));
        }
    }
}
