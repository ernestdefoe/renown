<?php

namespace ErnestDefoe\Renown\Api;

use ErnestDefoe\Renown\Prestige;
use ErnestDefoe\Renown\Refusal;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The member's own actions, on themselves only:
 * POST /api/renown/prestige (take it), POST /api/renown/seen (the rank-up
 * toast was shown), PATCH /api/renown/me {hidden} (leave or rejoin the board).
 */
class MeController extends Handler
{
    public function __construct(private readonly Prestige $prestige, private readonly ConnectionInterface $db)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertRegistered();
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/prestige')) {
            if (! Prestige::canTake($actor)) {
                throw new Refusal('renown_prestige_not_ready');
            }
            $tier = $this->prestige->take($actor);
        } elseif (str_ends_with($path, '/seen')) {
            $this->db->table('users')->where('id', $actor->id)->update(['renown_seen' => self::seen($actor)]);
        } else {
            $hidden = (bool) (self::body($request)['hidden'] ?? false);
            $this->db->table('users')->where('id', $actor->id)->update(['renown_hidden' => $hidden]);
            $actor->renown_hidden = $hidden;
        }

        return new JsonResponse(['data' => Presenter::user($actor) + [
            'tier' => $tier ?? null,
            'hidden' => (bool) $actor->renown_hidden,
            'seen' => self::seen($actor),
        ]]);
    }

    /** The point the member has been told about: prestige × 1000 + level. */
    public static function seen(User $user): int
    {
        return (int) $user->renown_prestige * 1000 + (int) $user->renown_level;
    }
}
