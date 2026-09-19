<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads')) {
            $schema->getConnection()
                ->table('lowseekai_advertising_ads')
                ->where('slot_key', 'sidebar')
                ->update(['slot_key' => 'right_sidebar']);
        }

        if (! $schema->hasTable('lowseekai_advertising_auto_group_grants')) {
            $schema->create('lowseekai_advertising_auto_group_grants', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('group_id');
                $table->unsignedInteger('ad_id');
                $table->boolean('was_existing')->default(false);
                $table->timestamps();

                $table->unique(['ad_id', 'group_id'], 'lowseekai_advertising_auto_group_ad_unique');
                $table->index(['user_id', 'group_id'], 'lowseekai_advertising_auto_group_user_idx');
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_auto_group_grants')) {
            $schema->drop('lowseekai_advertising_auto_group_grants');
        }

        if ($schema->hasTable('lowseekai_advertising_ads')) {
            $schema->getConnection()
                ->table('lowseekai_advertising_ads')
                ->where('slot_key', 'right_sidebar')
                ->update(['slot_key' => 'sidebar']);
        }
    },
];
