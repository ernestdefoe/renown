<?php

namespace ErnestDefoe\Renown\Integration;

use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Settings;

/**
 * ernestdefoe/syllabus: XP for learning. Three sources, each its own line in a
 * member's XP history and each switched off by setting it to 0:
 *
 * - enrolling in a course (10 by default), once per member and course;
 * - completing a course (100 by default), once per member and course;
 * - a certificate issued (50 by default), once per certificate.
 *
 * Completion and certificate XP sits outside the daily cap (Ledger::OUTSIDE_DAILY_CAP):
 * each pays only once, so a cap that clipped it would lose it for good.
 *
 * One log row per award, unique on (member, source, subject), so a re-fired
 * event or leaving and re-joining a course never pays twice. Syllabus has no
 * unenroll or revoke event (certificates are never revoked), so nothing is
 * taken back. Like Renown's other integrations, past courses aren't replayed:
 * they earn from the moment both extensions are enabled.
 */
class Syllabus
{
    public const ENROLL = 'syllabus_enroll';
    public const COMPLETE = 'syllabus_complete';
    public const CERTIFICATE = 'syllabus_certificate';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
    ) {
    }

    public function enrolled(object $event): void
    {
        $this->award($event, self::ENROLL, (int) $event->course->id);
    }

    public function completed(object $event): void
    {
        $this->award($event, self::COMPLETE, (int) $event->course->id);
    }

    public function certified(object $event): void
    {
        $this->award($event, self::CERTIFICATE, (int) $event->certificate->id);
    }

    private function award(object $event, string $source, int $subject): void
    {
        $xp = $this->settings->xp($source);

        if ($xp > 0 && $event->user) {
            $this->ledger->award($event->user, $source, $subject, 0, $xp);
        }
    }
}
