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
        return [
            'adId' => (int) $this->ad->id,
            'title' => (string) $this->ad->title,
            'slotLabel' => AdvertisingSettings::SLOT_LABELS[$this->ad->slot_key] ?? (string) $this->ad->slot_key,
            'slotPosition' => $this->ad->slot_position !== null ? (int) $this->ad->slot_position : null,
            'status' => (string) $this->ad->status,
            'totalPrice' => (int) $this->ad->total_price,
            'reviewNote' => (string) ($this->ad->review_note ?? ''),
            'endsAt' => $this->ad->ends_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
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
            $this->ad->status === 'approved'
                ? 'lowseekai-advertising.email.reviewed.approved_subject'
                : 'lowseekai-advertising.email.reviewed.rejected_subject',
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
