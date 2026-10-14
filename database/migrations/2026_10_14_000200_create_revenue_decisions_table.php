<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rəsmi (bank) daxilolmaların P&L-ə münasibəti, ödəyici üzrə:
 * included — satışı sistemdə artıq var (təkrar sayılmır), extra — sistemdə olmayan satış (P&L-ə əlavə), not_revenue — gəlir deyil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('normalized')->unique();
            $table->string('counterparty');
            $table->string('decision', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_decisions');
    }
};
