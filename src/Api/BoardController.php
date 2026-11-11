<?php

namespace ErnestDefoe\Renown\Api;

use Carbon\Carbon;
use ErnestDefoe\Renown\Ledger;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/renown/board?period=all|month&page=1
 *
 * All time: prestige, then XP this climb (index on users). This month: XP
 * earned since the 1st (UTC), the top 200 cached five minutes. Only members
 * the viewer can see, never anyone who left the board.
 */
class BoardController extends Handler
{
    public const PER_PAGE = 25;

    public function __construct(private readonly ConnectionInterface $db, private readonly Cache $cache)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $page = max(1, min(40, (int) self::query($request, 'page')));
        $offset = ($page - 1) * self::PER_PAGE;

        if (self::query($request, 'period') === 'month') {
            $month = Carbon::now('UTC')->startOfMonth();
            $top = $this->cache->remember('ernestdefoe-renown.board.'.$month->format('Ym'), 300, fn () => $this->db->table(Ledger::TABLE)
                ->where('created_at', '>=', $month)
                ->whereNull('revoked_at')
                ->groupBy('user_id')
                ->selectRaw('user_id, SUM(xp) as gained')
                ->havingRaw('SUM(xp) > 0')
                ->orderByDesc('gained')
                ->limit(200)
                ->pluck('gained', 'user_id')
                ->map(fn ($xp) => (int) $xp)
                ->all());

            $users = User::query()->whereVisibleTo($actor)->whereIn('id', array_keys($top))->where('renown_hidden', false)->get()->keyBy('id');
            $rows = [];
            foreach ($top as $id => $gained) {
                if ($user = $users->get($id)) {
                    $rows[] = Presenter::user($user) + ['gained' => $gained];
                }
            }
            $slice = array_slice($rows, $offset, self::PER_PAGE + 1);
        } else {
            $slice = User::query()->whereVisibleTo($actor)
                ->where('renown_hidden', false)
                ->where('renown_lifetime_xp', '>', 0)
                ->orderByDesc('renown_prestige')->orderByDesc('renown_xp')->orderBy('id')
                ->offset($offset)->limit(self::PER_PAGE + 1)
                ->get()->map(fn (User $user) => Presenter::user($user))->all();
        }

        $more = count($slice) > self::PER_PAGE;
        $rows = array_slice($slice, 0, self::PER_PAGE);
        foreach ($rows as $i => &$row) {
            $row['position'] = $offset + $i + 1;
        }

        return new JsonResponse(['data' => $rows, 'more' => $more]);
    }
}
