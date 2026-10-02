<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->unsignedSmallInteger('api_port')->default(8728);
            $table->unsignedSmallInteger('snmp_port')->default(161);
            $table->boolean('enabled')->default(false);
            $table->timestampsTz();
        });

        Schema::create('hotspot_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->restrictOnDelete();
            $table->string('username');
            $table->string('profile')->nullable();
            $table->boolean('disabled')->default(false);
            $table->text('comment')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['router_id', 'username']);
        });

        Schema::create('hotspot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->restrictOnDelete();
            $table->foreignId('hotspot_user_id')->constrained()->restrictOnDelete();
            $table->string('username');
            $table->ipAddress('ip_address');
            $table->string('mac_address', 17);
            $table->string('routeros_id')->nullable();
            $table->string('server_name')->nullable();
            $table->string('login_by')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('missing_polls')->default(0);
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            foreach (['bytes_in', 'bytes_out', 'packets_in', 'packets_out'] as $counter) {
                $table->unsignedBigInteger('mikrotik_'.$counter)->default(0);
            }
            $table->timestampsTz();
            $table->index(['router_id', 'ip_address', 'started_at']);
            $table->index(['hotspot_user_id', 'started_at']);
            $table->index('ended_at');
            $table->index('mac_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_sessions');
        Schema::dropIfExists('hotspot_users');
        Schema::dropIfExists('routers');
    }
};
