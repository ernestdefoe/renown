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
 * a deleted one gives it back for good.
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
        $testimonial = $event->testimonial;
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

    public function withdrawn(object $event): void
    {
        $testimonial = $event->testimonial;
        $id = (int) $testimonial->id;
        $this->ledger->revoke(fn ($q) => $q->where('source', self::SOURCE)->where('subject_id', $id), $testimonial->exists);
    }
}
