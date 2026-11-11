<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The leaderboard order: prestige, then XP this climb (the level follows the XP).
return [
    'up' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->index(['renown_prestige', 'renown_xp']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->dropIndex(['renown_prestige', 'renown_xp']);
        });
    },
];
