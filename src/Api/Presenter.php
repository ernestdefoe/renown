<?php

namespace ErnestDefoe\Renown\Api;

use Flarum\User\User;

/** A member as Renown's pages show them: name, avatar, and where they stand. */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $user->username,
            'avatarUrl' => $user->avatar_url,
            'color' => $user->getAttribute('color'),
            'level' => (int) $user->getAttribute('renown_level'),
            'prestige' => (int) $user->getAttribute('renown_prestige'),
            'xp' => (int) $user->getAttribute('renown_xp'),
            'lifetime' => (int) $user->getAttribute('renown_lifetime_xp'),
        ];
    }
}
