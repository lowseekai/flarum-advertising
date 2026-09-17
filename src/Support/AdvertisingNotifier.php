<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Flarum\Group\Group;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Lowseekai\Advertising\Model\Ad;
use Lowseekai\Advertising\Notification\AdPendingReviewBlueprint;
use Lowseekai\Advertising\Notification\AdReviewedBlueprint;

class AdvertisingNotifier
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected ConnectionInterface $db,
    ) {
    }

    public function notifyPendingReview(Ad $ad, User $applicant): void
    {
        $recipients = $this->reviewRecipients($applicant);

        if ($recipients === []) {
            return;
        }

        $this->notifications->sync(new AdPendingReviewBlueprint($ad, $applicant), $recipients);
    }

    public function notifyReviewed(Ad $ad, User $reviewer): void
    {
        $ad->loadMissing('user');

        if (! $ad->user || (int) $ad->user->id === (int) $reviewer->id) {
            return;
        }

        $this->notifications->sync(new AdReviewedBlueprint($ad, $reviewer), [$ad->user]);
    }

    protected function reviewRecipients(User $applicant): array
    {
        $groupIds = $this->db->table('group_permission')
            ->where('permission', 'lowseekai-advertising.manage')
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->push(Group::ADMINISTRATOR_ID)
            ->unique()
            ->values()
            ->all();

        if ($groupIds === []) {
            return [];
        }

        return User::query()
            ->where('id', '<>', (int) $applicant->id)
            ->whereHas('groups', fn ($query) => $query->whereIn('groups.id', $groupIds))
            ->get()
            ->all();
    }
}
