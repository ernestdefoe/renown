<?php

namespace ErnestDefoe\Renown\Event;

use Flarum\User\User;

/**
 * A member's level moved, up or down. Quiet when nobody should be told: a
 * take-back, the history replay, a reset. Group rewards follow either way.
 */
class LevelChanged
{
    public function __construct(
        public User $user,
        public int $from,
        public int $to,
        public bool $quiet = false,
    ) {
    }
}
