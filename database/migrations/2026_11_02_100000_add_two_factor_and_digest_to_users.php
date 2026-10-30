<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İki mərhələli giriş (TOTP: Google / Microsoft Authenticator) və gündəlik e-mail xülasəsi.
 * Açar və ehtiyat kodlar APP_KEY ilə şifrələnmiş saxlanılır; last_step eyni kodun təkrar işlədilməsinin qarşısını alır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
            $table->boolean('digest_enabled')->default(false);
            $table->date('digest_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step', 'digest_enabled', 'digest_sent_on']);
        });
    }
};
