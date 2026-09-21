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

class AdReviewedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public Ad $ad,
        public User $reviewer,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->reviewer;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->ad->user;
    }

    public function getData(): array
    {
        $startsAt = $this->ad->starts_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');
        $endsAt = $this->ad->ends_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        return [
            'adId' => (int) $this->ad->id,
            'title' => (string) $this->ad->title,
            'slotLabel' => AdvertisingSettings::slotLabel((string) $this->ad->slot_key),
            'status' => (string) $this->ad->status,
            'totalPrice' => (int) $this->ad->total_price,
            'reviewNote' => (string) ($this->ad->review_note ?? ''),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'reservedAt' => $this->ad->reserved_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'estimatedStartAt' => $this->ad->reservation_estimated_start_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            'waitUntil' => $this->ad->reservation_wait_until?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
        ];
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'lowseekai-advertising::emails.plain.ad-reviewed',
            'html' => 'lowseekai-advertising::emails.html.ad-reviewed',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans(
            match ($this->ad->status) {
                'approved' => 'lowseekai-advertising.email.reviewed.approved_subject',
                'reserved' => 'lowseekai-advertising.email.reviewed.reserved_subject',
                'cancelled', 'expired' => 'lowseekai-advertising.email.reviewed.cancelled_subject',
                default => 'lowseekai-advertising.email.reviewed.rejected_subject',
            },
            ['{title}' => (string) $this->ad->title]
        );
    }

    public static function getType(): string
    {
        return 'lowseekaiAdvertisingAdReviewed';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }
}
