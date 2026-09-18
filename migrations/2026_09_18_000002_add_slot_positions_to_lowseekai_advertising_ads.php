<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads') && ! $schema->hasColumn('lowseekai_advertising_ads', 'slot_position')) {
            $schema->table('lowseekai_advertising_ads', function (Blueprint $table) {
                $table->unsignedSmallInteger('slot_position')->nullable()->after('slot_key');
                $table->index(['slot_key', 'slot_position', 'status'], 'lowseekai_ads_slot_position_status_idx');
            });

            $connection = $schema->getConnection();
            $connection->table('lowseekai_advertising_ads')
                ->whereNull('slot_position')
                ->whereIn('status', ['pending', 'approved'])
                ->orderBy('slot_key')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('slot_key')
                ->each(function ($ads, $slotKey) use ($connection) {
                    $capacity = $slotKey === 'top' ? 3 : 10;
                    foreach ($ads->values()->take($capacity) as $index => $ad) {
                        $connection->table('lowseekai_advertising_ads')
                            ->where('id', $ad->id)
                            ->update(['slot_position' => $index + 1]);
                    }
                });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads') && $schema->hasColumn('lowseekai_advertising_ads', 'slot_position')) {
            $schema->table('lowseekai_advertising_ads', function (Blueprint $table) {
                $table->dropIndex('lowseekai_ads_slot_position_status_idx');
                $table->dropColumn('slot_position');
            });
        }
    },
];
