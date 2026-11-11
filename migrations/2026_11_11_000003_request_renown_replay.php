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
        // History ends here: posts, likes and kudos after this are live, and a replay never counts them again.
        if (! $db->table('settings')->where('key', 'ernestdefoe-renown.boundary')->exists()) {
            $db->table('settings')->insert(['key' => 'ernestdefoe-renown.boundary', 'value' => json_encode([
                'posts' => (int) $db->table('posts')->max('id'),
                'kudos' => $schema->hasTable('kudos') ? (int) $db->table('kudos')->max('id') : 0,
                'at' => gmdate('Y-m-d H:i:s'),
            ])]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', ['ernestdefoe-renown.replay', 'ernestdefoe-renown.boundary'])->delete();
    },
];
