<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P&L «Cədvəl» görünüşündə ayın əvvəlinə qalıq — istifadəçi əl ilə daxil edir.
 * Daxil edilməyən ay əvvəlki ayın son qalığından davam edir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnl_openings', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique();
            $table->decimal('amount', 16, 4);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnl_openings');
    }
};
