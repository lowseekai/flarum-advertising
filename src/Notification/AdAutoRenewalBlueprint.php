<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Support\AdvertisingSettings;

class AdAutoRenewalBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public Ad $ad,
        public string $event,
        public ?string $reason = null,
        public int $amount = 0,
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
            'event' => $this->event,
            'amount' => $this->amount,
            'slotLabel' => AdvertisingSettings::slotLabel((string) $this->ad->slot_key),
            'startsAt' => $this->ad->starts_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'endsAt' => $this->ad->ends_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'reason' => $this->reason ?: '',
        ];
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'lowseekai-advertising::emails.plain.ad-auto-renewal',
            'html' => 'lowseekai-advertising::emails.html.ad-auto-renewal',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans(
            match ($this->event) {
                'enabled' => 'lowseekai-advertising.email.auto_renewal.enabled_subject',
                'succeeded' => 'lowseekai-advertising.email.auto_renewal.succeeded_subject',
                'price_changed' => 'lowseekai-advertising.email.auto_renewal.price_changed_subject',
                default => 'lowseekai-advertising.email.auto_renewal.failed_subject',
            },
            ['{title}' => (string) $this->ad->title]
        );
    }

    public static function getType(): string
    {
        return 'lowseekaiAdvertisingAutoRenewal';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
