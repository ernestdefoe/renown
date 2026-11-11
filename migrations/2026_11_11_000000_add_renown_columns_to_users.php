<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// What every post, user card and list needs is on the user row Flarum already loads: no extra query.
return [
    'up' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->unsignedInteger('renown_xp')->default(0);
            $table->unsignedBigInteger('renown_lifetime_xp')->default(0);
            $table->unsignedTinyInteger('renown_level')->default(1);
            $table->unsignedTinyInteger('renown_prestige')->default(0);
            $table->unsignedInteger('renown_seen')->default(0);
            $table->unsignedInteger('renown_day')->default(0);
            $table->unsignedInteger('renown_streak_day')->default(0);
            $table->boolean('renown_hidden')->default(false);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('users', function (Blueprint $table) {
            $table->dropColumn(['renown_xp', 'renown_lifetime_xp', 'renown_level', 'renown_prestige', 'renown_seen', 'renown_day', 'renown_streak_day', 'renown_hidden']);
        });
    },
];
