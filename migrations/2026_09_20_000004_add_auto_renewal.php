<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('lowseekai_advertising_ads')) {
            $schema->table('lowseekai_advertising_ads', function (Blueprint $table) use ($schema) {
                if (! $schema->hasColumn('lowseekai_advertising_ads', 'auto_renew_enabled')) {
                    $table->boolean('auto_renew_enabled')->default(false)->after('total_price');
                    $table->unsignedInteger('auto_renew_price')->nullable()->after('auto_renew_enabled');
                    $table->string('auto_renew_status', 20)->default('disabled')->after('auto_renew_price');
                    $table->timestamp('auto_renew_last_attempt_at')->nullable()->after('auto_renew_status');
                    $table->text('auto_renew_failure_reason')->nullable()->after('auto_renew_last_attempt_at');
                    $table->timestamp('auto_renew_disabled_at')->nullable()->after('auto_renew_failure_reason');
                    $table->index(
                        ['auto_renew_enabled', 'auto_renew_status', 'ends_at'],
                        'lowseekai_ads_auto_renew_due_idx'
                    );
                }
            });
        }

        if (! $schema->hasTable('lowseekai_advertising_renewals')) {
            $schema->create('lowseekai_advertising_renewals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('ad_id');
                $table->unsignedInteger('user_id');
                $table->string('cycle_key', 64);
                $table->unsignedInteger('amount')->default(0);
                $table->unsignedSmallInteger('duration_days')->default(30);
                $table->string('status', 20)->default('pending');
                $table->unsignedInteger('point_transaction_id')->nullable();
                $table->timestamp('previous_ends_at')->nullable();
                $table->timestamp('new_ends_at')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('attempted_at')->nullable();
                $table->timestamps();

                $table->unique(['ad_id', 'cycle_key'], 'lowseekai_advertising_renewal_cycle_unique');
                $table->index(['status', 'created_at'], 'lowseekai_advertising_renewal_status_idx');
                $table->index('user_id', 'lowseekai_advertising_renewal_user_idx');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('lowseekai_advertising_renewals');

        if ($schema->hasTable('lowseekai_advertising_ads') && $schema->hasColumn('lowseekai_advertising_ads', 'auto_renew_enabled')) {
            $schema->table('lowseekai_advertising_ads', function (Blueprint $table) {
                $table->dropIndex('lowseekai_ads_auto_renew_due_idx');
                $table->dropColumn([
                    'auto_renew_enabled',
                    'auto_renew_price',
                    'auto_renew_status',
                    'auto_renew_last_attempt_at',
                    'auto_renew_failure_reason',
                    'auto_renew_disabled_at',
                ]);
            });
        }
    },
];
