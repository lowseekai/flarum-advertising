<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads')) {
            return;
        }

        $schema->create('lowseekai_advertising_ads', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('slot_key', 40)->default('sidebar');
            $table->string('title', 120);
            $table->string('image_path', 500);
            $table->string('target_url', 500);
            $table->unsignedSmallInteger('duration_days')->default(7);
            $table->unsignedInteger('price_per_day')->default(0);
            $table->unsignedInteger('total_price')->default(0);
            $table->unsignedInteger('point_transaction_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('is_visible')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['slot_key', 'status', 'is_visible'], 'lowseekai_ads_slot_status_visible_idx');
            $table->index(['ends_at', 'status'], 'lowseekai_ads_ends_status_idx');
            $table->index('user_id', 'lowseekai_ads_user_idx');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('lowseekai_advertising_ads');
    },
];
