<?php

declare(strict_types=1);

namespace Lowseekai\Advertising\Support;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Lowseekai\Advertising\Model\Ad;

class AdvertisingAutoGroupManager
{
    private const TABLE = 'lowseekai_advertising_auto_group_grants';

    public function __construct(
        protected AdvertisingSettings $settings,
        protected ConnectionInterface $db,
    ) {
    }

    public function sync(Ad $ad): void
    {
        $groupId = $this->settings->autoGroupId();

        if (! $this->settings->autoGroupEnabled() || $groupId === null) {
            $this->releaseAd($ad);

            return;
        }

        $this->releaseAdFromOtherGroups($ad, $groupId);

        $eligible = $ad->status === 'approved'
            && (bool) $ad->is_visible
            && (! $ad->starts_at || $ad->starts_at->lte(Carbon::now('Asia/Shanghai')))
            && (! $ad->ends_at || $ad->ends_at->gt(Carbon::now('Asia/Shanghai')));

        if (! $eligible) {
            $this->releaseAd($ad);

            return;
        }

        $tracked = $this->db->table(self::TABLE)
            ->where('ad_id', (int) $ad->id)
            ->where('group_id', $groupId)
            ->first();
        $hasAnyTrackedGrant = $this->db->table(self::TABLE)
            ->where('user_id', (int) $ad->user_id)
            ->where('group_id', $groupId)
            ->exists();
        $wasExisting = $tracked
            ? (bool) $tracked->was_existing
            : ! $hasAnyTrackedGrant && $this->db->table('group_user')
                ->where('user_id', (int) $ad->user_id)
                ->where('group_id', $groupId)
                ->exists();

        $this->db->table('group_user')->insertOrIgnore([
            'user_id' => (int) $ad->user_id,
            'group_id' => $groupId,
        ]);

        $now = Carbon::now();
        if ($tracked) {
            $this->db->table(self::TABLE)
                ->where('id', (int) $tracked->id)
                ->update(['user_id' => (int) $ad->user_id, 'updated_at' => $now]);
        } else {
            $this->db->table(self::TABLE)->insert([
                'ad_id' => (int) $ad->id,
                'group_id' => $groupId,
                'user_id' => (int) $ad->user_id,
                'was_existing' => $wasExisting,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function reconcile(): void
    {
        $grants = $this->db->table(self::TABLE)->get();

        foreach ($grants as $grant) {
            $ad = Ad::query()->find((int) $grant->ad_id);

            if ($ad) {
                $this->sync($ad);
            } else {
                $this->releaseGrant($grant);
            }
        }

        if (! $this->settings->autoGroupEnabled()) {
            return;
        }

        Ad::query()
            ->where('status', 'approved')
            ->get()
            ->each(fn (Ad $ad) => $this->sync($ad));
    }

    public function releaseAd(Ad $ad): void
    {
        $grants = $this->db->table(self::TABLE)
            ->where('ad_id', (int) $ad->id)
            ->get();

        foreach ($grants as $grant) {
            $this->releaseGrant($grant);
        }
    }

    protected function releaseAdFromOtherGroups(Ad $ad, int $activeGroupId): void
    {
        $grants = $this->db->table(self::TABLE)
            ->where('ad_id', (int) $ad->id)
            ->where('group_id', '<>', $activeGroupId)
            ->get();

        foreach ($grants as $grant) {
            $this->releaseGrant($grant);
        }
    }

    protected function releaseGrant(object $grant): void
    {
        $this->db->table(self::TABLE)->where('id', (int) $grant->id)->delete();

        $hasOtherGrant = $this->db->table(self::TABLE)
            ->where('user_id', (int) $grant->user_id)
            ->where('group_id', (int) $grant->group_id)
            ->exists();

        $wasExisting = (bool) $grant->was_existing || (bool) $this->db->table(self::TABLE)
            ->where('user_id', (int) $grant->user_id)
            ->where('group_id', (int) $grant->group_id)
            ->where('was_existing', true)
            ->exists();

        if (! $hasOtherGrant && ! $wasExisting) {
            $this->db->table('group_user')
                ->where('user_id', (int) $grant->user_id)
                ->where('group_id', (int) $grant->group_id)
                ->delete();
        }
    }
}
