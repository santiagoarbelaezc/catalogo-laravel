<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Healthcheck (sin DB)
Route::get('/health', fn () => response()->json([
    'success' => true,
    'status' => 'OK',
    'message' => 'API Laravel funcionando correctamente',
]));

// Auth públicas
Route::post('/auth/login', [AuthController::class, 'login']);

// Auth protegidas
Route::middleware('jwt.auth')->group(function () {
    Route::get('/auth/profile', [AuthController::class, 'profile']);
});

Route::middleware(['jwt.auth:admin', 'throttle:admin'])->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);

    // Productos (admin)
    Route::post('/productos', [ProductController::class, 'store']);
    Route::match(['put', 'post'], '/productos/{id}', [ProductController::class, 'update'])->whereNumber('id');
    Route::delete('/productos/{id}', [ProductController::class, 'destroy'])->whereNumber('id');

    // Categorías y Subcategorías (admin)
    Route::post('/categorias', [CategoryController::class, 'store']);
    Route::put('/categorias/{id}', [CategoryController::class, 'update'])->whereNumber('id');
    Route::delete('/categorias/{id}', [CategoryController::class, 'destroy'])->whereNumber('id');
    Route::post('/subcategorias', [CategoryController::class, 'storeSubcategory']);
    Route::put('/subcategorias/{id}', [CategoryController::class, 'updateSubcategory'])->whereNumber('id');
    Route::delete('/subcategorias/{id}', [CategoryController::class, 'destroySubcategory'])->whereNumber('id');

    // IA (admin)
    Route::post('/ia/generar-descripcion', [AiController::class, 'generateDescription']);
    Route::post('/ia/sugerir-subcategoria', [AiController::class, 'suggestSubcategory']);
    Route::post('/ia/generar-sku', [AiController::class, 'generateSku']);
});

// Productos y Categorías públicos (protegidos con caché + rate limit)
Route::middleware('throttle:catalog')->group(function () {
    Route::get('/productos', [ProductController::class, 'index']);
    Route::get('/productos/slug/{slug}', [ProductController::class, 'showBySlug']);
    Route::get('/productos/categoria/{category}', [ProductController::class, 'byCategory'])->where('category', '.*');
    Route::get('/productos/{id}', [ProductController::class, 'show'])->whereNumber('id');

    Route::get('/categorias', [CategoryController::class, 'index']);
    Route::get('/categorias/{id}/subcategorias', [CategoryController::class, 'subcategories'])->whereNumber('id');
});

// Inventario en tiempo real
Route::middleware('throttle:inventory')->group(function () {
    Route::get('/inventario/cambios', [InventoryController::class, 'changes']);
    Route::get('/inventario/stream', [InventoryController::class, 'stream']);
});

// Integraciones inventario (token especial)
Route::middleware('inventory.token')->group(function () {
    Route::put('/integraciones/inventario/productos/{id}', [ProductController::class, 'updateInventoryStock'])->whereNumber('id');
    Route::post('/integraciones/inventario/productos/{id}/ajustar', [ProductController::class, 'adjustInventoryStock'])->whereNumber('id');
});

// Backup por token secreto
Route::get('/backup/run', [BackupController::class, 'run']);

