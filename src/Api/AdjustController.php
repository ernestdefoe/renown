<?php

namespace ErnestDefoe\Renown\Api;

use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Refusal;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/renown/users/{id}/adjust {xp, reason}: add or remove XP by hand (renown.adjust). Logged with the reason. */
class AdjustController extends Handler
{
    public function __construct(private readonly Ledger $ledger)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertCan('renown.adjust');
        $user = User::query()->whereVisibleTo($actor)->find((int) self::route($request, 'id'));
        if (! $user) {
            throw new Refusal('renown_not_found', 404);
        }

        // Nobody adjusts their own XP, staff and admins included.
        if ((int) $user->id === (int) $actor->id) {
            throw new Refusal('renown_adjust_self', 403);
        }

        $body = self::body($request);
        $xp = (int) ($body['xp'] ?? 0);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($xp === 0 || abs($xp) > 1000000) {
            throw new Refusal('renown_adjust_amount');
        }
        if ($reason === '') {
            throw new Refusal('renown_adjust_reason');
        }

        $this->ledger->adjust($user, $xp, $actor, $reason);

        return new JsonResponse(['data' => Presenter::user($user)]);
    }
}
