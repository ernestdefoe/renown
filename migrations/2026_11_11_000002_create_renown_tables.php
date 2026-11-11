<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // One row per award. The unique key makes every award idempotent and every take-back exact.
        $schema->create('renown_xp_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->string('source', 24);
            $table->unsignedInteger('subject_id')->default(0);
            $table->unsignedInteger('actor_id')->default(0);
            $table->integer('xp');
            $table->unsignedTinyInteger('prestige')->default(0);
            $table->unsignedInteger('day');
            $table->boolean('backfilled')->default(false);
            $table->string('note', 200)->nullable();
            $table->dateTime('created_at');

            $table->unique(['user_id', 'source', 'subject_id', 'actor_id']);
            $table->index(['user_id', 'day']);
            $table->index(['user_id', 'created_at']);
            $table->index(['source', 'subject_id']);
            $table->index('created_at');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->create('renown_prestiges', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedTinyInteger('tier');
            $table->dateTime('reached_at');

            $table->primary(['user_id', 'tier']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Only groups Renown itself gave: a member who was in the group before keeps it.
        $schema->create('renown_grants', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('group_id');
            $table->string('kind', 10);
            $table->unsignedTinyInteger('step');

            $table->primary(['user_id', 'group_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
        });

        $schema->create('renown_boosts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title', 100)->nullable();
            $table->unsignedTinyInteger('multiplier');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('tag_id')->nullable();

            $table->index('ends_at');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('renown_boosts');
        $schema->dropIfExists('renown_grants');
        $schema->dropIfExists('renown_prestiges');
        $schema->dropIfExists('renown_xp_log');
    },
];
