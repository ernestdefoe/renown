<?php

namespace ErnestDefoe\Renown\Api;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use ErnestDefoe\Renown\Ledger;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/renown/board?period=all|prestiged|month|week&page=1
 *
 * All time: prestige, then XP this climb (index on users); Prestiged: the
 * same, prestiged members only. This month / this week: XP earned since the
 * 1st / Monday (UTC), the top 200 cached five minutes. Only members the
 * viewer can see, never anyone who left the board.
 *
 * Every row carries its XP this week, read from that same cached top 200.
 * Page 1 adds the week's three risers (except on the week board, whose podium
 * they are) and, for a signed-in member, where they stand: one count query on
 * the all-time boards, free on the others.
 */
class BoardController extends Handler
{
    public const PER_PAGE = 25;

    public const PERIODS = ['all', 'prestiged', 'month', 'week'];

    /** Bumped when a member leaves or rejoins the board, so guests never see them a minute late. */
    public const VERSION = 'ernestdefoe-renown.board.version';

    public function __construct(private readonly ConnectionInterface $db, private readonly Cache $cache)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $page = max(1, min(40, (int) self::query($request, 'page')));
        $offset = ($page - 1) * self::PER_PAGE;
        $period = in_array($p = self::query($request, 'period'), self::PERIODS, true) ? $p : 'all';
        $week = $this->gains(Carbon::now('UTC')->startOfWeek(CarbonInterface::MONDAY), 'week');
        $me = $page === 1 && ! $actor->isGuest() && ! $actor->getAttribute('renown_hidden') ? Presenter::user($actor) : null;

        if ($period === 'month' || $period === 'week') {
            $top = $period === 'week' ? $week : $this->gains(Carbon::now('UTC')->startOfMonth(), 'month');
            $users = User::query()->whereVisibleTo($actor)->whereIn('id', array_keys($top))->where('renown_hidden', false)->get()->keyBy('id');
            $board = [];
            foreach ($top as $id => $gained) {
                if ($user = $users->get($id)) {
                    $board[] = Presenter::user($user) + ['gained' => $gained];
                }
            }
            $slice = array_slice($board, $offset, self::PER_PAGE + 1);

            if ($me) {
                // The whole board is in hand: where the member stands costs nothing.
                $at = array_search($me['id'], array_column($board, 'id'), true);
                $me += ['gained' => $top[$me['id']] ?? 0, 'position' => $at === false ? null : $at + 1, 'gap' => null];
                if ($at) {
                    $above = $board[$at - 1];
                    $me['gap'] = ['xp' => $above['gained'] - $me['gained'] + 1, 'position' => $at, 'name' => $above['displayName']];
                }
            }
        } else {
            // All one direction, so the (prestige, XP) index (which carries the id) serves the whole
            // order. Guests all see the same board: cached a minute.
            $slice = $this->forGuests($actor, $period.'.'.$page, fn () => User::query()->whereVisibleTo($actor)
                ->where('renown_hidden', false)
                ->where('renown_lifetime_xp', '>', 0)
                ->when($period === 'prestiged', fn ($q) => $q->where('renown_prestige', '>', 0))
                ->orderByDesc('renown_prestige')->orderByDesc('renown_xp')->orderByDesc('id')
                ->offset($offset)->limit(self::PER_PAGE + 1)
                ->get()->map(fn (User $user) => Presenter::user($user))->all());

            if ($me) {
                $me += $this->standing($actor, $me, $period, array_column(array_slice($slice, 0, self::PER_PAGE), 'id'));
            }
        }

        $more = count($slice) > self::PER_PAGE;
        $rows = array_slice($slice, 0, self::PER_PAGE);
        foreach ($rows as $i => &$row) {
            $row['position'] = $offset + $i + 1;
            $row['week'] = $week[$row['id']] ?? 0;
        }
        unset($row);

        if ($me) {
            $me['week'] = $week[$me['id']] ?? 0;
        }

        return new JsonResponse([
            'data' => $rows,
            'more' => $more,
            'rising' => $page === 1 && $period !== 'week' ? $this->rising($actor, $week, $rows) : [],
            'me' => $me,
        ]);
    }

    /**
     * Where a member stands on the all-time boards: how many visible members
     * are ahead, and the XP of the nearest one in the same prestige (one
     * aggregate query, skipped when they're on this page or off the board).
     *
     * @param array<string, mixed> $me
     * @param list<int> $onPage
     * @return array<string, mixed>
     */
    private function standing(User $actor, array $me, string $period, array $onPage): array
    {
        if (in_array($me['id'], $onPage, true) || ! $me['lifetime'] || ($period === 'prestiged' && ! $me['prestige'])) {
            return ['position' => null, 'gap' => null];
        }

        [$prestige, $xp, $id] = [$me['prestige'], $me['xp'], $me['id']];
        $grammar = $this->db->getQueryGrammar();
        $row = User::query()->whereVisibleTo($actor)
            ->where('renown_hidden', false)
            ->where('renown_lifetime_xp', '>', 0)
            ->where(fn ($q) => $q->where('renown_prestige', '>', $prestige)
                ->orWhere(fn ($q) => $q->where('renown_prestige', $prestige)->where('renown_xp', '>', $xp))
                ->orWhere(fn ($q) => $q->where('renown_prestige', $prestige)->where('renown_xp', $xp)->where('id', '>', $id)))
            ->toBase()
            ->selectRaw('COUNT(*) as ahead, MIN(CASE WHEN '.$grammar->wrap('renown_prestige').' = ? THEN '.$grammar->wrap('renown_xp').' END) as nearest', [$prestige])
            ->first();

        $ahead = (int) ($row->ahead ?? 0);
        $nearest = $row->nearest ?? null;

        return [
            'position' => $ahead + 1,
            // Only someone in the same prestige can be passed with XP; past them it's the next Prestige.
            'gap' => $ahead && $nearest !== null ? ['xp' => max(1, (int) $nearest - $xp + 1), 'position' => $ahead, 'name' => null] : null,
        ];
    }

    /**
     * The week's three biggest earners the viewer can see. Most weeks they're
     * on the page already; only the rest are looked up (one query, or none).
     *
     * @param array<int, int> $week
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rising(User $actor, array $week, array $rows): array
    {
        $ids = array_slice(array_keys($week), 0, 10);
        $shown = array_column($rows, null, 'id');
        $missing = array_values(array_diff(array_slice($ids, 0, 3), array_keys($shown))) ? array_diff($ids, array_keys($shown)) : [];

        $found = ! $missing ? [] : $this->forGuests($actor, 'rising.'.implode(',', $missing), fn () => User::query()->whereVisibleTo($actor)
            ->whereIn('id', $missing)->where('renown_hidden', false)->get()
            ->mapWithKeys(fn (User $user) => [$user->id => Presenter::user($user)])->all());

        $rising = [];
        foreach ($ids as $id) {
            if (($row = $shown[$id] ?? $found[$id] ?? null) && count($rising) < 3) {
                $rising[] = array_diff_key($row, ['position' => 0, 'gained' => 0]) + ['week' => $week[$id]];
            }
        }

        return $rising;
    }

    /**
     * XP earned since a moment, the top 200, cached five minutes.
     *
     * @return array<int, int>
     */
    private function gains(Carbon $since, string $name): array
    {
        return $this->cache->remember('ernestdefoe-renown.board.'.$name.'.'.$since->format('Ymd'), 300, fn () => $this->db->table(Ledger::TABLE)
            ->where('created_at', '>=', $since)
            ->whereNull('revoked_at')
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(xp) as gained')
            ->havingRaw('SUM(xp) > 0')
            ->orderByDesc('gained')
            ->limit(200)
            ->pluck('gained', 'user_id')
            ->map(fn ($xp) => (int) $xp)
            ->all());
    }

    /**
     * Guests all see the same thing: cached a minute, and dropped when someone leaves or rejoins the board.
     *
     * @template T
     * @param \Closure(): T $build
     * @return T
     */
    private function forGuests(User $actor, string $key, \Closure $build): mixed
    {
        if (! $actor->isGuest()) {
            return $build();
        }

        $version = (int) $this->cache->get(self::VERSION, 0);

        return $this->cache->remember('ernestdefoe-renown.board.guest.'.$version.'.'.$key, 60, $build);
    }
}
