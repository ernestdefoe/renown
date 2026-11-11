<?php

namespace ErnestDefoe\Renown\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** "You reached Level 100: Prestige is ready." The subject is the member (a model with a core resource). */
class PrestigeReadyBlueprint implements BlueprintInterface
{
    public function __construct(public User $user, public int $tier)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getData(): mixed
    {
        return ['tier' => $this->tier];
    }

    public static function getType(): string
    {
        return 'renownPrestigeReady';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
