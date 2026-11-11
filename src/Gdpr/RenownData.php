<?php

namespace ErnestDefoe\Renown\Gdpr;

use ErnestDefoe\Renown\Ledger;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: their level, prestige and XP, every award in the log and
 * the emblems they earned. Erasing or anonymizing deletes all of it (the
 * member's own rows; likes they gave others stay in the others' XP without a name).
 */
class RenownData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-renown.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-renown.lib.gdpr.delete');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-renown.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $db = resolve('db');
        $data = [
            'level' => (int) $this->user->renown_level,
            'prestige' => (int) $this->user->renown_prestige,
            'xp_this_climb' => (int) $this->user->renown_xp,
            'lifetime_xp' => (int) $this->user->renown_lifetime_xp,
            'emblems' => $db->table('renown_prestiges')->where('user_id', $this->user->id)->orderBy('tier')->get(['tier', 'reached_at'])->all(),
            'log' => $db->table(Ledger::TABLE)->where('user_id', $this->user->id)->orderBy('id')
                ->get(['source', 'subject_id', 'xp', 'prestige', 'note', 'created_at'])->all(),
        ];

        return [['renown/renown.json' => $this->encodeForExport($data)]];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $db = resolve('db');
        $db->table(Ledger::TABLE)->where('user_id', $this->user->id)->delete();
        $db->table('renown_prestiges')->where('user_id', $this->user->id)->delete();
        $db->table('renown_grants')->where('user_id', $this->user->id)->delete();
    }
}
