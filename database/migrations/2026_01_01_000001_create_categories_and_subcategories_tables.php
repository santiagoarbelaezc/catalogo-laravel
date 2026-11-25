<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('brand', 100)->default('Plaxtilineas')->index('idx_categories_brand');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('subcategories')) {
            Schema::create('subcategories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->string('name', 255);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategories');
        Schema::dropIfExists('categories');
    }
};

