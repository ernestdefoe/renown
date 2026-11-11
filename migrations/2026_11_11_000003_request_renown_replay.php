<?php

use Illuminate\Database\Schema\Builder;

// Members start with the XP their history earned. The work itself never runs in the
// request that installs Renown: the scheduler, the admin page or `php flarum renown:rebuild`
// takes it in slices (Replay).
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $exists = $db->table('settings')->where('key', 'ernestdefoe-renown.replay')->exists();
        if (! $exists) {
            $db->table('settings')->insert(['key' => 'ernestdefoe-renown.replay', 'value' => json_encode(['phase' => 'posts', 'cursor' => 0])]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-renown.replay')->delete();
    },
];
