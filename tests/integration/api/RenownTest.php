<?php

namespace ErnestDefoe\Renown\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Renown\Curve;
use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Replay;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * What members can lean on: posts and likes earn XP once, the anti-farming
 * rules hold, hidden posts and removed likes give it back exactly, Prestige
 * is the member's own choice at Level 100, the replay is uncapped and never
 * prestiges anyone, and the board only shows who the viewer may see.
 */
class RenownTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const NOW = '2026-11-11 12:00:00';

    private const AVA = 3;
    private const BO = 4;
    private const CY = 5;
    private const MO = 6;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->extension('flarum-likes', 'ernestdefoe-renown');

        $user = fn (int $id, string $name) => [
            'id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subDays(30),
        ];

        $this->prepareDatabase([
            User::class => [$this->normalUser(), $user(self::AVA, 'ava'), $user(self::BO, 'bo'), $user(self::CY, 'cy'), $user(self::MO, 'mo')],
            'group_user' => [
                ...array_map(fn ($id) => ['user_id' => $id, 'group_id' => Group::MEMBER_ID], [self::AVA, self::BO, self::CY]),
                ['user_id' => self::MO, 'group_id' => Group::MODERATOR_ID],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Migrating to Flarum 2', 'slug' => 'migrating', 'user_id' => self::AVA, 'created_at' => Carbon::now()->subDay(), 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => 0],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => self::AVA, 'type' => 'comment', 'number' => 1, 'content' => '<t><p>How do I migrate my forum to Flarum 2?</p></t>', 'created_at' => Carbon::now()->subDay(), 'is_private' => 0],
            ],
            'group_permission' => [
                ['permission' => 'discussion.likePosts', 'group_id' => Group::MEMBER_ID],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<string> */
    protected function allowedRepeatedQueries(): array
    {
        // FoF Byobu loads each recipient on its own when recipients change (the same request
        // without Renown shows it): not Renown's, and only in the Byobu test.
        $g = $this->database()->getQueryGrammar();

        return ['select * from '.$g->wrapTable('users').' where '.$g->wrap('users.id').' = ?'];
    }

    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $response = $this->send($this->request($method, '/api'.$path, $options)->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function reply(int $as, string $text = 'Here is exactly what worked for me when we moved over.'): int
    {
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        [$status, $body] = $this->api('POST', '/posts', $as, ['data' => ['type' => 'posts', 'attributes' => ['content' => $text], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]]);
        $this->assertSame(201, $status, json_encode($body));

        return (int) $body['data']['id'];
    }

    private function like(int $postId, int $as, bool $liked = true): void
    {
        [$status, $body] = $this->api('PATCH', '/posts/'.$postId, $as, ['data' => ['type' => 'posts', 'id' => (string) $postId, 'attributes' => ['isLiked' => $liked]]]);
        $this->assertSame(200, $status, json_encode($body));
    }

    private function user(int $id): object
    {
        return $this->database()->table('users')->where('id', $id)->first(['renown_xp', 'renown_lifetime_xp', 'renown_level', 'renown_prestige']);
    }

    private function xp(int $id, ?string $source = null): int
    {
        $q = $this->database()->table(Ledger::TABLE)->where('user_id', $id);
        if ($source) {
            $q->where('source', $source);
        }

        return (int) $q->sum('xp');
    }

    private function set(string $key, mixed $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('ernestdefoe-renown.'.$key, $value);
    }

    // ---------------------------------------------------------------- the curve

    #[Test]
    public function the_curve_matches_the_approved_table(): void
    {
        $this->assertSame(0, Curve::xpFor(1));
        $this->assertSame(30, Curve::xpFor(2));
        $this->assertSame(93210, Curve::xpFor(100));
        $this->assertSame(1, Curve::levelFor(29));
        $this->assertSame(2, Curve::levelFor(30));
        $this->assertSame(99, Curve::levelFor(93209));
        $this->assertSame(100, Curve::levelFor(500000));
        $this->assertSame([20, 5], [Curve::rank(100), Curve::grade(100)]);
        $this->assertSame([9, 2], [Curve::rank(42), Curve::grade(42)]);

        for ($level = 2; $level <= 100; $level++) {
            $this->assertGreaterThan(Curve::xpFor($level - 1), Curve::xpFor($level));
        }
    }

    // ---------------------------------------------------------------- earning

    #[Test]
    public function a_reply_earns_xp_once_with_the_days_visit(): void
    {
        $this->app();
        $this->reply(self::BO);

        $this->assertSame(10, $this->xp(self::BO, 'reply'));
        $this->assertSame(5, $this->xp(self::BO, 'visit'));
        $bo = $this->user(self::BO);
        $this->assertSame(15, (int) $bo->renown_xp);
        $this->assertSame(15, (int) $bo->renown_lifetime_xp);
    }

    #[Test]
    public function short_replies_and_the_cooldown_earn_nothing(): void
    {
        $this->app();
        $this->reply(self::BO, '+1');
        $this->assertSame(0, $this->xp(self::BO, 'reply'));

        // Quotes don't count toward the minimum length.
        $this->reply(self::BO, "> Here is exactly what worked for me when we moved over.\n\nSame!");
        $this->assertSame(0, $this->xp(self::BO, 'reply'));

        $this->set('cooldown', 300);
        $this->reply(self::BO);
        $this->reply(self::BO);
        $this->assertSame(10, $this->xp(self::BO, 'reply'), 'the second reply came within the cooldown');
    }

    #[Test]
    public function the_daily_cap_holds(): void
    {
        $this->set('cap_daily', 30);
        $this->app();
        foreach (range(1, 4) as $i) {
            $this->reply(self::BO);
        }
        // 5 for the visit, then 10 + 10, then 5 more fits, then nothing.
        $this->assertSame(30, $this->xp(self::BO));
    }

    #[Test]
    public function likes_earn_and_an_unlike_gives_it_back(): void
    {
        $this->app();
        $post = $this->reply(self::BO);
        $this->like($post, self::AVA);
        $this->like($post, self::CY);
        $this->assertSame(10, $this->xp(self::BO, 'like'));

        $this->like($post, self::CY, false);
        $this->assertSame(5, $this->xp(self::BO, 'like'));
        $this->assertSame(20, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function likes_per_giver_are_limited_each_day(): void
    {
        $this->set('likes_per_giver', 1);
        $this->app();
        $a = $this->reply(self::BO);
        $b = $this->reply(self::BO);
        $this->like($a, self::AVA);
        $this->like($b, self::AVA);
        $this->assertSame(5, $this->xp(self::BO, 'like'));
    }

    #[Test]
    public function hiding_a_post_takes_back_everything_it_earned_and_restoring_gives_it_again(): void
    {
        $this->app();
        $post = $this->reply(self::BO);
        $this->like($post, self::AVA);
        $this->assertSame(20, (int) $this->user(self::BO)->renown_xp);

        [$status] = $this->api('PATCH', '/posts/'.$post, self::MO, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['isHidden' => true]]]);
        $this->assertSame(200, $status);
        $this->assertSame(5, (int) $this->user(self::BO)->renown_xp, 'only the visit is left');

        [$status] = $this->api('PATCH', '/posts/'.$post, self::MO, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['isHidden' => false]]]);
        $this->assertSame(200, $status);
        $this->assertSame(20, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function excluded_tags_and_own_likes_earn_nothing(): void
    {
        $this->app();
        $post = $this->reply(self::BO);
        $this->like($post, self::BO);
        $this->assertSame(0, $this->xp(self::BO, 'like'));
    }

    #[Test]
    public function hiding_and_restoring_never_earns_more_than_the_post_did(): void
    {
        $this->set('cap_daily', 15);
        $this->app();
        $this->reply(self::BO);            // 5 visit + 10 reply = the cap
        $capped = $this->reply(self::BO);  // earns nothing
        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);

        // The author hides their own capped-out post and brings it back: still nothing.
        foreach ([true, false] as $hidden) {
            [$status] = $this->api('PATCH', '/posts/'.$capped, self::MO, ['data' => ['type' => 'posts', 'id' => (string) $capped, 'attributes' => ['isHidden' => $hidden]]]);
            $this->assertSame(200, $status);
        }
        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function moving_a_discussion_into_an_excluded_tag_takes_its_xp_back_and_out_again_restores_it(): void
    {
        $this->extension('flarum-tags');
        $this->prepareDatabase([
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0],
                ['id' => 2, 'name' => 'Games', 'slug' => 'games', 'position' => 1],
            ],
            'discussion_tag' => [['discussion_id' => 1, 'tag_id' => 1]],
            'group_permission' => [['permission' => 'discussion.tag', 'group_id' => Group::MODERATOR_ID]],
        ]);
        $this->set('exclude_tags', json_encode([2]));
        $this->app();
        $this->reply(self::BO);
        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);

        $tag = fn (int $id) => $this->api('PATCH', '/discussions/1', 1, ['data' => ['type' => 'discussions', 'id' => '1', 'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $id]]]]]]);
        [$status, $body] = $tag(2);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(5, (int) $this->user(self::BO)->renown_xp, 'the reply is out of scope; the visit stays');

        $tag(1);
        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);
    }

    // ---------------------------------------------------------------- prestige

    #[Test]
    public function prestige_is_only_at_level_100_and_resets_the_climb(): void
    {
        $this->app();
        [$status, $body] = $this->api('POST', '/renown/prestige', self::BO);
        $this->assertSame(422, $status);
        $this->assertSame('renown_prestige_not_ready', $body['errors'][0]['code']);

        $this->database()->table('users')->where('id', self::BO)->update(['renown_xp' => 100000, 'renown_lifetime_xp' => 100000, 'renown_level' => 100]);
        [$status, $body] = $this->api('POST', '/renown/prestige', self::BO);
        $this->assertSame(200, $status, json_encode($body));
        $bo = $this->user(self::BO);
        $this->assertSame([1, 0, 1, 100000], [(int) $bo->renown_prestige, (int) $bo->renown_xp, (int) $bo->renown_level, (int) $bo->renown_lifetime_xp]);
        $this->assertSame(1, $this->database()->table('renown_prestiges')->where('user_id', self::BO)->count());

        // Not again until the next Level 100.
        [$status] = $this->api('POST', '/renown/prestige', self::BO);
        $this->assertSame(422, $status);
    }

    #[Test]
    public function after_tier_20_comes_eternal_with_no_reset(): void
    {
        $this->app();
        $this->database()->table('users')->where('id', self::BO)->update(['renown_xp' => 95000, 'renown_lifetime_xp' => 2000000, 'renown_level' => 100, 'renown_prestige' => 20]);
        [$status] = $this->api('POST', '/renown/prestige', self::BO);
        $this->assertSame(200, $status);
        $bo = $this->user(self::BO);
        $this->assertSame([21, 100], [(int) $bo->renown_prestige, (int) $bo->renown_level]);
        $this->assertGreaterThanOrEqual(95000, (int) $bo->renown_xp, 'no reset');

        [$status] = $this->api('POST', '/renown/prestige', self::BO);
        $this->assertSame(422, $status, 'Eternal is the last');
    }

    #[Test]
    public function xp_taken_back_after_a_prestige_lowers_lifetime_only(): void
    {
        $this->app();
        $post = $this->reply(self::BO);
        $this->database()->table('users')->where('id', self::BO)->update(['renown_xp' => 100000, 'renown_lifetime_xp' => 100015, 'renown_level' => 100]);
        $this->api('POST', '/renown/prestige', self::BO);

        $this->api('PATCH', '/posts/'.$post, self::MO, ['data' => ['type' => 'posts', 'id' => (string) $post, 'attributes' => ['isHidden' => true]]]);
        $bo = $this->user(self::BO);
        $this->assertSame([0, 1, 100005], [(int) $bo->renown_xp, (int) $bo->renown_level, (int) $bo->renown_lifetime_xp]);
    }

    #[Test]
    public function eternal_keeps_the_xp_of_the_climb_that_reached_it_when_totals_are_recomputed(): void
    {
        $this->app();
        $this->database()->table(Ledger::TABLE)->insert(['user_id' => self::BO, 'source' => 'reply', 'subject_id' => 777, 'actor_id' => 0, 'xp' => 95000, 'prestige' => 20, 'day' => 1, 'backfilled' => true, 'created_at' => Carbon::now()]);
        $this->database()->table('users')->where('id', self::BO)->update(['renown_xp' => 95000, 'renown_lifetime_xp' => 95000, 'renown_level' => 100, 'renown_prestige' => 20]);
        $this->api('POST', '/renown/prestige', self::BO);

        $this->app()->getContainer()->make(Ledger::class)->recompute([self::BO]);
        $bo = $this->user(self::BO);
        $this->assertSame([21, 100], [(int) $bo->renown_prestige, (int) $bo->renown_level]);
        $this->assertGreaterThanOrEqual(95000, (int) $bo->renown_xp);
    }

    #[Test]
    public function a_rebuild_never_replays_activity_after_install(): void
    {
        $this->set('cap_daily', 15);
        $replay = $this->app()->getContainer()->make(Replay::class);
        // Install: history ends at post 1.
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->delete(Replay::BOUNDARY);
        $replay->boundary();

        $this->reply(self::BO);
        $this->reply(self::BO); // capped out: no log row
        $replay->request(true);
        $replay->run(30);

        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);
        $this->assertSame(25, (int) $this->user(self::AVA)->renown_xp, 'history (before install) still replayed');
    }

    // ---------------------------------------------------------------- replay

    #[Test]
    public function the_replay_is_uncapped_and_never_prestiges_anyone(): void
    {
        $this->set('cap_daily', 20);
        $rows = [];
        foreach (range(2, 1201) as $id) {
            $rows[] = ['id' => $id, 'discussion_id' => 1, 'user_id' => self::CY, 'type' => 'comment', 'number' => $id, 'content' => '<t><p>ok</p></t>', 'created_at' => Carbon::now()->subDays(2), 'is_private' => 0];
        }
        $replay = $this->app()->getContainer()->make(Replay::class);
        foreach (array_chunk($rows, 300) as $chunk) {
            $this->database()->table('posts')->insert($chunk);
        }
        // Install happens now: all of this is history.
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->delete(Replay::BOUNDARY);
        $replay->request();
        $replay->run(30);
        $this->assertFalse($replay->pending());

        // 1,200 short replies on one day: all count (no cap, no minimum length), plus that day's visit.
        $cy = $this->user(self::CY);
        $this->assertSame(12005, (int) $cy->renown_xp);
        $this->assertSame(Curve::levelFor(12005), (int) $cy->renown_level);
        $ava = $this->user(self::AVA);
        $this->assertSame(25, (int) $ava->renown_xp, 'the discussion (20) and its day (5)');

        // Past Level 100: stays there, nobody prestiged.
        $this->database()->table(Ledger::TABLE)->insert(['user_id' => self::CY, 'source' => 'reply', 'subject_id' => 999999, 'actor_id' => 0, 'xp' => 200000, 'prestige' => 0, 'day' => 1, 'backfilled' => true, 'created_at' => Carbon::now()]);
        $replay->requestTotals();
        $replay->run(30);
        $cy = $this->user(self::CY);
        $this->assertSame([100, 0], [(int) $cy->renown_level, (int) $cy->renown_prestige]);

        // Running it again counts nothing twice.
        $replay->request();
        $replay->run(30);
        $this->assertSame(212005, (int) $this->user(self::CY)->renown_xp);
    }

    // ---------------------------------------------------------------- more paths

    #[Test]
    public function hiding_and_restoring_a_discussion_takes_back_and_returns_exactly(): void
    {
        $this->app();
        $post = $this->reply(self::BO);
        $this->like($post, self::AVA);
        $this->assertSame(20, (int) $this->user(self::BO)->renown_xp);

        $hide = fn (bool $hidden) => $this->api('PATCH', '/discussions/1', self::MO, ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['isHidden' => $hidden]]]);
        [$status, $body] = $hide(true);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(5, (int) $this->user(self::BO)->renown_xp, 'the reply and its like go; the visit stays');

        $hide(false);
        $this->assertSame(20, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function a_rebuild_asked_for_during_a_running_replay_is_not_lost(): void
    {
        $container = $this->app()->getContainer();
        $replay = $container->make(Replay::class);
        $replay->request();
        // A slice holds the lock: the admin's Rebuild waits for it, then wins.
        $lock = $container->make('cache')->lock('ernestdefoe-renown.replay-lock', 30);
        $lock->get();
        $replay->run(1);
        $this->assertSame('posts', $replay->state()['phase'], 'no slice ran while the lock was held');
        $lock->release();

        $replay->run(30);
        $this->assertFalse($replay->pending());
        $replay->request(true);
        $this->assertSame('clear', $replay->state()['phase']);
        $replay->run(30);
        $this->assertFalse($replay->pending());
    }

    #[Test]
    public function reward_groups_follow_the_rank_and_permanent_ones_stay(): void
    {
        $this->prepareDatabase([Group::class => [
            ['id' => 10, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0],
            ['id' => 11, 'name_singular' => 'Old hand', 'name_plural' => 'Old hands', 'is_hidden' => 0],
        ]]);
        $ranks = array_fill(0, 20, ['name' => '', 'group' => null, 'permanent' => false]);
        $ranks[1] = ['name' => '', 'group' => 10, 'permanent' => false];
        $ranks[2] = ['name' => '', 'group' => 11, 'permanent' => true];
        $this->set('ranks', json_encode($ranks));
        $c = $this->app()->getContainer();
        $bo = User::query()->find(self::BO);
        $groups = fn () => $this->database()->table('group_user')->where('user_id', self::BO)->whereIn('group_id', [10, 11])->pluck('group_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $c->make(Ledger::class)->adjust($bo, Curve::xpFor(11), User::query()->find(1), 'Rank 3');
        $this->assertSame([10, 11], $groups(), 'ranks 2 and 3 reached');

        $c->make(Ledger::class)->adjust($bo, -Curve::xpFor(11), User::query()->find(1), 'Back to rank 1');
        $this->assertSame([11], $groups(), 'rank 2 group taken back, the permanent one stays');
    }

    #[Test]
    public function best_answers_kudos_and_upvotes_earn_once_and_give_back(): void
    {
        $c = $this->app()->getContainer();
        $activity = $c->make(\ErnestDefoe\Renown\Activity::class);
        $post = Post::query()->find($this->reply(self::BO));
        $bo = User::query()->find(self::BO);

        $activity->bestAnswer($post);
        $activity->bestAnswer($post);
        $this->assertSame(50, $this->xp(self::BO, 'best_answer'));
        $activity->notBestAnswer($post);
        $this->assertSame(0, $this->xp(self::BO, 'best_answer'));

        $activity->kudos($bo, 7, self::AVA);
        $this->assertSame(25, $this->xp(self::BO, 'kudos'));
        $activity->kudosRemoved(7);
        $this->assertSame(0, $this->xp(self::BO, 'kudos'));

        $activity->upvoted($post, self::AVA);
        $activity->upvoted($post, self::BO); // your own vote: nothing
        $this->assertSame(5, $this->xp(self::BO, 'answer_upvote'));
    }

    #[Test]
    public function double_xp_doubles_the_xp_and_the_cap(): void
    {
        $this->prepareDatabase(['renown_boosts' => [
            ['id' => 1, 'title' => 'Launch', 'multiplier' => 2, 'starts_at' => Carbon::now()->subHour(), 'ends_at' => Carbon::now()->addDay(), 'tag_id' => null],
        ]]);
        $this->set('cap_daily', 30);
        $this->app();
        foreach (range(1, 4) as $i) {
            $this->reply(self::BO);
        }
        // Visit 10 + replies 20 each, up to a cap of 60.
        $this->assertSame(60, $this->xp(self::BO));
    }

    #[Test]
    public function the_monthly_board_counts_this_months_xp(): void
    {
        $this->app();
        $this->database()->table(Ledger::TABLE)->insert([
            ['user_id' => self::CY, 'source' => 'reply', 'subject_id' => 1, 'actor_id' => 0, 'xp' => 900, 'prestige' => 0, 'day' => 1, 'backfilled' => true, 'created_at' => Carbon::now()->subMonths(2)],
            ['user_id' => self::BO, 'source' => 'reply', 'subject_id' => 2, 'actor_id' => 0, 'xp' => 40, 'prestige' => 0, 'day' => 1, 'backfilled' => false, 'created_at' => Carbon::now()->subHour()],
        ]);
        [, $body] = $this->api('GET', '/renown/board?period=month', null);
        $this->assertSame([self::BO], array_column($body['data'], 'id'));
        $this->assertSame(40, $body['data'][0]['gained']);
    }

    #[Test]
    public function erasing_a_members_data_removes_their_xp_and_the_groups_renown_gave(): void
    {
        $this->extension('flarum-gdpr');
        $this->prepareDatabase([Group::class => [['id' => 10, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0]]]);
        $ranks = array_fill(0, 20, ['name' => '', 'group' => null, 'permanent' => false]);
        $ranks[0] = ['name' => '', 'group' => 10, 'permanent' => false];
        $this->set('ranks', json_encode($ranks));
        $c = $this->app()->getContainer();
        $this->reply(self::BO);
        $this->database()->table('renown_grants')->insertOrIgnore(['user_id' => self::BO, 'group_id' => 10, 'kind' => 'rank', 'step' => 1]);
        $this->database()->table('group_user')->insertOrIgnore(['user_id' => self::BO, 'group_id' => 10]);

        $c->makeWith(\ErnestDefoe\Renown\Gdpr\RenownData::class, ['user' => User::query()->find(self::BO), 'erasureRequest' => null])->delete();

        $this->assertSame(0, $this->xp(self::BO));
        $this->assertSame(0, (int) $this->user(self::BO)->renown_lifetime_xp);
        $this->assertFalse($this->database()->table('group_user')->where('user_id', self::BO)->where('group_id', 10)->exists());
    }

    #[Test]
    public function an_approved_post_counts_on_its_own_day_without_the_cooldown(): void
    {
        $c = $this->app()->getContainer();
        $this->reply(self::BO);
        $this->set('cooldown', 3600);
        $old = Carbon::now()->subDays(2);
        $id = $this->database()->table('posts')->insertGetId(['discussion_id' => 1, 'user_id' => self::BO, 'type' => 'comment', 'number' => 50, 'content' => '<t><p>Waiting for a moderator since Monday, with plenty of words.</p></t>', 'created_at' => $old, 'is_private' => 0]);
        $c->make(\ErnestDefoe\Renown\Activity::class)->posted(Post::query()->find($id), false);

        $row = $this->database()->table(Ledger::TABLE)->where('subject_id', $id)->where('source', 'reply')->first();
        $this->assertNotNull($row, 'no cooldown for an approval');
        $this->assertSame(Curve::day($old), (int) $row->day);
        $this->assertSame(1, $this->database()->table(Ledger::TABLE)->where('user_id', self::BO)->where('source', 'visit')->count(), 'an approval is not a visit');
    }

    #[Test]
    public function an_icon_upload_is_judged_by_its_content(): void
    {
        $this->app();
        $file = new \Laminas\Diactoros\UploadedFile((new \Laminas\Diactoros\StreamFactory())->createStream('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 80, UPLOAD_ERR_OK, 'icon.png', 'image/png');
        $response = $this->send($this->request('POST', '/api/renown/icons/ranks/1', ['authenticatedAs' => 1])->withUploadedFiles(['icon' => $file]));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('renown_icon_type', (string) $response->getBody());
    }

    #[Test]
    public function making_a_discussion_private_with_byobu_takes_its_xp_back_and_public_again_restores_it(): void
    {
        $this->extension('flarum-tags', 'fof-byobu');
        $this->setting('fof-byobu.makePublic', '1');
        $this->app();
        $this->reply(self::BO);
        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);

        // Byobu makes a discussion public only when asked to (`public`); emptying the recipients otherwise hides it.
        $recipients = fn (array $ids) => $this->api('PATCH', '/discussions/1', 1, ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => $ids ? [] : ['public' => '1'], 'relationships' => [
            'recipientUsers' => ['data' => array_map(fn ($id) => ['type' => 'users', 'id' => (string) $id], $ids)],
        ]]]);
        [$status, $body] = $recipients([self::BO, self::AVA, 1]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertTrue((bool) $this->database()->table('discussions')->where('id', 1)->value('is_private'));
        $this->assertSame(5, (int) $this->user(self::BO)->renown_xp, 'private now: the reply goes, the visit stays');

        [$status, $body] = $recipients([]);
        $this->assertSame(200, $status, substr(json_encode($body), 0, 400));
        $this->assertFalse((bool) $this->database()->table('discussions')->where('id', 1)->value('is_private'));
        $this->assertSame(15, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function approving_the_first_post_of_a_queued_discussion_earns_it(): void
    {
        $this->extension('flarum-flags', 'flarum-approval');
        $this->prepareDatabase([
            Discussion::class => [['id' => 2, 'title' => 'Waiting for a moderator', 'slug' => 'waiting', 'user_id' => self::CY, 'created_at' => Carbon::now()->subDay(), 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => 1, 'is_approved' => 0]],
            Post::class => [['id' => 2, 'discussion_id' => 2, 'user_id' => self::CY, 'type' => 'comment', 'number' => 1, 'content' => '<t><p>My first discussion, held for approval.</p></t>', 'created_at' => Carbon::now()->subDay(), 'is_private' => 1, 'is_approved' => 0]],
        ]);
        $this->app();

        [$status, $body] = $this->api('PATCH', '/posts/2', 1, ['data' => ['type' => 'posts', 'id' => '2', 'attributes' => ['isApproved' => true]]]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(20, $this->xp(self::CY, 'discussion'));
        $this->assertSame(0, $this->xp(self::CY, 'visit'), 'an approval is not the author visiting');
    }

    // ---------------------------------------------------------------- second review

    #[Test]
    public function hiding_a_post_frees_no_room_under_the_daily_cap(): void
    {
        $this->set('cap_daily', 25);
        $this->app();
        // A second discussion to earn in (fixtures added after setUp's are dropped: insert directly).
        $this->database()->table('discussions')->insert(['id' => 3, 'title' => 'Elsewhere', 'slug' => 'elsewhere', 'user_id' => self::AVA, 'created_at' => Carbon::now()->subDay(), 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => 0]);
        $this->database()->table('posts')->insert(['id' => 3, 'discussion_id' => 3, 'user_id' => self::AVA, 'type' => 'comment', 'number' => 1, 'content' => '<t><p>Somewhere else.</p></t>', 'created_at' => Carbon::now()->subDay(), 'is_private' => 0]);
        $this->database()->table('discussions')->where('id', 3)->update(['first_post_id' => 3]);
        $a = $this->reply(self::BO);
        $this->reply(self::BO); // 5 visit + 10 + 10 = the cap of 25

        // Hide your own post, earn elsewhere, restore it.
        $hide = fn (int $id, bool $hidden) => $this->api('PATCH', '/posts/'.$id, self::MO, ['data' => ['type' => 'posts', 'id' => (string) $id, 'attributes' => ['isHidden' => $hidden]]]);
        $hide($a, true);
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        [$status] = $this->api('POST', '/posts', self::BO, ['data' => ['type' => 'posts', 'attributes' => ['content' => 'And here is a long enough reply in another discussion.'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '3']]]]]);
        $this->assertSame(201, $status);
        $hide($a, false);

        $this->assertLessThanOrEqual(25, (int) $this->database()->table(Ledger::TABLE)->where('user_id', self::BO)->where('day', Curve::day())->whereNull('revoked_at')->sum('xp'));
        $this->assertSame(25, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function reset_stops_a_running_replay_for_good(): void
    {
        $replay = $this->app()->getContainer()->make(Replay::class);
        $this->reply(self::BO);
        $replay->request(true);

        [$status, $body] = $this->api('POST', '/renown/admin', 1, ['action' => 'reset']);
        $this->assertSame(200, $status, json_encode($body));
        $replay->run(10);

        $this->assertFalse($replay->pending());
        $this->assertSame(0, $this->database()->table(Ledger::TABLE)->count());
        $this->assertSame(0, (int) $this->user(self::BO)->renown_xp);
    }

    #[Test]
    public function the_replay_lock_is_released_when_a_change_fails(): void
    {
        $replay = $this->app()->getContainer()->make(Replay::class);
        try {
            $replay->locked(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $started = microtime(true);
        $replay->request();
        $this->assertLessThan(2, microtime(true) - $started, 'the lock was free again');
        $this->assertTrue($replay->pending());
    }

    #[Test]
    public function a_group_the_member_already_had_is_never_taken_away(): void
    {
        $this->prepareDatabase([
            Group::class => [['id' => 10, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0]],
            'group_user' => [['user_id' => self::BO, 'group_id' => 10]],
        ]);
        $ranks = array_fill(0, 20, ['name' => '', 'group' => null, 'permanent' => false]);
        $ranks[1] = ['name' => '', 'group' => 10, 'permanent' => false];
        $this->set('ranks', json_encode($ranks));
        $c = $this->app()->getContainer();
        $bo = User::query()->find(self::BO);
        $admin = User::query()->find(1);

        $c->make(Ledger::class)->adjust($bo, Curve::xpFor(6), $admin, 'Rank 2');
        $c->make(Ledger::class)->adjust($bo, -Curve::xpFor(6), $admin, 'Back');
        $this->assertTrue($this->database()->table('group_user')->where('user_id', self::BO)->where('group_id', 10)->exists());
    }

    #[Test]
    public function a_reward_group_an_admin_removed_by_hand_is_not_given_back(): void
    {
        $this->prepareDatabase([Group::class => [['id' => 10, 'name_singular' => 'Regular', 'name_plural' => 'Regulars', 'is_hidden' => 0]]]);
        $ranks = array_fill(0, 20, ['name' => '', 'group' => null, 'permanent' => false]);
        $ranks[1] = ['name' => '', 'group' => 10, 'permanent' => false];
        $this->set('ranks', json_encode($ranks));
        $c = $this->app()->getContainer();
        $bo = User::query()->find(self::BO);
        $admin = User::query()->find(1);
        $c->make(Ledger::class)->adjust($bo, Curve::xpFor(6), $admin, 'Rank 2');
        $this->database()->table('group_user')->where('user_id', self::BO)->where('group_id', 10)->delete();

        $c->make(Ledger::class)->adjust($bo, Curve::xpFor(11), $admin, 'Rank 3');
        $this->assertFalse($this->database()->table('group_user')->where('user_id', self::BO)->where('group_id', 10)->exists());
    }

    #[Test]
    public function staff_cannot_adjust_their_own_xp(): void
    {
        $this->app();
        [$status, $body] = $this->api('POST', '/renown/users/1/adjust', 1, ['xp' => 500, 'reason' => 'Me']);
        $this->assertSame(403, $status);
        $this->assertSame('renown_adjust_self', $body['errors'][0]['code']);
    }

    #[Test]
    public function prestige_and_eternal_hold_through_a_totals_pass_with_real_rows(): void
    {
        $c = $this->app()->getContainer();
        $ledger = $c->make(Ledger::class);
        $bo = User::query()->find(self::BO);
        $admin = User::query()->find(1);

        for ($tier = 1; $tier <= 21; $tier++) {
            $ledger->adjust($bo, Curve::xpFor(100) + 50, $admin, 'Climb '.$tier);
            [$status, $body] = $this->api('POST', '/renown/prestige', self::BO);
            $this->assertSame(200, $status, json_encode($body));
            $bo->refresh();
        }
        $ledger->adjust($bo, 700, $admin, 'Eternal earns on');

        $before = $this->user(self::BO);
        $ledger->recompute([self::BO]);
        $after = $this->user(self::BO);
        $this->assertSame([21, 100], [(int) $after->renown_prestige, (int) $after->renown_level]);
        $this->assertSame((int) $before->renown_xp, (int) $after->renown_xp, 'the totals pass changes nothing');
        $this->assertSame(Curve::xpFor(100) + 50 + 700 + (int) $this->database()->table(Ledger::TABLE)->where('user_id', self::BO)->where('source', 'visit')->where('prestige', 20)->sum('xp'), (int) $after->renown_xp);
        $this->assertSame(21 * (Curve::xpFor(100) + 50) + 700 + $this->xp(self::BO, 'visit'), (int) $after->renown_lifetime_xp);
    }

    #[Test]
    public function an_answers_upvote_earns_through_its_own_hook_and_a_withdrawn_one_gives_it_back(): void
    {
        $this->extension('flarum-tags', 'ernestdefoe-answers');
        $this->prepareDatabase([
            'tags' => [['id' => 1, 'name' => 'Help', 'slug' => 'help', 'position' => 0, 'answers_enabled' => 1]],
            'discussion_tag' => [['discussion_id' => 1, 'tag_id' => 1]],
        ]);
        $c = $this->app()->getContainer();
        $this->database()->table('discussions')->where('id', 1)->update(['answers_question' => 1]);
        $answer = Post::query()->find($this->reply(self::BO));
        $votes = $c->make(\ErnestDefoe\Answers\Votes::class);

        $votes->cast($answer, User::query()->find(self::AVA), 1);
        $this->assertSame(5, $this->xp(self::BO, 'answer_upvote'));

        $votes->cast(Post::query()->find($answer->id), User::query()->find(self::AVA), 0);
        $this->assertSame(0, $this->xp(self::BO, 'answer_upvote'));
    }

    #[Test]
    public function kudos_earn_through_kudos_own_alert_and_a_deleted_one_gives_it_back(): void
    {
        $this->extension('ernestdefoe-kudos');
        $this->prepareDatabase(['group_permission' => [
            ['permission' => 'kudos.view', 'group_id' => Group::MEMBER_ID],
            ['permission' => 'kudos.give', 'group_id' => Group::MEMBER_ID],
        ]]);
        $this->app();
        [$status, $body] = $this->api('POST', '/kudos', self::AVA, ['receiver' => self::BO, 'note' => 'Thanks for walking me through the migration!', 'style' => 'meadow']);
        $this->assertSame(201, $status, json_encode($body));
        $this->assertSame(25, $this->xp(self::BO, 'kudos'));

        [$status] = $this->api('DELETE', '/kudos/'.$body['card']['id'], 1);
        $this->assertContains($status, [200, 204]);
        $this->assertSame(0, $this->xp(self::BO, 'kudos'));
    }

    #[Test]
    public function an_icon_claiming_huge_dimensions_is_refused_before_decoding(): void
    {
        $this->app();
        // A real PNG header that says 50,000 × 50,000.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 50000, 50000)."\x08\x06\x00\x00\x00".pack('N', 0);
        $file = new \Laminas\Diactoros\UploadedFile((new \Laminas\Diactoros\StreamFactory())->createStream($png), strlen($png), UPLOAD_ERR_OK, 'big.png', 'image/png');
        $response = $this->send($this->request('POST', '/api/renown/icons/ranks/1', ['authenticatedAs' => 1])->withUploadedFiles(['icon' => $file]));
        $this->assertSame(422, $response->getStatusCode());
    }

    // ---------------------------------------------------------------- board, profile, staff

    #[Test]
    public function the_board_leaves_out_members_who_opted_out(): void
    {
        $this->app();
        $this->database()->table('users')->where('id', self::BO)->update(['renown_xp' => 500, 'renown_lifetime_xp' => 500, 'renown_level' => 5]);
        $this->database()->table('users')->where('id', self::CY)->update(['renown_xp' => 900, 'renown_lifetime_xp' => 900, 'renown_level' => 7]);

        [$status, $body] = $this->api('GET', '/renown/board', null);
        $this->assertSame(200, $status);
        $this->assertSame([self::CY, self::BO], array_column($body['data'], 'id'));

        $this->api('PATCH', '/renown/me', self::CY, ['hidden' => true]);
        [, $body] = $this->api('GET', '/renown/board', null);
        $this->assertSame([self::BO], array_column($body['data'], 'id'));
        $this->assertSame(1, $body['data'][0]['position']);
    }

    #[Test]
    public function the_profile_shows_sources_and_only_the_member_may_prestige(): void
    {
        $this->app();
        $this->reply(self::BO);
        [$status, $body] = $this->api('GET', '/renown/users/'.self::BO, self::AVA);
        $this->assertSame(200, $status);
        $this->assertSame(['reply' => 10, 'visit' => 5], $body['data']['sources']);
        $this->assertFalse($body['data']['canPrestige']);
        $this->assertNull($body['data']['hidden'], 'only the member sees their own setting');
        $this->assertSame(1, $body['data']['position']);
    }

    #[Test]
    public function only_staff_with_the_permission_adjust_xp_and_it_never_goes_below_zero(): void
    {
        $this->app();
        [$status] = $this->api('POST', '/renown/users/'.self::BO.'/adjust', self::AVA, ['xp' => 500, 'reason' => 'Event winner']);
        $this->assertSame(403, $status);

        [$status, $body] = $this->api('POST', '/renown/users/'.self::BO.'/adjust', 1, ['xp' => 500, 'reason' => 'Event winner']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(500, (int) $this->user(self::BO)->renown_xp);

        $this->api('POST', '/renown/users/'.self::BO.'/adjust', 1, ['xp' => -9000, 'reason' => 'Correction']);
        $this->assertSame(0, (int) $this->user(self::BO)->renown_xp);
        $this->assertSame(0, $this->xp(self::BO));
    }

    #[Test]
    public function the_user_payload_carries_the_rank_and_seen_only_for_yourself(): void
    {
        $this->app();
        $this->database()->table('users')->where('id', self::BO)->update(['renown_level' => 42, 'renown_prestige' => 3, 'renown_seen' => 3042]);

        [, $body] = $this->api('GET', '/users/'.self::BO, self::AVA);
        $this->assertSame(42, $body['data']['attributes']['renownLevel']);
        $this->assertSame(3, $body['data']['attributes']['renownPrestige']);
        $this->assertArrayNotHasKey('renownSeen', $body['data']['attributes']);

        [, $body] = $this->api('GET', '/users/'.self::BO, self::BO);
        $this->assertSame(3042, $body['data']['attributes']['renownSeen']);
    }
}
