<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satış nümayəndəsinin aylıq hədəfləri (satış və inkasso). Ay üçün hədəf yoxdursa kartdakı monthly_target götürülür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rep_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('sales_target', 14, 2)->nullable();
            $table->decimal('collection_target', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['manager_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_targets');
    }
};
