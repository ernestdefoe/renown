<?php

namespace ErnestDefoe\Renown;

use Carbon\Carbon;
use ErnestDefoe\Renown\Event\PrestigeTaken;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Prestige: only at Level 100, only when the member chooses it.
 *
 * Tiers 1–20 start the climb again at Newcomer I: XP this climb goes to 0
 * (XP past Level 100 included), lifetime XP, every emblem and permanent
 * rewards stay. After tier 20, Eternal: the last emblem, no reset, Immortal V
 * for good.
 */
class Prestige
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Ledger $ledger,
        private readonly Dispatcher $events,
    ) {
    }

    public static function canTake(User $user): bool
    {
        return (int) $user->getAttribute('renown_level') >= Curve::MAX_LEVEL && (int) $user->getAttribute('renown_prestige') < Curve::ETERNAL;
    }

    /** @return int the new tier */
    public function take(User $user): int
    {
        $result = $this->db->transaction(function () use ($user) {
            $row = $this->ledger->lock((int) $user->id);
            if (! $row || (int) $row->renown_level < Curve::MAX_LEVEL || (int) $row->renown_prestige >= Curve::ETERNAL) {
                throw new Refusal('renown_prestige_not_ready');
            }

            $tier = (int) $row->renown_prestige + 1;
            $eternal = $tier === Curve::ETERNAL;
            $values = ['renown_prestige' => $tier];
            if (! $eternal) {
                $values += ['renown_xp' => 0, 'renown_level' => 1];
            }
            $this->db->table('users')->where('id', $user->id)->update($values);
            $this->db->table('renown_prestiges')->insertOrIgnore(['user_id' => $user->id, 'tier' => $tier, 'reached_at' => Carbon::now()]);

            return [$tier, $values];
        });

        [$tier, $values] = $result;
        foreach ($values as $key => $value) {
            $user->setAttribute($key, $value);
            $user->syncOriginalAttribute($key);
        }

        $this->events->dispatch(new PrestigeTaken($user, $tier));

        return $tier;
    }
}
