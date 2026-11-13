<?php

namespace ErnestDefoe\Renown\Integration;

use Carbon\Carbon;
use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Settings;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * ernestdefoe/corkboard: a finished card pays the people assigned to it, split evenly (at least 1
 * each). Never whoever dragged it to done unless they're assigned too.
 *
 * Anti-farming, on top of Renown's own daily cap:
 * - once per card and person, ever: reopening takes it back softly, finishing again restores
 *   exactly that, never a second award;
 * - the card is at least an hour old;
 * - and it is real work for that person: someone else made it, or it has a checklist or a comment;
 * - the board has a team (its owner plus at least one other person or group), so nobody farms a
 *   board of their own;
 * - a daily cap for card XP (`cap_corkboard`, default 50).
 * A deleted card gives its XP back for good.
 */
class Corkboard
{
    public const SOURCE = 'corkboard_card';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function completed(object $event): void
    {
        $card = $event->card;
        $id = (int) $card->id;

        // Finished before: bring back exactly what it earned, nothing more.
        if ($this->db->table(Ledger::TABLE)->where('source', self::SOURCE)->where('subject_id', $id)->exists()) {
            $this->ledger->reinstate(fn ($q) => $q->where('source', self::SOURCE)->where('subject_id', $id));

            return;
        }

        $xp = $this->settings->xp(self::SOURCE);
        if ($xp <= 0 || ! $card->created_at || Carbon::parse($card->created_at)->gt(Carbon::now()->subHour())) {
            return;
        }
        $board = $this->db->table('corkboard_boards')->where('id', $card->board_id)->first(['id', 'owner_id']);
        $others = $this->db->table('corkboard_members')->where('board_id', $card->board_id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '<>', (int) ($board->owner_id ?? 0)))->exists();
        if (! $board || ! $others) {
            return;
        }

        $assignees = $this->db->table('corkboard_assignees')->where('card_id', $id)->pluck('user_id')->map(fn ($u) => (int) $u)->all();
        if (! $assignees) {
            return;
        }
        $share = max(1, intdiv($xp, count($assignees)));
        $worked = (int) $card->checklist_total > 0 || (int) $card->comment_count > 0;

        foreach (User::query()->whereIn('id', $assignees)->get() as $user) {
            if (! $worked && (int) $card->user_id === (int) $user->id) {
                continue;
            }
            $this->ledger->award($user, self::SOURCE, $id, 0, $share);
        }
    }

    public function reopened(object $event): void
    {
        $id = (int) $event->card->id;
        $this->ledger->revoke(fn ($q) => $q->where('source', self::SOURCE)->where('subject_id', $id), true);
    }

    public function deleted(object $event): void
    {
        $id = (int) $event->card->id;
        $this->ledger->revoke(fn ($q) => $q->where('source', self::SOURCE)->where('subject_id', $id));
    }
}
