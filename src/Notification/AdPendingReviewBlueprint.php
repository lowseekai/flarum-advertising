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

class AdPendingReviewBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public Ad $ad,
        public User $applicant,
    ) {
    }

    public function getFromUser(): ?User
    {
        return $this->applicant;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->applicant;
    }

    public function getData(): array
    {
        return $this->payload() + [
            'applicantId' => (int) $this->applicant->id,
            'applicantName' => (string) ($this->applicant->display_name ?: $this->applicant->username),
        ];
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'lowseekai-advertising::emails.plain.ad-pending-review',
            'html' => 'lowseekai-advertising::emails.html.ad-pending-review',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('lowseekai-advertising.email.pending_review.subject', [
            '{title}' => (string) $this->ad->title,
        ]);
    }

    public static function getType(): string
    {
        return 'lowseekaiAdvertisingAdPendingReview';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    protected function payload(): array
    {
        return [
            'adId' => (int) $this->ad->id,
            'title' => (string) $this->ad->title,
            'slotLabel' => AdvertisingSettings::SLOT_LABELS[$this->ad->slot_key] ?? (string) $this->ad->slot_key,
            'slotPosition' => $this->ad->slot_position !== null ? (int) $this->ad->slot_position : null,
            'totalPrice' => (int) $this->ad->total_price,
        ];
    }
}
