<?php

namespace ErnestDefoe\Renown;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Groups given for reaching a rank or a prestige tier.
 *
 * A rank's group is held from that rank up, in the current climb; a tier's
 * group while it is the member's tier. Marked permanent, either is kept for
 * good (through every later Prestige). Renown only ever takes back a group
 * it gave (`renown_grants`): a member who was in it already keeps it, and
 * Flarum's built-in groups are never used.
 */
class Rewards
{
    public function __construct(private readonly ConnectionInterface $db, private readonly Ranks $ranks)
    {
    }

    public function configured(): bool
    {
        foreach ([...$this->ranks->ranks(), ...$this->ranks->tiers()] as $entry) {
            if ($entry['group']) {
                return true;
            }
        }

        return false;
    }

    public function sync(User $user): void
    {
        if (! $this->configured()) {
            return;
        }

        $rank = Curve::rank((int) $user->getAttribute('renown_level'));
        $tier = (int) $user->getAttribute('renown_prestige');
        $granted = $this->db->table('renown_grants')->where('user_id', $user->id)->pluck('group_id')->map(fn ($id) => (int) $id)->all();

        $wanted = [];
        foreach ($this->ranks->ranks() as $i => $entry) {
            $group = $this->usable($entry['group']);
            if ($group && ($rank >= $i + 1 || ($entry['permanent'] && in_array($group, $granted, true)))) {
                $wanted[$group] = ['kind' => 'rank', 'step' => $i + 1];
            }
        }
        foreach ($this->ranks->tiers() as $i => $entry) {
            $group = $this->usable($entry['group']);
            if ($group && ($tier === $i + 1 || ($entry['permanent'] && $tier >= $i + 1))) {
                $wanted[$group] = ['kind' => 'prestige', 'step' => $i + 1];
            }
        }

        $member = $this->db->table('group_user')->where('user_id', $user->id)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
        $changed = false;

        foreach ($wanted as $group => $why) {
            if (in_array($group, $member, true)) {
                continue;
            }
            if (! $this->db->table('groups')->where('id', $group)->exists()) {
                continue;
            }
            $this->db->table('group_user')->insertOrIgnore(['user_id' => $user->id, 'group_id' => $group, 'created_at' => Carbon::now()]);
            $this->db->table('renown_grants')->insertOrIgnore(['user_id' => $user->id, 'group_id' => $group] + $why);
            $changed = true;
        }

        foreach (array_diff($granted, array_keys($wanted)) as $group) {
            $this->db->table('group_user')->where('user_id', $user->id)->where('group_id', $group)->delete();
            $this->db->table('renown_grants')->where('user_id', $user->id)->where('group_id', $group)->delete();
            $changed = true;
        }

        if ($changed) {
            $user->unsetRelation('groups');
        }
    }

    /** Take back every group Renown gave (a reset). */
    public function revokeAll(): void
    {
        $grants = $this->db->table('renown_grants')->get(['user_id', 'group_id']);
        foreach ($grants as $grant) {
            $this->db->table('group_user')->where('user_id', $grant->user_id)->where('group_id', $grant->group_id)->delete();
        }
        $this->db->table('renown_grants')->delete();
    }

    private function usable(?int $group): ?int
    {
        return $group && ! in_array($group, [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID, Group::MODERATOR_ID], true) ? $group : null;
    }
}
