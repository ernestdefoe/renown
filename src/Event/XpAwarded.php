<?php

namespace ErnestDefoe\Renown\Event;

use Flarum\User\User;

/** XP earned by taking part (never the replay or a take-back). FoF Badges' lifetime XP metric listens. */
class XpAwarded
{
    public function __construct(public User $user, public string $source, public int $xp)
    {
    }
}
