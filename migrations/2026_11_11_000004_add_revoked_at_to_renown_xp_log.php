<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// A hidden post's XP is revoked, not deleted: restoring it brings back exactly what it earned.
return [
    'up' => function (Builder $schema) {
        $schema->table('renown_xp_log', function (Blueprint $table) {
            $table->dateTime('revoked_at')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('renown_xp_log', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    },
];
