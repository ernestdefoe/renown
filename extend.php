<?php

/*
 * Renown: members earn XP by taking part and climb 100 levels across 20
 * ranks (Newcomer I to Immortal V). At Level 100 they may take Prestige:
 * twenty tiers, then Eternal.
 *
 * Every XP change goes through the Ledger, under the member's row lock, with
 * one log row per award: awards are idempotent and take-backs exact. What a
 * post, a user card or a list shows is on the user row Flarum already loads.
 * History is replayed in slices (Replay), never in one request.
 *
 * forum.js draws the insignia and wires things up; the leaderboard and the
 * Renown tab are one lazy chunk.
 */

use ErnestDefoe\Renown\Activity;
use ErnestDefoe\Renown\Api;
use ErnestDefoe\Renown\Badges;
use ErnestDefoe\Renown\Boosts;
use ErnestDefoe\Renown\Console;
use ErnestDefoe\Renown\Event\LevelChanged;
use ErnestDefoe\Renown\Event\PrestigeTaken;
use ErnestDefoe\Renown\Event\XpAwarded;
use ErnestDefoe\Renown\Gdpr\RenownData;
use ErnestDefoe\Renown\Integration;
use ErnestDefoe\Renown\Listener\Subscriber;
use ErnestDefoe\Renown\Notification\PrestigeReadyBlueprint;
use ErnestDefoe\Renown\Ranks;
use ErnestDefoe\Renown\RenownServiceProvider;
use ErnestDefoe\Renown\Replay;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\Settings\Event\Saved as SettingsSaved;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the leaderboard and the profile tab are a chunk in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/common.less')
        ->css(__DIR__.'/less/forum.less')
        ->route('/renown', 'renown'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/common.less')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/renown/board', 'renown.board', Api\BoardController::class)
        ->get('/renown/users/{id:\d+}', 'renown.user', Api\ProfileController::class)
        ->post('/renown/users/{id:\d+}/adjust', 'renown.adjust', Api\AdjustController::class)
        ->post('/renown/prestige', 'renown.prestige', Api\MeController::class)
        ->post('/renown/seen', 'renown.seen', Api\MeController::class)
        ->patch('/renown/me', 'renown.me', Api\MeController::class)
        ->get('/renown/admin', 'renown.admin', Api\AdminController::class)
        ->post('/renown/admin', 'renown.admin.act', Api\AdminController::class)
        ->post('/renown/boosts', 'renown.boosts.create', Api\BoostsController::class)
        ->delete('/renown/boosts/{id:\d+}', 'renown.boosts.delete', Api\BoostsController::class)
        ->post('/renown/icons/{kind:ranks|tiers}/{step:\d+}', 'renown.icons.upload', Api\IconController::class)
        ->delete('/renown/icons/{kind:ranks|tiers}/{step:\d+}', 'renown.icons.delete', Api\IconController::class),

    // Columns on the user row Flarum already loads: no query, on every post and card.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
            Schema\Integer::make('renownLevel')->get(fn (User $user) => (int) $user->getAttribute('renown_level')),
            Schema\Integer::make('renownPrestige')->get(fn (User $user) => (int) $user->getAttribute('renown_prestige')),
            Schema\Integer::make('renownXp')->get(fn (User $user) => (int) $user->getAttribute('renown_xp')),
            Schema\Integer::make('renownLifetimeXp')->get(fn (User $user) => (int) $user->getAttribute('renown_lifetime_xp')),
            Schema\Integer::make('renownSeen')
                ->visible(fn (User $user, Context $context) => (int) $context->getActor()->id === (int) $user->id)
                ->get(fn (User $user) => (int) $user->getAttribute('renown_seen')),
        ]),

    // The rank and tier names and icons (cached settings), and a running Double XP event (cached a minute).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('renown')->get(fn () => resolve(Ranks::class)->forForum()),
            Schema\Arr::make('renownBoosts')->get(fn () => array_map(
                fn ($b) => array_intersect_key($b, array_flip(['title', 'multiplier', 'starts_at', 'ends_at', 'tag_id'])),
                array_slice(resolve(Boosts::class)->upcoming(), 0, 3)
            )),
            Schema\Boolean::make('renownCanAdjust')->get(fn ($forum, Context $context) => $context->getActor()->can('renown.adjust')),
        ]),

    (new Extend\Notification())
        ->type(PrestigeReadyBlueprint::class, ['alert']),

    // 🚨 String literals, not Settings:: constants: extend.php runs while a deploy
    // swaps vendor/, and a class constant read here fatals every request then.
    (new Extend\Settings())
        ->default('ernestdefoe-renown.nav', true)
        ->default('ernestdefoe-renown.on_posts', true)
        ->default('ernestdefoe-renown.cap_daily', 300)
        ->default('ernestdefoe-renown.cap_likes', 50)
        ->default('ernestdefoe-renown.likes_per_giver', 2)
        ->default('ernestdefoe-renown.min_length', 20)
        ->default('ernestdefoe-renown.cooldown', 30)
        ->default('ernestdefoe-renown.xp_reply', 10)
        ->default('ernestdefoe-renown.xp_discussion', 20)
        ->default('ernestdefoe-renown.xp_like', 5)
        ->default('ernestdefoe-renown.xp_best_answer', 50)
        ->default('ernestdefoe-renown.xp_visit', 5)
        ->default('ernestdefoe-renown.xp_streak', 5)
        ->default('ernestdefoe-renown.xp_kudos', 25)
        ->default('ernestdefoe-renown.xp_answer_upvote', 5)
        ->default('ernestdefoe-renown.xp_testimonial', 25)
        ->default('ernestdefoe-renown.xp_corkboard_card', 5)
        ->default('ernestdefoe-renown.cap_corkboard', 50)
        // Buzz: rank-ups are frequent, so off until the admin turns them on; prestige on.
        ->default('ernestdefoe-buzz.type.renown_rank', false)
        ->serializeToForum('renownNav', 'ernestdefoe-renown.nav', 'boolval')
        ->serializeToForum('renownOnPosts', 'ernestdefoe-renown.on_posts', 'boolval'),

    (new Extend\ServiceProvider())
        ->register(RenownServiceProvider::class)
        ->register(Badges\BadgesServiceProvider::class),

    (new Extend\Event())
        ->subscribe(Subscriber::class)
        ->listen(LevelChanged::class, Badges\EvaluateRenownBadges::class)
        ->listen(PrestigeTaken::class, Badges\EvaluateRenownBadges::class)
        ->listen(XpAwarded::class, Badges\EvaluateRenownBadges::class)
        // Changed rewards apply to everyone: the totals step of the replay walks every member.
        ->listen(SettingsSaved::class, function (SettingsSaved $event) {
            if (array_intersect(['ernestdefoe-renown.ranks', 'ernestdefoe-renown.tiers'], array_keys($event->settings))) {
                // Never a failed settings save over it: if the replay is busy, the next save (or a
                // Rebuild) applies the rewards.
                try {
                    resolve(Replay::class)->requestTotals();
                } catch (\ErnestDefoe\Renown\Refusal) {
                }
            }
        }),

    (new Extend\Console())
        ->command(Console\RebuildCommand::class)
        ->command(Console\ReplayCommand::class)
        ->schedule(Console\ReplayCommand::class, function (ScheduledEvent $event) {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-likes', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Likes\Event\PostWasLiked::class, fn ($e) => resolve(Activity::class)->liked($e->post, $e->user))
                ->listen(\Flarum\Likes\Event\PostWasUnliked::class, fn ($e) => resolve(Activity::class)->unliked($e->post, $e->user)),
        ])
        ->whenExtensionEnabled('flarum-approval', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Approval\Event\PostWasApproved::class, fn ($e) => resolve(Activity::class)->posted($e->post, false)),
        ])
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\Event())
                ->listen(\Flarum\Tags\Event\DiscussionWasTagged::class, fn ($e) => resolve(Activity::class)->rescope($e->discussion)),
        ])
        ->whenExtensionEnabled('fof-best-answer', fn () => [
            (new Extend\Event())
                ->listen(\FoF\BestAnswer\Events\BestAnswerSet::class, fn ($e) => resolve(Activity::class)->bestAnswer($e->post))
                ->listen(\FoF\BestAnswer\Events\BestAnswerUnset::class, fn ($e) => resolve(Activity::class)->notBestAnswer($e->post)),
        ])
        ->whenExtensionEnabled('ernestdefoe-answers', fn () => [
            (new Extend\Event())
                ->listen(\ErnestDefoe\Answers\Event\AnswerAccepted::class, [Integration\Answers::class, 'accepted'])
                ->listen(\ErnestDefoe\Answers\Event\AnswerUnaccepted::class, [Integration\Answers::class, 'unaccepted'])
                ->listen(\ErnestDefoe\Answers\Event\ReputationChanged::class, [Integration\Answers::class, 'reputation']),
        ])
        ->whenExtensionEnabled('ernestdefoe-kudos', fn () => [
            (new Extend\Notification())->beforeSending(Integration\Kudos::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-testimonials', fn () => [
            (new Extend\Event())
                ->listen(\ErnestDefoe\Testimonials\Event\Approved::class, [Integration\Testimonials::class, 'approved'])
                ->listen(\ErnestDefoe\Testimonials\Event\Withdrawn::class, [Integration\Testimonials::class, 'withdrawn']),
        ])
        ->whenExtensionEnabled('ernestdefoe-corkboard', fn () => [
            (new Extend\Event())
                ->listen(\ErnestDefoe\Corkboard\Event\CardCompleted::class, [Integration\Corkboard::class, 'completed'])
                ->listen(\ErnestDefoe\Corkboard\Event\CardReopened::class, [Integration\Corkboard::class, 'reopened'])
                ->listen(\ErnestDefoe\Corkboard\Event\CardDeleted::class, [Integration\Corkboard::class, 'deleted']),
        ])
        ->whenExtensionEnabled('ernestdefoe-buzz', fn () => [
            (new \Ernestdefoe\Buzz\Extend\Buzz())
                ->type('renown.rank', 'fas fa-medal', 'ernestdefoe-renown.forum.buzz.rank', ['name' => 'ernestdefoe-renown.admin.buzz.rank'])
                ->type('renown.prestige', 'fas fa-star', 'ernestdefoe-renown.forum.buzz.prestige', ['name' => 'ernestdefoe-renown.admin.buzz.prestige']),
            (new Extend\Event())
                ->listen(LevelChanged::class, [Integration\Buzz::class, 'level'])
                ->listen(PrestigeTaken::class, [Integration\Buzz::class, 'prestige']),
        ])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(RenownData::class),
        ]),
];
