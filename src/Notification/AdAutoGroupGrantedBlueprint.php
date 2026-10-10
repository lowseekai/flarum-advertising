<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Lowseekai\Advertising\Model\Ad;

class AdAutoGroupGrantedBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(
        public Ad $ad,
        public string $groupName,
    ) {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->ad->user;
    }

    public function getData(): array
    {
        return [
            'adId' => (int) $this->ad->id,
            'title' => (string) $this->ad->title,
            'groupName' => $this->groupName,
        ];
    }

    public static function getType(): string
    {
        return 'lowseekaiAdvertisingAutoGroupGranted';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
