<?php

namespace ErnestDefoe\Renown;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Renown's settings, read with their defaults. 0 switches an XP source off.
 *
 * 🚨 extend.php repeats the defaults as string literals: it must never read
 * this class while a deploy swaps vendor/.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-renown.';

    /** XP per source, live and in the history replay. */
    public const SOURCES = [
        'reply' => 10,
        'discussion' => 20,
        'like' => 5,
        'best_answer' => 50,
        'visit' => 5,
        'streak' => 5,
        'kudos' => 25,
        'answer_upvote' => 5,
        'testimonial' => 25,
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function xp(string $source): int
    {
        return $this->int('xp_'.$source, self::SOURCES[$source] ?? 0);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? $default : (bool) $value;
    }

    /** @return list<int> */
    public function excludedTags(): array
    {
        return self::ids($this->settings->get(self::PREFIX.'exclude_tags'));
    }

    /** @return array<string, mixed> */
    public function json(string $key): array
    {
        $value = json_decode((string) $this->settings->get(self::PREFIX.$key), true);

        return is_array($value) ? $value : [];
    }

    public function setJson(string $key, ?array $value): void
    {
        if ($value === null) {
            $this->settings->delete(self::PREFIX.$key);
        } else {
            $this->settings->set(self::PREFIX.$key, json_encode($value));
        }
    }

    /** @return list<int> */
    public static function ids(mixed $value): array
    {
        $list = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($list) ? array_values(array_unique(array_filter(array_map('intval', $list), fn ($id) => $id > 0))) : [];
    }
}
