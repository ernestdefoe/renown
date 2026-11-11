<?php

namespace ErnestDefoe\Renown;

use Carbon\Carbon;
use ErnestDefoe\Renown\Event\LevelChanged;
use ErnestDefoe\Renown\Event\XpAwarded;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Every XP change goes through here: one `renown_xp_log` row per award, and
 * the member's totals on `users` moved in the same transaction.
 *
 * The member's user row is locked first (SELECT … FOR UPDATE), so the daily
 * caps, the cooldown and the like limits are read with nothing racing them,
 * and the totals are written as absolute values computed here: never
 * `column - n`, which errors on MySQL's unsigned columns below zero.
 *
 * XP belongs to the climb it was earned in (`prestige` on the row): taking
 * it back after a Prestige lowers lifetime XP only.
 */
class Ledger
{
    public const TABLE = 'renown_xp_log';

    private const COLUMNS = ['id', 'renown_xp', 'renown_lifetime_xp', 'renown_level', 'renown_prestige'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly Boosts $boosts,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Award XP once per (member, source, subject, actor).
     *
     * Options: `capped` (default true: live activity under the anti-farming
     * rules and any running boost; false for the history replay and restores),
     * `backfilled` (true: never counts toward a day's caps), `at`, `note`,
     * `tags` (the discussion's tags, for tag boosts), `quiet`.
     *
     * @param array{capped?: bool, backfilled?: bool, at?: \DateTimeInterface, note?: ?string, tags?: list<int>|null, quiet?: bool} $options
     * @return int the XP actually given (0 when capped out or already given)
     */
    public function award(User $user, string $source, int $subject, int $actor, int $xp, array $options = []): int
    {
        if ($xp <= 0 || ! $user->exists) {
            return 0;
        }

        $capped = $options['capped'] ?? true;
        $at = Carbon::instance($options['at'] ?? Carbon::now());
        $day = Curve::day($at);

        $change = $this->db->transaction(function () use ($user, $source, $subject, $actor, $xp, $options, $capped, $at, $day) {
            $row = $this->lock((int) $user->id);
            if (! $row) {
                return null;
            }

            if ($capped && $source !== 'admin') {
                $xp = $this->underCaps((int) $user->id, $source, $actor, $xp, $day, $options['tags'] ?? null);
                if ($xp <= 0) {
                    return null;
                }
            }

            $inserted = $this->db->table(self::TABLE)->insertOrIgnore([
                'user_id' => $user->id,
                'source' => $source,
                'subject_id' => $subject,
                'actor_id' => $actor,
                'xp' => $xp,
                'prestige' => (int) $row->renown_prestige,
                'day' => $day,
                'backfilled' => ! $capped || ($options['backfilled'] ?? false),
                'note' => $options['note'] ?? null,
                'created_at' => $at,
            ]);

            return $inserted ? $this->apply($row, $xp, $xp) + ['xp_given' => $xp] : null;
        });

        if (! $change) {
            return 0;
        }

        $this->publish($user, $change, $options['quiet'] ?? ! $capped);
        if ($capped) {
            $this->events->dispatch(new XpAwarded($user, $source, $change['xp_given']));
        }

        return $change['xp_given'];
    }

    /**
     * Staff add or remove XP by hand, with a reason. Never capped, never
     * announced as earned; a removal can take a member's level down.
     */
    public function adjust(User $user, int $xp, User $actor, string $note): void
    {
        $change = $this->db->transaction(function () use ($user, $xp, $actor, $note) {
            $row = $this->lock((int) $user->id);
            if (! $row) {
                return null;
            }
            // Never below zero: a removal larger than the member's XP removes what there is.
            $xp = max($xp, -(int) $row->renown_xp);
            if ($xp === 0) {
                return null;
            }
            $now = Carbon::now();
            $this->db->table(self::TABLE)->insert([
                'user_id' => $user->id,
                'source' => 'admin',
                'subject_id' => (int) ($this->db->table(self::TABLE)->where('user_id', $user->id)->where('source', 'admin')->max('subject_id') ?? 0) + 1,
                'actor_id' => $actor->id,
                'xp' => $xp,
                'prestige' => (int) $row->renown_prestige,
                'day' => Curve::day($now),
                'backfilled' => false,
                'note' => mb_substr($note, 0, 200),
                'created_at' => $now,
            ]);

            return $this->apply($row, $xp, $xp);
        });

        if ($change) {
            $this->publish($user, $change, $xp < 0);
        }
    }

    /**
     * Take back what some rows gave, exactly, and lower each member's totals
     * by them. Always quiet: a level lost is never announced.
     *
     * Hard (an unlike, a deleted post): the rows go, so the same thing can be
     * earned again later under the day's rules. Soft (a hidden post, a
     * discussion moved out of scope): the rows stay, marked revoked, so a
     * restore brings back exactly what was earned and nothing more.
     *
     * @param callable(\Illuminate\Database\Query\Builder): mixed $scope narrows the log query
     */
    public function revoke(callable $scope, bool $soft = false): void
    {
        $this->each($scope, function (object $row, Collection $rows) use ($soft) {
            $active = $rows->whereNull('revoked_at');
            if ($soft) {
                $this->db->table(self::TABLE)->whereIn('id', $active->pluck('id')->all())->update(['revoked_at' => Carbon::now()]);
            } else {
                $this->db->table(self::TABLE)->whereIn('id', $rows->pluck('id')->all())->delete();
            }

            return $active->isEmpty() ? null : $this->apply($row, -$this->climbOf($active, $row), -(int) $active->sum('xp'));
        });
    }

    /**
     * Bring back rows a soft take-back revoked (a restored post or discussion):
     * exactly the XP they earned, in the climb they were earned in.
     *
     * @param callable(\Illuminate\Database\Query\Builder): mixed $scope narrows the log query
     */
    public function reinstate(callable $scope): void
    {
        $this->each(function ($q) use ($scope) {
            $scope($q);
            $q->whereNotNull('revoked_at');
        }, function (object $row, Collection $rows) {
            $back = $rows->whereNotNull('revoked_at');
            if ($back->isEmpty()) {
                return null;
            }
            $this->db->table(self::TABLE)->whereIn('id', $back->pluck('id')->all())->update(['revoked_at' => null]);

            return $this->apply($row, $this->climbOf($back, $row), (int) $back->sum('xp'));
        });
    }

    /**
     * Each member's matching rows, read again under their lock (a racing
     * take-back may have handled some already), then their change published.
     *
     * @param callable(\Illuminate\Database\Query\Builder): mixed $scope
     * @param callable(object, Collection<int, \stdClass>): (array{from: int, to: int, xp: int, lifetime: int, prestige: int}|null) $change
     */
    private function each(callable $scope, callable $change): void
    {
        $query = $this->db->table(self::TABLE)->select(['id', 'user_id']);
        $scope($query);
        $byUser = [];
        foreach ($query->get() as $row) {
            $byUser[(int) $row->user_id][] = (int) $row->id;
        }

        foreach ($byUser as $userId => $ids) {
            $result = $this->db->transaction(function () use ($userId, $ids, $change) {
                $row = $this->lock($userId);
                $rows = $this->db->table(self::TABLE)->whereIn('id', $ids)->get(['id', 'xp', 'prestige', 'revoked_at']);

                return $row && $rows->isNotEmpty() ? $change($row, $rows) : null;
            });

            if ($result && ($user = User::query()->find($userId))) {
                $this->publish($user, $result, true);
            }
        }
    }

    /**
     * What of these rows belongs to the member's current climb. Eternal keeps
     * the climb that reached it (tier 20) with no reset.
     *
     * @param Collection<int, \stdClass> $rows
     */
    private function climbOf(Collection $rows, object $user): int
    {
        $tier = (int) $user->renown_prestige;

        return (int) $rows->filter(fn ($r) => self::inClimb((int) $r->prestige, $tier))->sum('xp');
    }

    public static function inClimb(int $rowPrestige, int $userPrestige): bool
    {
        return $rowPrestige === $userPrestige || ($userPrestige >= Curve::ETERNAL && $rowPrestige === Curve::TIERS);
    }

    /**
     * Set each member's totals to what their rows add up to (the replay's
     * last step, and after a reset). Quiet.
     *
     * @param list<int> $userIds
     */
    public function recompute(array $userIds): void
    {
        if (! $userIds) {
            return;
        }

        $changes = $this->db->transaction(function () use ($userIds) {
            $users = $this->db->table('users')->whereIn('id', $userIds)->lockForUpdate()->get(self::COLUMNS)->keyBy('id');
            $grammar = $this->db->getQueryGrammar();
            $sums = $this->db->table(self::TABLE)
                ->join('users', 'users.id', '=', self::TABLE.'.user_id')
                ->whereIn(self::TABLE.'.user_id', $userIds)
                ->groupBy(self::TABLE.'.user_id')
                ->selectRaw($grammar->wrap(self::TABLE.'.user_id').' as uid')
                ->whereNull(self::TABLE.'.revoked_at')
                ->selectRaw('SUM('.$grammar->wrap(self::TABLE.'.xp').') as lifetime')
                // Rows of the member's current climb; Eternal (21) keeps the climb that reached it (20).
                ->selectRaw('SUM(CASE WHEN '.$grammar->wrap(self::TABLE.'.prestige').' = '.$grammar->wrap('users.renown_prestige')
                    .' OR ('.$grammar->wrap('users.renown_prestige').' >= '.Curve::ETERNAL.' AND '.$grammar->wrap(self::TABLE.'.prestige').' = '.Curve::TIERS.')'
                    .' THEN '.$grammar->wrap(self::TABLE.'.xp').' ELSE 0 END) as climb')
                ->get()
                ->keyBy('uid');

            $changes = [];
            foreach ($users as $id => $row) {
                $sum = $sums->get($id);
                $climb = (int) ($sum->climb ?? 0);
                $lifetime = (int) ($sum->lifetime ?? 0);
                if ($climb === (int) $row->renown_xp && $lifetime === (int) $row->renown_lifetime_xp && $this->levelOf($climb, (int) $row->renown_prestige) === (int) $row->renown_level) {
                    continue;
                }
                $changes[$id] = $this->apply($row, $climb - (int) $row->renown_xp, $lifetime - (int) $row->renown_lifetime_xp);
            }

            return $changes;
        });

        if ($changes) {
            foreach (User::query()->whereIn('id', array_keys($changes))->get() as $user) {
                $this->publish($user, $changes[$user->id], true);
            }
        }
    }

    public function levelOf(int $xp, int $prestige): int
    {
        return $prestige >= Curve::ETERNAL ? Curve::MAX_LEVEL : Curve::levelFor($xp);
    }

    /** The member's row, locked for this transaction. */
    public function lock(int $userId): ?object
    {
        return $this->db->table('users')->where('id', $userId)->lockForUpdate()->first(self::COLUMNS);
    }

    /**
     * Write the member's new totals from the locked row plus the change.
     *
     * @return array{from: int, to: int, xp: int, lifetime: int, prestige: int}
     */
    public function apply(object $row, int $climbDelta, int $lifetimeDelta): array
    {
        $prestige = (int) $row->renown_prestige;
        $xp = max(0, (int) $row->renown_xp + $climbDelta);
        $lifetime = max(0, (int) $row->renown_lifetime_xp + $lifetimeDelta);
        $level = $this->levelOf($xp, $prestige);

        $this->db->table('users')->where('id', $row->id)->update([
            'renown_xp' => $xp,
            'renown_lifetime_xp' => $lifetime,
            'renown_level' => $level,
        ]);

        return ['from' => (int) $row->renown_level, 'to' => $level, 'xp' => $xp, 'lifetime' => $lifetime, 'prestige' => $prestige];
    }

    /**
     * The model in memory follows the database (this request's own payload
     * shows the new level), then the level change goes out.
     *
     * @param array{from: int, to: int, xp: int, lifetime: int, prestige: int} $change
     */
    public function publish(User $user, array $change, bool $quiet): void
    {
        foreach (['renown_xp' => $change['xp'], 'renown_lifetime_xp' => $change['lifetime'], 'renown_level' => $change['to'], 'renown_prestige' => $change['prestige']] as $key => $value) {
            $user->setAttribute($key, $value);
            $user->syncOriginalAttribute($key);
        }

        if ($change['from'] !== $change['to']) {
            $this->events->dispatch(new LevelChanged($user, $change['from'], $change['to'], $quiet));
        }
    }

    /**
     * The anti-farming rules, read under the member's lock: the daily cap,
     * the like limits and the posting cooldown. A running boost multiplies
     * the XP and the caps alike.
     *
     * @param list<int>|null $tags
     */
    private function underCaps(int $userId, string $source, int $actor, int $xp, int $day, ?array $tags): int
    {
        $multiplier = $this->boosts->multiplier($tags);
        $xp *= $multiplier;

        $today = fn () => $this->db->table(self::TABLE)->where('user_id', $userId)->where('day', $day)->where('backfilled', false)->whereNull('revoked_at');

        if (in_array($source, ['reply', 'discussion'], true) && ($cooldown = $this->settings->int('cooldown', 30)) > 0) {
            $recent = $today()->whereIn('source', ['reply', 'discussion'])
                ->where('created_at', '>', Carbon::now()->subSeconds($cooldown))
                ->exists();
            if ($recent) {
                return 0;
            }
        }

        if ($source === 'like') {
            $perGiver = $this->settings->int('likes_per_giver', 2);
            if ($perGiver > 0 && $today()->where('source', 'like')->where('actor_id', $actor)->count() >= $perGiver) {
                return 0;
            }
            $likeCap = $this->settings->int('cap_likes', 50) * $multiplier;
            if ($likeCap > 0) {
                $xp = min($xp, max(0, $likeCap - (int) $today()->where('source', 'like')->sum('xp')));
            }
        }

        $cap = $this->settings->int('cap_daily', 300) * $multiplier;
        if ($cap > 0 && $xp > 0) {
            $xp = min($xp, max(0, $cap - (int) $today()->where('source', '<>', 'admin')->sum('xp')));
        }

        return $xp;
    }
}
