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
