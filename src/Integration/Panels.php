<?php

namespace ErnestDefoe\Renown\Integration;

use ErnestDefoe\Renown\Ledger;
use ErnestDefoe\Renown\Settings;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * ernestdefoe/panels: XP for comics.
 *
 * - Publishing a chapter pays its uploader (15 by default), once per chapter: a chapter taken down
 *   (removed, or by a copyright claim) gives it back softly, and restoring it brings back exactly that.
 * - Finishing a chapter pays the reader (2 by default), once per member and chapter (Panels fires
 *   ChapterRead only the first time, and never for the chapter's own uploader), under its own daily
 *   cap (`cap_panels_read`, default 20), on top of Renown's.
 */
class Panels
{
    public const PUBLISH = 'panels_publish';
    public const READ = 'panels_read';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function published(object $event): void
    {
        $chapter = $event->chapter;
        $id = (int) $chapter->id;
        if ($this->db->table(Ledger::TABLE)->where('source', self::PUBLISH)->where('subject_id', $id)->exists()) {
            $this->ledger->reinstate(fn ($q) => $q->where('source', self::PUBLISH)->where('subject_id', $id));

            return;
        }
        $xp = $this->settings->xp(self::PUBLISH);
        $uploader = $chapter->user_id ? User::query()->find($chapter->user_id) : null;
        if ($xp > 0 && $uploader) {
            $this->ledger->award($uploader, self::PUBLISH, $id, 0, $xp);
        }
    }

    public function removed(object $event): void
    {
        $id = (int) $event->chapter->id;
        $this->ledger->revoke(fn ($q) => $q->where('source', self::PUBLISH)->where('subject_id', $id), true);
    }

    public function read(object $event): void
    {
        $chapter = $event->chapter;
        $user = $event->user;
        $xp = $this->settings->xp(self::READ);
        if ($xp <= 0 || (int) $chapter->user_id === (int) $user->id) {
            return;
        }
        $this->ledger->award($user, self::READ, (int) $chapter->id, 0, $xp);
    }
}
