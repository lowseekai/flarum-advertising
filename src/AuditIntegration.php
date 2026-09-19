<?php

declare(strict_types=1);

namespace Lowseekai\Advertising;

use Flarum\Audit\AuditLogger;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use Lowseekai\Advertising\Model\Ad;

class AuditIntegration
{
    public static array $actions = [
        'advertising.ad_updated',
    ];

    public function __invoke(Container $container): void
    {
        $events = $container->make(Dispatcher::class);
        $events->listen('eloquent.updated: '.Ad::class, [$this, 'updated']);
    }

    public function updated(Ad $ad): void
    {
        $changes = Arr::only($ad->getChanges(), [
            'title',
            'image_path',
            'target_url',
            'slot_key',
            'slot_position',
            'duration_plan',
            'duration_days',
            'status',
            'is_visible',
            'review_note',
            'starts_at',
            'ends_at',
        ]);

        if ($changes === []) {
            return;
        }

        $before = [];
        foreach (array_keys($changes) as $key) {
            $before[$key] = $ad->getOriginal($key);
        }

        AuditLogger::log('advertising.ad_updated', [
            'ad_id' => (int) $ad->id,
            'before' => $before,
            'after' => $changes,
        ]);
    }
}
