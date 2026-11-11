<?php

namespace ErnestDefoe\Renown;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Double XP events: while one runs, live XP (and the daily caps with it) is
 * multiplied. A boost tied to a tag counts only for activity in that tag.
 * The running and next boosts are cached for a minute, so asking costs no query.
 */
class Boosts
{
    private const KEY = 'ernestdefoe-renown.boosts';

    /** @var list<array{id: int, title: ?string, multiplier: int, starts_at: string, ends_at: string, tag_id: ?int}>|null */
    private ?array $memo = null;

    public function __construct(private readonly ConnectionInterface $db, private readonly Cache $cache)
    {
    }

    /** @return list<array{id: int, title: ?string, multiplier: int, starts_at: string, ends_at: string, tag_id: ?int}> boosts that haven't ended, soonest first */
    public function upcoming(): array
    {
        return $this->memo ??= $this->cache->remember(self::KEY, 60, fn () => $this->db->table('renown_boosts')
            ->where('ends_at', '>', Carbon::now())
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'title' => $row->title,
                'multiplier' => (int) $row->multiplier,
                'starts_at' => Carbon::parse($row->starts_at)->toIso8601String(),
                'ends_at' => Carbon::parse($row->ends_at)->toIso8601String(),
                'tag_id' => $row->tag_id === null ? null : (int) $row->tag_id,
            ])->all());
    }

    /** @return list<array{id: int, title: ?string, multiplier: int, starts_at: string, ends_at: string, tag_id: ?int}> */
    public function running(): array
    {
        $now = Carbon::now();

        return array_values(array_filter($this->upcoming(), fn ($b) => Carbon::parse($b['starts_at'])->lte($now) && Carbon::parse($b['ends_at'])->gt($now)));
    }

    /**
     * The biggest multiplier that applies. $tagIds null = activity with no tag
     * (a visit, a kudos): only boosts for the whole forum count.
     *
     * @param list<int>|null $tagIds
     */
    public function multiplier(?array $tagIds = null): int
    {
        $best = 1;
        foreach ($this->running() as $boost) {
            if ($boost['tag_id'] === null || ($tagIds !== null && in_array($boost['tag_id'], $tagIds, true))) {
                $best = max($best, $boost['multiplier']);
            }
        }

        return $best;
    }

    public function forget(): void
    {
        $this->memo = null;
        $this->cache->forget(self::KEY);
    }
}
