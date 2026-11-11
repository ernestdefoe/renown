<?php

namespace ErnestDefoe\Renown;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * The history replay: members start with the XP their past activity earned.
 *
 * No caps, cooldowns, like limits or minimum length here (those are for
 * activity after install). What never counted still doesn't: hidden or
 * unapproved posts, private discussions, excluded tags, likes on your own
 * posts. Visits are the days a member posted. Nobody is given a Prestige:
 * history worth more than Level 100 leaves the member at Immortal V with the
 * rest banked, Prestige ready to take.
 *
 * It walks in slices of about a second (posts by id, then kudos, Cadence
 * streaks, then each member's totals), so no request ever does it all: the
 * scheduler, the admin page and `php flarum renown:rebuild` each take slices
 * until it's done. Every row is insert-or-ignore on the log's unique key, so
 * a slice run twice, or live XP earned meanwhile, is never counted twice.
 */
class Replay
{
    public const KEY = 'ernestdefoe-renown.replay';

    /** Where history ends and live activity begins: set at install, never moved. */
    public const BOUNDARY = 'ernestdefoe-renown.boundary';

    private const PHASES = ['posts', 'kudos', 'streaks', 'totals'];

    /** How much of the bar each phase fills. */
    private const WEIGHTS = ['posts' => 70, 'kudos' => 5, 'streaks' => 5, 'totals' => 20];

    /** @var array<string, bool> */
    private array $columns = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settingsRepo,
        private readonly Settings $settings,
        private readonly Ledger $ledger,
        private readonly Rewards $rewards,
        private readonly ExtensionManager $extensions,
        private readonly Cache $cache,
    ) {
    }

    /** @return array{phase: string, cursor: int, span?: int, max?: int, started_at?: string, finished_at?: string}|null */
    public function state(): ?array
    {
        $state = json_decode((string) $this->settingsRepo->get(self::KEY), true);

        return is_array($state) && isset($state['phase']) ? $state : null;
    }

    public function pending(): bool
    {
        $state = $this->state();

        return $state !== null && $state['phase'] !== 'done';
    }

    /** 0–100 */
    public function progress(): int
    {
        $state = $this->state();
        if (! $state) {
            return 0;
        }
        if ($state['phase'] === 'done') {
            return 100;
        }

        $done = 0;
        foreach (self::PHASES as $phase) {
            if ($phase === $state['phase']) {
                $max = max(1, (int) ($state['max'] ?? 0));

                return (int) min(99, $done + self::WEIGHTS[$phase] * min(1, $state['cursor'] / $max));
            }
            $done += self::WEIGHTS[$phase];
        }

        return 0;
    }

    /**
     * Start again from the beginning. Fresh: the rows earlier replays wrote go
     * first, so changed XP values apply to history too (live XP stays).
     */
    public function request(bool $fresh = false): void
    {
        if ($fresh) {
            // The Cadence lump stays: Cadence counts days, not each day, so it can't be told apart again.
            $this->db->table(Ledger::TABLE)->where('backfilled', true)->where(fn ($q) => $q->where('source', '<>', 'streak')->orWhere('subject_id', '>', 0))->delete();
        }
        $this->save(['phase' => 'posts', 'cursor' => 0, 'started_at' => Carbon::now()->toIso8601String()]);
    }

    /** Only the totals and group rewards again (after rewards change). */
    public function requestTotals(): void
    {
        if (! $this->pending()) {
            $this->save(['phase' => 'totals', 'cursor' => 0, 'started_at' => Carbon::now()->toIso8601String()]);
        }
    }

    /** Work for about $seconds, then stop where it got to. */
    public function run(float $seconds): void
    {
        $lock = $this->cache->lock('ernestdefoe-renown.replay-lock', 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $until = microtime(true) + $seconds;
            while (($state = $this->state()) && $state['phase'] !== 'done' && microtime(true) < $until) {
                $this->save($this->step($state));
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * One slice. The slice grows while it's quick and shrinks when it's slow,
     * so a forum with half a million posts and one with fifty both take
     * about a second a slice.
     *
     * @param array{phase: string, cursor: int, span?: int, max?: int} $state
     * @return array{phase: string, cursor: int, span?: int, max?: int}
     */
    private function step(array $state): array
    {
        $phase = $state['phase'];
        if (! isset($state['max'])) {
            $state['max'] = $this->max($phase);
            $state['span'] = $phase === 'totals' ? 200 : 2000;
        }

        $from = (int) $state['cursor'];
        // Never past the boundary: the last slice stops at it.
        $to = min($from + (int) $state['span'], (int) $state['max']);
        $started = microtime(true);

        if ($from < $state['max']) {
            match ($phase) {
                'posts' => $this->posts($from, $to),
                'kudos' => $this->kudos($from, $to),
                'streaks' => $this->streaks($from, $to),
                default => $this->totals($from, $to),
            };
        }

        $took = microtime(true) - $started;
        $span = (int) $state['span'];
        $span = $took < 0.3 ? min($span * 2, 50000) : ($took > 1.5 ? max(50, intdiv($span, 2)) : $span);

        if ($to >= $state['max']) {
            $next = self::PHASES[array_search($phase, self::PHASES, true) + 1] ?? null;

            return $next
                ? ['phase' => $next, 'cursor' => 0, 'started_at' => $state['started_at'] ?? null]
                : ['phase' => 'done', 'cursor' => 0, 'started_at' => $state['started_at'] ?? null, 'finished_at' => Carbon::now()->toIso8601String()];
        }

        return ['cursor' => $to, 'span' => $span] + $state;
    }

    private function max(string $phase): int
    {
        $boundary = $this->boundary();

        return (int) match ($phase) {
            'posts' => $boundary['posts'],
            'kudos' => $boundary['kudos'],
            default => $this->db->table('users')->max('id'),
        };
    }

    /**
     * The last post and kudos, and the moment, history ends. Everything after
     * it is live activity that already went through the day's rules, so no
     * replay (a rebuild included) may count it again.
     *
     * @return array{posts: int, kudos: int, at: string}
     */
    public function boundary(): array
    {
        $saved = json_decode((string) $this->settingsRepo->get(self::BOUNDARY), true);
        if (is_array($saved) && isset($saved['posts'], $saved['kudos'], $saved['at'])) {
            return $saved;
        }

        $boundary = self::measure($this->db, $this->extensions->isEnabled('ernestdefoe-kudos'));
        $this->settingsRepo->set(self::BOUNDARY, json_encode($boundary));

        return $boundary;
    }

    /** @return array{posts: int, kudos: int, at: string} */
    public static function measure(ConnectionInterface $db, bool $kudos): array
    {
        return [
            'posts' => (int) $db->table('posts')->max('id'),
            'kudos' => $kudos && $db->getSchemaBuilder()->hasTable('kudos') ? (int) $db->table('kudos')->max('id') : 0,
            'at' => Carbon::now()->toDateTimeString(),
        ];
    }

    /** Posts and what they earned: replies, discussions, likes, best answers, Answers upvotes, and the days posted. */
    private function posts(int $from, int $to): void
    {
        $rows = [];
        $visits = [];
        $xp = fn (string $source) => $this->settings->xp($source);

        foreach ($this->eligible($from, $to)->get(['posts.id', 'posts.user_id', 'posts.number', 'posts.created_at', 'discussions.first_post_id']) as $post) {
            $first = (int) $post->id === (int) $post->first_post_id || (int) $post->number === 1;
            $source = $first ? 'discussion' : 'reply';
            $rows[] = $this->row((int) $post->user_id, $source, (int) $post->id, 0, $xp($source), $post->created_at);
            $day = Curve::day(Carbon::parse($post->created_at));
            $visits[$post->user_id.':'.$day] = $this->row((int) $post->user_id, 'visit', $day, 0, $xp('visit'), $post->created_at);
        }

        if ($this->extensions->isEnabled('flarum-likes') && $xp('like') > 0) {
            $likes = $this->eligible($from, $to)
                ->join('post_likes', 'post_likes.post_id', '=', 'posts.id')
                ->whereColumn('post_likes.user_id', '<>', 'posts.user_id')
                ->where(fn ($q) => $q->whereNull('post_likes.created_at')->orWhere('post_likes.created_at', '<=', $this->boundary()['at']))
                ->get(['posts.id', 'posts.user_id', 'posts.created_at', 'post_likes.user_id as liker', 'post_likes.created_at as liked_at']);
            foreach ($likes as $like) {
                $rows[] = $this->row((int) $like->user_id, 'like', (int) $like->id, (int) $like->liker, $xp('like'), $like->liked_at ?? $like->created_at);
            }
        }

        if ($this->extensions->isEnabled('ernestdefoe-answers') && $xp('answer_upvote') > 0) {
            $votes = $this->eligible($from, $to)
                ->join('answers_votes', 'answers_votes.post_id', '=', 'posts.id')
                ->where('answers_votes.value', '>', 0)
                ->whereColumn('answers_votes.user_id', '<>', 'posts.user_id')
                ->where('answers_votes.created_at', '<=', $this->boundary()['at'])
                ->get(['posts.id', 'posts.user_id', 'answers_votes.user_id as voter', 'answers_votes.created_at']);
            foreach ($votes as $vote) {
                $rows[] = $this->row((int) $vote->user_id, 'answer_upvote', (int) $vote->id, (int) $vote->voter, $xp('answer_upvote'), $vote->created_at);
            }
        }

        if ($xp('best_answer') > 0) {
            $columns = [];
            if ($this->extensions->isEnabled('fof-best-answer') && $this->hasColumn('discussions', 'best_answer_post_id')) {
                $columns[] = ['discussions.best_answer_post_id', 'discussions.best_answer_set_at'];
            }
            if ($this->extensions->isEnabled('ernestdefoe-answers') && $this->hasColumn('discussions', 'answers_accepted_post_id')) {
                $columns[] = ['discussions.answers_accepted_post_id', null];
            }
            foreach ($columns as [$column, $when]) {
                $best = $this->eligible($from, $to)->whereColumn('posts.id', $column)
                    ->when($when, fn ($q) => $q->where(fn ($q) => $q->whereNull($when)->orWhere($when, '<=', $this->boundary()['at'])))
                    ->get(array_filter(['posts.id', 'posts.user_id', 'posts.created_at', $when ? $when.' as set_at' : null]));
                foreach ($best as $post) {
                    $rows[] = $this->row((int) $post->user_id, 'best_answer', (int) $post->id, 0, $xp('best_answer'), $post->set_at ?? $post->created_at);
                }
            }
        }

        if ($xp('visit') > 0) {
            array_push($rows, ...array_values($visits));
        }

        $this->insert($rows);
    }

    private function kudos(int $from, int $to): void
    {
        if ($this->settings->xp('kudos') <= 0 || ! $this->extensions->isEnabled('ernestdefoe-kudos')) {
            return;
        }

        $rows = [];
        foreach ($this->db->table('kudos')->where('id', '>', $from)->where('id', '<=', $to)->where('status', 'approved')->get(['id', 'receiver_id', 'giver_id', 'created_at', 'approved_at']) as $kudos) {
            $rows[] = $this->row((int) $kudos->receiver_id, 'kudos', (int) $kudos->id, (int) $kudos->giver_id, $this->settings->xp('kudos'), $kudos->approved_at ?? $kudos->created_at);
        }
        $this->insert($rows);
    }

    /** Cadence keeps a count of days fed, not each day: one row per member for the days not already counted live. */
    private function streaks(int $from, int $to): void
    {
        $xp = $this->settings->xp('streak');
        if ($xp <= 0 || ! $this->extensions->isEnabled('ernestdefoe-cadence')) {
            return;
        }

        $streaks = $this->db->table('cadence_streaks')->where('user_id', '>', $from)->where('user_id', '<=', $to)->where('total_days', '>', 0)->pluck('total_days', 'user_id');
        // One lump for days spread over the member's history: dated when they joined, so it never counts as "this week".
        $joined = $this->db->table('users')->whereIn('id', $streaks->keys()->all())->pluck('joined_at', 'id');
        if ($streaks->isEmpty()) {
            return;
        }
        $live = $this->db->table(Ledger::TABLE)->where('source', 'streak')->where('subject_id', '>', 0)->whereIn('user_id', $streaks->keys()->all())
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) as days')->pluck('days', 'user_id');

        $rows = [];
        foreach ($streaks as $userId => $days) {
            $days = (int) $days - (int) ($live[$userId] ?? 0);
            if ($days > 0) {
                $rows[] = $this->row((int) $userId, 'streak', 0, 0, $days * $xp, $joined[$userId] ?? null);
            }
        }
        $this->insert($rows);
    }

    /** Each member's totals from their rows, then their group rewards. */
    private function totals(int $from, int $to): void
    {
        $ids = $this->db->table('users')->where('id', '>', $from)->where('id', '<=', $to)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->ledger->recompute($ids);

        if ($ids && $this->rewards->configured()) {
            foreach (User::query()->whereIn('id', $ids)->get() as $user) {
                $this->rewards->sync($user);
            }
        }
    }

    /** Comments that can earn XP, with ids in ($from, $to]. */
    private function eligible(int $from, int $to): Builder
    {
        $query = $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('posts.id', '>', $from)->where('posts.id', '<=', $to)
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->whereNotNull('posts.user_id')
            ->whereNull('discussions.hidden_at');

        if ($this->hasColumn('posts', 'is_approved')) {
            $query->where('posts.is_approved', true)->where('discussions.is_approved', true);
        }
        if ($this->hasColumn('discussions', 'is_private')) {
            $query->where('discussions.is_private', false);
        }
        $excluded = $this->settings->excludedTags();
        if ($excluded && $this->extensions->isEnabled('flarum-tags')) {
            $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('discussion_tag')
                ->whereColumn('discussion_tag.discussion_id', 'discussions.id')
                ->whereIn('discussion_tag.tag_id', $excluded));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(int $userId, string $source, int $subject, int $actor, int $xp, mixed $at): array
    {
        $at = $at ? Carbon::parse($at) : Carbon::now();

        return [
            'user_id' => $userId,
            'source' => $source,
            'subject_id' => $subject,
            'actor_id' => $actor,
            'xp' => $xp,
            'prestige' => 0,
            'day' => Curve::day($at),
            'backfilled' => true,
            'created_at' => $at,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(array $rows): void
    {
        $rows = array_filter($rows, fn ($row) => $row['xp'] > 0);
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table(Ledger::TABLE)->insertOrIgnore($chunk);
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns[$table.'.'.$column] ??= $this->db->getSchemaBuilder()->hasColumn($table, $column);
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        $this->settingsRepo->set(self::KEY, json_encode(array_filter($state, fn ($v) => $v !== null)));
    }
}
