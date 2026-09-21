<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('lowseekai_advertising_ads')) {
            return;
        }

        $schema->table('lowseekai_advertising_ads', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('lowseekai_advertising_ads', 'is_reservation')) {
                $table->boolean('is_reservation')->default(false)->after('slot_position');
                $table->timestamp('reserved_at')->nullable()->after('reviewed_at');
                $table->timestamp('reservation_estimated_start_at')->nullable()->after('reserved_at');
                $table->timestamp('reservation_wait_until')->nullable()->after('reservation_estimated_start_at');
                $table->unsignedSmallInteger('reservation_deferred_count')->default(0)->after('reservation_wait_until');
                $table->unsignedInteger('refund_transaction_id')->nullable()->after('point_transaction_id');
                $table->timestamp('reservation_cancelled_at')->nullable()->after('reservation_deferred_count');
                $table->index(['is_reservation', 'status', 'slot_key', 'reviewed_at'], 'lowseekai_ads_reservation_queue_idx');
                $table->index(['status', 'is_reservation', 'reservation_wait_until'], 'lowseekai_ads_reservation_wait_idx');
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('lowseekai_advertising_ads') || ! $schema->hasColumn('lowseekai_advertising_ads', 'is_reservation')) {
            return;
        }

        $schema->table('lowseekai_advertising_ads', function (Blueprint $table) {
            $table->dropIndex('lowseekai_ads_reservation_queue_idx');
            $table->dropIndex('lowseekai_ads_reservation_wait_idx');
            $table->dropColumn([
                'is_reservation',
                'reserved_at',
                'reservation_estimated_start_at',
                'reservation_wait_until',
                'reservation_deferred_count',
                'refund_transaction_id',
                'reservation_cancelled_at',
            ]);
        });
    },
];
