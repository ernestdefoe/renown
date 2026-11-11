<?php

namespace ErnestDefoe\Renown\Integration;

use ErnestDefoe\Renown\Activity;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * ernestdefoe/kudos: a kudos received. Kudos sends its "received" alert
 * once per kudos, when it shows on the wall (at once, or when a moderator
 * approves it), so XP rides on that alert; a deleted kudos gives it back.
 */
class Kudos
{
    public function __construct(private readonly Activity $activity)
    {
    }

    /**
     * @param User[] $recipients
     * @return User[]
     */
    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        if ($blueprint instanceof \ErnestDefoe\Kudos\Notification\KudosReceivedBlueprint) {
            $kudos = $blueprint->kudos;
            foreach ($recipients as $user) {
                if ((int) $user->id === (int) $kudos->receiver_id) {
                    $this->activity->kudos($user, (int) $kudos->id, $kudos->giver_id ? (int) $kudos->giver_id : null);
                }
            }
        }

        return $recipients;
    }
}
