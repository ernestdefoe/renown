<?php

namespace ErnestDefoe\Renown\Badges;

use ErnestDefoe\Renown\Event\LevelChanged;
use ErnestDefoe\Renown\Event\PrestigeTaken;
use ErnestDefoe\Renown\Event\XpAwarded;
use Flarum\User\User;
use FoF\Badges\Trigger\MetricInterface;

/**
 * FoF Badges metrics: "Renown level", "Prestige tier" and "Lifetime XP".
 * Read from the user row: no query. Only loaded when fof/badges builds its
 * metrics (BadgesServiceProvider).
 */
class Metric implements MetricInterface
{
    public function __construct(private readonly string $type, private readonly string $column)
    {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTranslationKey(): string
    {
        return 'ernestdefoe-renown.admin.badges.'.$this->type;
    }

    public function getEventTriggers(): array
    {
        $user = fn (object $event) => $event->user;

        return [
            LevelChanged::class => $user,
            PrestigeTaken::class => $user,
            XpAwarded::class => $user,
        ];
    }

    public function getValue(User $user, array $config = []): int
    {
        return (int) $user->getAttribute($this->column);
    }

    public function isBoolean(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    public function getExtensionDependencies(): array
    {
        return ['ernestdefoe/renown'];
    }
}
