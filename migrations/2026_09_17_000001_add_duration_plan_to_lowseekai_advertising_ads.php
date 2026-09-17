<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads') && ! $schema->hasColumn('lowseekai_advertising_ads', 'duration_plan')) {
            $schema->table('lowseekai_advertising_ads', function (Blueprint $table) {
                $table->string('duration_plan', 30)->nullable()->after('duration_days');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads') && $schema->hasColumn('lowseekai_advertising_ads', 'duration_plan')) {
            $schema->table('lowseekai_advertising_ads', function (Blueprint $table) {
                $table->dropColumn('duration_plan');
            });
        }
    },
];
