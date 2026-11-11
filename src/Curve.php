<?php

namespace ErnestDefoe\Renown;

/**
 * The climb: 100 levels, 20 ranks of five grades (I–V), then Prestige.
 *
 * Reaching level L takes 30·(L−1)^1.75 XP in total (rounded to tens), so
 * Level 100 is 93,214 XP: about two years at 120 XP a day. js/src/common/curve.ts
 * computes the same table; CurveTest pins both to the same numbers.
 */
final class Curve
{
    public const MAX_LEVEL = 100;

    public const RANKS = 20;

    public const GRADES = 5;

    /** Prestige tiers 1–20; 21 is Eternal, the last emblem, with no more resets. */
    public const TIERS = 20;

    public const ETERNAL = 21;

    /** @var array<int, int>|null */
    private static ?array $table = null;

    /** @return array<int, int> level => total XP needed to reach it */
    public static function table(): array
    {
        if (self::$table === null) {
            self::$table = [];
            for ($level = 1; $level <= self::MAX_LEVEL; $level++) {
                self::$table[$level] = $level === 1 ? 0 : (int) (round(30 * (($level - 1) ** 1.75) / 10) * 10);
            }
        }

        return self::$table;
    }

    public static function xpFor(int $level): int
    {
        return self::table()[max(1, min(self::MAX_LEVEL, $level))];
    }

    public static function levelFor(int $xp): int
    {
        $table = self::table();
        $low = 1;
        $high = self::MAX_LEVEL;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($table[$mid] <= $xp) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /** 1–20 */
    public static function rank(int $level): int
    {
        return intdiv(max(1, $level) - 1, self::GRADES) + 1;
    }

    /** 1–5 */
    public static function grade(int $level): int
    {
        return (max(1, $level) - 1) % self::GRADES + 1;
    }

    /** Days since 1970-01-01 in UTC: the day an award counts toward. */
    public static function day(?\DateTimeInterface $at = null): int
    {
        return intdiv(($at ?? new \DateTimeImmutable())->getTimestamp(), 86400);
    }
}
