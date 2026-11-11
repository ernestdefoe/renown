<?php

namespace ErnestDefoe\Renown\Event;

use Flarum\User\User;

/** A member chose Prestige: tier 1–20, or 21 for Eternal. */
class PrestigeTaken
{
    public function __construct(public User $user, public int $tier)
    {
    }
}
