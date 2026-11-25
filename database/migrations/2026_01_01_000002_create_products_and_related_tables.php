<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('slug', 255)->nullable()->unique('uk_products_slug');
                $table->longText('description')->nullable();
                $table->string('material', 255)->nullable();
                $table->string('category', 100)->nullable()->default('Plaxtilineas')->index('idx_products_category');
                $table->longText('options')->nullable();
                $table->integer('stock')->default(15);
                $table->boolean('isNew')->default(true);
                $table->boolean('isFeatured')->default(false);
                $table->string('marca', 255)->nullable();
                $table->string('gramaje', 100)->nullable();
                $table->longText('brandIconUrl')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
                $table->foreignId('subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('url', 1024);
                $table->text('description')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (!Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('name', 255);
                $table->string('sku', 100)->nullable()->index('idx_variant_sku');
                $table->boolean('available')->default(true);
                $table->integer('stock')->default(15);
                $table->decimal('price', 10, 2)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_colors')) {
            Schema::create('product_colors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('color', 100);
                $table->timestamp('created_at')->nullable()->useCurrent();
            });
        }

        if (!Schema::hasTable('inventario_eventos')) {
            Schema::create('inventario_eventos', function (Blueprint $table) {
                $table->id();
                $table->integer('producto_id');
                $table->integer('stock_nuevo');
                $table->string('origen', 50)->default('ecommerce');
                $table->timestamp('creado_at')->nullable()->useCurrent()->index('idx_inv_eventos_creado_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_eventos');
        Schema::dropIfExists('product_colors');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};

