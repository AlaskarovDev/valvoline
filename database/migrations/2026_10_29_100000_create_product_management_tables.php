<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Məhsul idarəetməsi: məlumat kitabçaları (marka, istehsalçı, sinif, vahid, qiymət növü),
 * məhsulun vahidləri (əsas vahid + qutu/koli əmsalı), barkodlar və tarixli qiymət kartları
 * (satış / alış / müştəriyə özəl, ƏDV daxil və ya xaric).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('country', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('product_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();       // ədəd, qutu, litr, kq, koli
            $table->string('code', 16)->nullable();     // e-qaimə ölçü vahidi kodu
            $table->timestamps();
        });
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();           // Satış (baza), Topdan, Diler, Alış
            $table->string('type', 10)->default('sale'); // sale | purchase
            $table->char('currency', 3)->default('AZN');
            $table->boolean('vat_included')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->after('name')->index();
            $table->foreignId('brand_id')->nullable()->after('barcode')->constrained()->nullOnDelete();
            $table->foreignId('manufacturer_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
            $table->foreignId('product_class_id')->nullable()->after('manufacturer_id')->constrained()->nullOnDelete();
            $table->foreignId('base_unit_id')->nullable()->after('product_class_id')->constrained('units')->nullOnDelete();
            $table->decimal('vat_rate', 5, 2)->default(18);                 // ƏDV dərəcəsi, %
            $table->string('vat_mode', 12)->default('standard');            // e-qaimə: standard (18 %) | zero (0 %) | exempt (azad) | not_subject (cəlb olunmayan)
            $table->decimal('excise_rate', 6, 2)->nullable();                // aksiz, % (adətən istehsalçı/idxalçıda)
            $table->string('short_name', 100)->nullable();                  // qaimə üçün qısa ad
            $table->string('specifications')->nullable();                   // API SP, ACEA C3, OEM təsdiqləri
            $table->string('application', 100)->nullable();                 // minik / ağır texnika / transmissiya / sənaye
            $table->string('hs_code', 20)->nullable();                      // e-qaimə «Malın kodu» (XİF MN / ХТН, məs. 2710.19)
            $table->string('country_of_origin', 64)->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->unsignedSmallInteger('shelf_life_months')->nullable();
            $table->decimal('max_stock_qty', 14, 4)->nullable();
            $table->decimal('min_price', 14, 4)->nullable();                // bu qiymətdən aşağı satılmır
            $table->decimal('max_discount_pct', 6, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('source', 10)->default('import');                // import | manual
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('factor', 14, 4);           // 1 bu vahid = factor əsas vahid (1 qutu = 12 ədəd)
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->boolean('is_sale_default')->default(false);
            $table->boolean('is_purchase_default')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->cascadeOnDelete(); // null = əsas vahid
            $table->string('barcode', 64)->unique();
            $table->timestamps();
        });

        Schema::create('price_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10)->default('sale');              // sale | purchase
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete(); // müştəriyə özəl
            $table->foreignId('product_unit_id')->nullable()->constrained()->cascadeOnDelete(); // null = əsas vahid
            $table->decimal('price', 14, 4);
            $table->char('currency', 3)->default('AZN');
            $table->boolean('vat_included')->default(true);
            $table->decimal('min_qty', 14, 4)->default(0);           // həcm (miqdar) qiyməti
            $table->date('valid_from')->index();
            $table->date('valid_to')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'type']);
            $table->index('customer_id');
        });

        Schema::create('product_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alternative_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'alternative_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
        });

        // ilkin kitabçalar: vahidlər və qiymət növləri; mövcud satış qiyməti baza kartına çevrilir
        $now = now();
        foreach ([['ədəd', 'ədəd'], ['qutu', 'qutu'], ['koli', 'koli'], ['litr', 'litr'], ['kq', 'kq'], ['bidon', 'bidon'], ['çəllək', 'çəllək']] as [$name, $code]) {
            DB::table('units')->insert(['name' => $name, 'code' => $code, 'created_at' => $now, 'updated_at' => $now]);
        }
        $sale = DB::table('price_lists')->insertGetId(['name' => 'Satış qiyməti (baza)', 'type' => 'sale', 'vat_included' => true, 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('price_lists')->insert([
            ['name' => 'Topdan', 'type' => 'sale', 'vat_included' => true, 'is_default' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Alış qiyməti', 'type' => 'purchase', 'vat_included' => true, 'is_default' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $piece = DB::table('units')->where('name', 'ədəd')->value('id');
        $box = DB::table('units')->where('name', 'qutu')->value('id');
        DB::table('products')->update(['base_unit_id' => $piece]);
        $brand = DB::table('brands')->insertGetId(['name' => 'Valvoline', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('products')->update(['brand_id' => $brand]);
        foreach (DB::table('products')->get(['id', 'sale_price', 'pack_qty']) as $p) {
            if ((float) $p->sale_price > 0) {
                DB::table('price_cards')->insert([
                    'product_id' => $p->id, 'price_list_id' => $sale, 'type' => 'sale', 'price' => $p->sale_price,
                    'currency' => 'AZN', 'vat_included' => true, 'valid_from' => '2000-01-01', 'is_active' => true,
                    'note' => 'Mallar faylından', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if ((int) $p->pack_qty > 1) {
                DB::table('product_units')->insert(['product_id' => $p->id, 'unit_id' => $box, 'factor' => $p->pack_qty, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });
        Schema::dropIfExists('product_alternatives');
        Schema::dropIfExists('price_cards');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_units');
        Schema::table('products', function (Blueprint $table) {
            foreach (['base_unit_id', 'product_class_id', 'manufacturer_id', 'brand_id'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropIndex(['barcode']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['barcode', 'vat_rate', 'vat_mode', 'excise_rate', 'short_name', 'specifications', 'application', 'hs_code', 'country_of_origin', 'weight_kg', 'shelf_life_months',
                'max_stock_qty', 'min_price', 'max_discount_pct', 'description', 'image_path', 'is_active', 'source']);
        });
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('units');
        Schema::dropIfExists('product_classes');
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('brands');
    }
};
