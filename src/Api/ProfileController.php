<?php

namespace ErnestDefoe\Renown\Api;

use Carbon\Carbon;
use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Prestige;
use ErnestDefoe\Renown\Refusal;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/renown/users/{id}: the Renown tab. Where the member stands, XP
 * this week, their place on the board, the emblems they've earned and where
 * their XP came from in the last 30 days. Four indexed queries.
 */
class ProfileController extends Handler
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $user = User::query()->whereVisibleTo($actor)->find((int) self::route($request, 'id'));
        if (! $user) {
            throw new Refusal('renown_not_found', 404);
        }

        $id = (int) $user->id;
        $prestige = (int) $user->renown_prestige;
        $xp = (int) $user->renown_xp;

        $week = (int) $this->db->table(Ledger::TABLE)->where('user_id', $id)->where('created_at', '>=', Carbon::now()->subDays(7))->sum('xp');

        $position = $user->renown_hidden || ! $user->renown_lifetime_xp ? null : 1 + $this->db->table('users')
            ->where('renown_hidden', false)
            ->where(fn ($q) => $q->where('renown_prestige', '>', $prestige)
                ->orWhere(fn ($q) => $q->where('renown_prestige', $prestige)->where('renown_xp', '>', $xp))
                ->orWhere(fn ($q) => $q->where('renown_prestige', $prestige)->where('renown_xp', $xp)->where('id', '<', $id)))
            ->count();

        $emblems = $this->db->table('renown_prestiges')->where('user_id', $id)->orderBy('tier')->get(['tier', 'reached_at'])
            ->map(fn ($row) => ['tier' => (int) $row->tier, 'reachedAt' => Carbon::parse($row->reached_at)->toIso8601String()])->all();

        $sources = $this->db->table(Ledger::TABLE)->where('user_id', $id)->where('created_at', '>=', Carbon::now()->subDays(30))
            ->groupBy('source')->selectRaw('source, SUM(xp) as xp')->orderByDesc('xp')->pluck('xp', 'source')
            ->map(fn ($xp) => (int) $xp)->filter(fn ($xp) => $xp > 0)->all();

        $self = (int) $actor->id === $id;

        return new JsonResponse(['data' => Presenter::user($user) + [
            'week' => $week,
            'position' => $position,
            'emblems' => $emblems,
            'sources' => $sources,
            'canPrestige' => $self && Prestige::canTake($user),
            'hidden' => $self ? (bool) $user->renown_hidden : null,
            'canAdjust' => $actor->can('renown.adjust'),
        ]]);
    }
}
