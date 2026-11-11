<?php

namespace ErnestDefoe\Renown\Api;

use Carbon\Carbon;
use ErnestDefoe\Renown\Boosts;
use ErnestDefoe\Renown\Refusal;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admins schedule Double XP events.
 * POST /api/renown/boosts {title, multiplier: 2|3, startsAt, endsAt, tagId}
 * DELETE /api/renown/boosts/{id}
 */
class BoostsController extends Handler
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Boosts $boosts)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertAdmin();

        if ($request->getMethod() === 'DELETE') {
            $this->db->table('renown_boosts')->where('id', (int) self::route($request, 'id'))->delete();
        } else {
            $body = self::body($request);
            $multiplier = (int) ($body['multiplier'] ?? 2);
            try {
                $starts = Carbon::parse((string) ($body['startsAt'] ?? ''))->utc();
                $ends = Carbon::parse((string) ($body['endsAt'] ?? ''))->utc();
            } catch (\Throwable) {
                throw new Refusal('renown_boost_dates');
            }
            if (! in_array($multiplier, [2, 3], true)) {
                throw new Refusal('renown_boost_multiplier');
            }
            if ($ends->lte($starts) || $ends->lte(Carbon::now()) || $starts->diffInDays($ends) > 31) {
                throw new Refusal('renown_boost_dates');
            }
            $tag = (int) ($body['tagId'] ?? 0);
            $this->db->table('renown_boosts')->insert([
                'title' => mb_substr(trim((string) ($body['title'] ?? '')), 0, 100) ?: null,
                'multiplier' => $multiplier,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'tag_id' => $tag > 0 ? $tag : null,
            ]);
        }

        $this->boosts->forget();

        return new JsonResponse(['data' => $this->boosts->upcoming()]);
    }
}
