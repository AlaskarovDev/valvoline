<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kampaniyalar (reporting-portal kampaniya modulunun sistem daxili versiyası):
 * növ + şərt (hədd / pillələr / hədiyyə), müştəri və mal hədəfi, tarix, prioritet, eksklüziv qrup,
 * müştəri üzrə tətbiq limiti və təsdiq axını. Sifarişə tətbiq olunan kampaniya sətrin altında saxlanılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            // simple_discount | qty_threshold_discount | amount_threshold_discount | tiered_discount | invoice_bottom_discount
            // | qty_gift | amount_gift | max_discount_cap | target_price
            $table->string('kind', 32)->index();
            $table->string('calc_basis', 8)->default('qty');          // qty | amount
            $table->string('formula_base', 2)->default('P3');         // P3 = əvvəlki endirimlərdən sonra, P1 = brüt
            $table->decimal('discount_rate', 7, 4)->nullable();       // %
            $table->decimal('threshold_value', 15, 4)->nullable();
            $table->json('tiers')->nullable();                        // [{from, to|null, value}] value = % və ya hədiyyə miqdarı
            $table->string('tier_calc', 8)->default('percent');       // percent | cap
            $table->string('tier_scope', 12)->default('products');    // products | document
            $table->string('gift_mode', 16)->nullable();              // null=proporsional | tiered | tiered_multiple
            $table->foreignId('gift_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('gift_qty', 15, 4)->nullable();           // M
            $table->decimal('gift_divisor', 15, 4)->nullable();       // N: hər N-ə M hədiyyə
            $table->decimal('vat_multiplier', 6, 4)->default(1);      // məbləğ həddinə tətbiq olunan ƏDV əmsalı
            $table->string('product_scope', 12)->default('all');      // all | products | groups | categories | series
            $table->json('product_filter')->nullable();               // products: [{id, rate?, price?}], digərləri: [id|series]
            $table->json('excluded_products')->nullable();            // [id]
            $table->string('customer_scope', 12)->default('all');     // all | customers | managers
            $table->json('customer_filter')->nullable();              // [id]
            $table->json('customer_excludes')->nullable();            // [customer id]
            $table->date('begin_date')->index();
            $table->date('end_date')->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('priority_group', 30)->nullable();         // eyni qrupdan yalnız bir kampaniya tətbiq olunur
            $table->unsignedInteger('apply_limit')->default(0);       // müştəri üzrə sifariş limiti, 0 = limitsiz
            $table->decimal('estimated_budget', 15, 2)->nullable();
            $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected | cancelled
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_line_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campaign_code', 30);
            $table->string('campaign_name', 200);
            $table->decimal('amount', 15, 4);                         // endirim məbləği
            $table->decimal('rate', 9, 4);                            // brüt məbləğə nisbətdə %
            $table->timestamps();
            $table->index('campaign_id');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('campaigns_applied')->default(false)->after('source');
            $table->json('campaigns_excluded')->nullable()->after('campaigns_applied');
        });
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->decimal('campaign_discount', 14, 4)->default(0)->after('discount_price');
            // hədiyyə sətri kampaniyadan yaranıbsa
            $table->foreignId('campaign_id')->nullable()->after('op_type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn('campaign_discount');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['campaigns_applied', 'campaigns_excluded']);
        });
        Schema::dropIfExists('sales_line_campaigns');
        Schema::dropIfExists('campaigns');
    }
};
