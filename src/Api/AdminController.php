<?php

namespace ErnestDefoe\Renown\Api;

use ErnestDefoe\Renown\Boosts;
use ErnestDefoe\Renown\Curve;
use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Refusal;
use ErnestDefoe\Renown\Replay;
use ErnestDefoe\Renown\Rewards;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admins only.
 * GET /api/renown/admin: the replay's progress and how members spread over the ranks.
 * POST /api/renown/admin {action}: `run` (a second of the replay: the admin page
 * polls this while it's open, so forums without cron finish too), `rebuild`
 * (replay history again with today's XP values) or `reset` (everyone to zero).
 */
class AdminController extends Handler
{
    public function __construct(
        private readonly Replay $replay,
        private readonly Rewards $rewards,
        private readonly Boosts $boosts,
        private readonly ConnectionInterface $db,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertAdmin();

        if ($request->getMethod() === 'POST') {
            match ((string) (self::body($request)['action'] ?? '')) {
                'run' => $this->replay->run(1.5),
                'rebuild' => $this->replay->request(true),
                'reset' => $this->reset(),
                default => throw new Refusal('renown_unknown_action'),
            };
        }

        $levels = $this->db->table('users')->where('renown_lifetime_xp', '>', 0)->groupBy('renown_level')
            ->selectRaw('renown_level as level, COUNT(*) as members')->pluck('members', 'level');
        $ranks = array_fill(1, Curve::RANKS, 0);
        foreach ($levels as $level => $members) {
            $ranks[Curve::rank((int) $level)] += (int) $members;
        }

        $state = $this->replay->state();

        return new JsonResponse(['data' => [
            'replay' => $state ? ['phase' => $state['phase'], 'progress' => $this->replay->progress(), 'finishedAt' => $state['finished_at'] ?? null] : null,
            'ranks' => array_values($ranks),
            'members' => (int) $this->db->table('users')->where('renown_lifetime_xp', '>', 0)->count(),
            'prestiged' => (int) $this->db->table('users')->where('renown_prestige', '>', 0)->count(),
            'ready' => (int) $this->db->table('users')->where('renown_level', Curve::MAX_LEVEL)->where('renown_prestige', '<', Curve::ETERNAL)->count(),
            'boosts' => $this->boosts->upcoming(),
        ]]);
    }

    /**
     * Everyone back to Level 1 with nothing earned. History is NOT replayed
     * (Rebuild does that). Under the replay's lock, so a running replay can't
     * write over it, and the "done" state goes through the settings
     * repository, so caches (fof/redis) see it.
     */
    private function reset(): void
    {
        $this->replay->locked(function () {
            $this->rewards->revokeAll();
            $this->db->table(Ledger::TABLE)->delete();
            $this->db->table('renown_prestiges')->delete();
            $this->db->table('users')->update([
                'renown_xp' => 0, 'renown_lifetime_xp' => 0, 'renown_level' => 1, 'renown_prestige' => 0,
                'renown_seen' => 0, 'renown_streak_day' => 0,
            ]);
            $this->replay->stop();
        });
    }
}
