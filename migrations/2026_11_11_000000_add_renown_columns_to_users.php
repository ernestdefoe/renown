<?php

use Flarum\Database\Migration;

// What every post, user card and list needs is on the user row Flarum already loads: no extra query.
return Migration::addColumns('users', [
    'renown_xp' => ['integer', 'unsigned' => true, 'default' => 0],
    'renown_lifetime_xp' => ['bigInteger', 'unsigned' => true, 'default' => 0],
    'renown_level' => ['tinyInteger', 'unsigned' => true, 'default' => 1],
    'renown_prestige' => ['tinyInteger', 'unsigned' => true, 'default' => 0],
    'renown_seen' => ['integer', 'unsigned' => true, 'default' => 0],
    'renown_day' => ['integer', 'unsigned' => true, 'default' => 0],
    'renown_streak_day' => ['integer', 'unsigned' => true, 'default' => 0],
    'renown_hidden' => ['boolean', 'default' => false],
]);
