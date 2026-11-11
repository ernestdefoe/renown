<?php

namespace ErnestDefoe\Renown;

/**
 * The admin's ranks (20) and prestige tiers (21: twenty, then Eternal):
 * a name (empty = the language pack's), an uploaded icon (empty = the drawn
 * insignia), and a group reward that may be kept for good.
 */
class Ranks
{
    public function __construct(private readonly Settings $settings, private readonly Icons $icons)
    {
    }

    /** @return list<array{name: string, icon: ?string, group: ?int, permanent: bool}> 20 entries, rank 1 first */
    public function ranks(): array
    {
        return $this->list('ranks', Curve::RANKS);
    }

    /** @return list<array{name: string, icon: ?string, group: ?int, permanent: bool}> 21 entries, tier 1 first, Eternal last */
    public function tiers(): array
    {
        return $this->list('tiers', Curve::ETERNAL);
    }

    /**
     * The forum's copy: names and icon addresses only (the groups are the admin's business).
     *
     * @return array{ranks: list<array{name: string, icon: ?string}>, tiers: list<array{name: string, icon: ?string}>}
     */
    public function forForum(): array
    {
        $public = fn (array $entry) => ['name' => $entry['name'], 'icon' => $this->icons->url($entry['icon'])];

        return ['ranks' => array_map($public, $this->ranks()), 'tiers' => array_map($public, $this->tiers())];
    }

    /** @return list<array{name: string, icon: ?string, group: ?int, permanent: bool}> */
    private function list(string $key, int $count): array
    {
        $saved = $this->settings->json($key);
        // Icons have their own setting: saving names from the admin page can never drop one.
        $icons = $this->settings->json('icons')[$key] ?? [];
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = is_array($saved[$i] ?? null) ? $saved[$i] : [];
            $group = (int) ($entry['group'] ?? 0);
            $out[] = [
                'name' => mb_substr(trim((string) ($entry['name'] ?? '')), 0, 40),
                'icon' => is_string($icons[$i + 1] ?? null) && $icons[$i + 1] !== '' ? $icons[$i + 1] : null,
                'group' => $group > 0 ? $group : null,
                'permanent' => (bool) ($entry['permanent'] ?? false),
            ];
        }

        return $out;
    }
}
