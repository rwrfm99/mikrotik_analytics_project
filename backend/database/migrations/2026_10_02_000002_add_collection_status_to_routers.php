<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->timestampTz('collector_last_success_at')->nullable();
            $table->timestampTz('collector_last_attempt_at')->nullable();
            $table->timestampTz('users_synced_at')->nullable();
            $table->string('collector_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', fn (Blueprint $table) => $table->dropColumn([
            'collector_last_success_at', 'collector_last_attempt_at', 'users_synced_at', 'collector_error',
        ]));
    }
};
