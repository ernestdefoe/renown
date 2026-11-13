<?php

namespace ErnestDefoe\Renown\Integration;

use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Settings;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * ernestdefoe/testimonials: a testimonial a member wrote went live. Once per
 * member and subject: a second testimonial about the same thing earns
 * nothing while the first still counts. Mirrored Depot reviews never earn.
 *
 * Withdrawn (declined, spam, taken down, edited back into the queue) is a
 * soft take-back, so approving it again brings back exactly what it earned;
 * a deleted one gives it back for good. Either way, another of theirs about
 * the same subject that is still live takes over the award.
 */
class Testimonials
{
    public const SOURCE = 'testimonial';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function approved(object $event): void
    {
        $this->count($event->testimonial);
    }

    public function withdrawn(object $event): void
    {
        $testimonial = $event->testimonial;
        $id = (int) $testimonial->id;
        $this->ledger->revoke(fn ($q) => $q->where('source', self::SOURCE)->where('subject_id', $id), $testimonial->exists);
        if ($testimonial->source !== 'native') {
            return;
        }

        $next = $this->db->table('testimonials')
            ->where('user_id', $testimonial->user_id)
            ->where('source', 'native')
            ->where('id', '<>', $id)
            ->where('status', 'approved')
            ->where('hidden_by_author', false)
            ->where('subject_type', $testimonial->subject_type)
            ->when(
                $testimonial->subject_id,
                fn ($q) => $q->where('subject_id', $testimonial->subject_id),
                fn ($q) => $q->whereNull('subject_id')
            )
            ->orderBy('id')
            ->first(['id', 'user_id', 'source', 'subject_type', 'subject_id']);
        if ($next) {
            $this->count($next);
        }
    }

    /** A live testimonial counts, unless another of the member's about the same subject already does. */
    private function count(object $testimonial): void
    {
        if ($testimonial->source !== 'native' || ! ($author = User::query()->find($testimonial->user_id))) {
            return;
        }

        $id = (int) $testimonial->id;
        $log = Ledger::TABLE;
        $counted = $this->db->table($log)
            ->join('testimonials', 'testimonials.id', '=', $log.'.subject_id')
            ->where($log.'.user_id', $author->id)
            ->where($log.'.source', self::SOURCE)
            ->whereNull($log.'.revoked_at')
            ->where($log.'.subject_id', '<>', $id)
            ->where('testimonials.subject_type', $testimonial->subject_type)
            ->when(
                $testimonial->subject_id,
                fn ($q) => $q->where('testimonials.subject_id', $testimonial->subject_id),
                fn ($q) => $q->whereNull('testimonials.subject_id')
            )
            ->exists();
        if ($counted) {
            return;
        }

        if ($this->db->table($log)->where('source', self::SOURCE)->where('subject_id', $id)->exists()) {
            $this->ledger->reinstate(fn ($q) => $q->where('source', self::SOURCE)->where('subject_id', $id));
        } else {
            $this->ledger->award($author, self::SOURCE, $id, 0, $this->settings->xp(self::SOURCE));
        }
    }
}
