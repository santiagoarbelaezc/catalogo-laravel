<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryEvent;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\CatalogCacheService;
use App\Services\CloudinaryService;
use App\Services\NotificadorPlaxtilineasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProductController extends Controller
{
    public function __construct(
        private CloudinaryService $cloudinary,
        private NotificadorPlaxtilineasService $notificador,
        private CatalogCacheService $catalogCache
    ) {}

    public function index(Request $request): JsonResponse
    {
        $categoryParam = $request->query('categoria') ?? $request->query('category') ?? $request->query('category_id');
        $brandParam = $request->query('brand') ?? $request->query('marca');

        $suffix = (empty($categoryParam) && empty($brandParam))
            ? 'products_all'
            : 'products_filter_' . md5(json_encode([(string) $categoryParam, (string) $brandParam]));

        return $this->catalogCache->respond($request, $suffix, function () use ($categoryParam, $brandParam) {
            $query = Product::with(['categoryRelation', 'subcategory', 'images', 'colors', 'variants'])
                ->orderByDesc('id');

            if (!empty($categoryParam)) {
                if (is_numeric($categoryParam)) {
                    $query->where(function ($q) use ($categoryParam) {
                        $q->where('category_id', $categoryParam)
                          ->orWhere('category', $categoryParam);
                    });
                } else {
                    $query->where(function ($q) use ($categoryParam) {
                        $q->where('category', $categoryParam)
                          ->orWhereHas('categoryRelation', function ($cq) use ($categoryParam) {
                              $cq->where('name', $categoryParam)->orWhere('brand', $categoryParam);
                          });
                    });
                }
            }

            if (!empty($brandParam)) {
                $query->where(function ($q) use ($brandParam) {
                    $q->where('marca', $brandParam)
                      ->orWhereHas('categoryRelation', fn ($cq) => $cq->where('brand', $brandParam));
                });
            }

            $products = $query->get()->map(fn (Product $p) => $this->formatProduct($p))->values()->all();

            return [
                'success' => true,
                'message' => 'Productos obtenidos',
                'data' => $products,
            ];
        });
    }

    public function byCategory(Request $request, string $category): JsonResponse
    {
        $category = trim(urldecode($category));
        if ($category === '') {
            return response()->json(['success' => false, 'message' => 'La categoría es requerida'], 400);
        }

        $suffix = 'products_cat_' . sha1(mb_strtolower($category));

        return $this->catalogCache->respond($request, $suffix, function () use ($category) {
            $query = Product::with(['categoryRelation', 'subcategory', 'images', 'colors', 'variants'])
                ->orderByDesc('id');

            if (is_numeric($category)) {
                $query->where(function ($q) use ($category) {
                    $q->where('category_id', $category)->orWhere('category', $category);
                });
            } else {
                $query->where(function ($q) use ($category) {
                    $q->where('category', $category)
                      ->orWhereHas('categoryRelation', fn ($cq) => $cq->where('name', $category)->orWhere('brand', $category));
                });
            }

            $products = $query->get()->map(fn (Product $p) => $this->formatProduct($p))->values()->all();

            return [
                'success' => true,
                'message' => "Productos de categoría {$category} obtenidos",
                'data' => $products,
            ];
        });
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'El ID debe ser un número entero positivo'], 400);
        }

        return $this->catalogCache->respond($request, "products_id_{$id}", function () use ($id) {
            $product = Product::with(['categoryRelation', 'subcategory', 'images', 'colors', 'variants'])->find($id);
            if (!$product) {
                return null;
            }

            return [
                'success' => true,
                'message' => 'Producto encontrado',
                'data' => $this->formatProduct($product),
            ];
        }, 'Producto no encontrado');
    }

    public function showBySlug(Request $request, string $slug): JsonResponse
    {
        $slug = trim($slug);
        if ($slug === '') {
            return response()->json(['success' => false, 'message' => 'El slug no puede estar vacío'], 400);
        }

        $suffix = 'products_slug_' . sha1($slug);

        return $this->catalogCache->respond($request, $suffix, function () use ($slug) {
            $product = Product::with(['categoryRelation', 'subcategory', 'images', 'colors', 'variants'])
                ->where('slug', $slug)
                ->first();

            if (!$product) {
                return null;
            }

            return [
                'success' => true,
                'message' => 'Producto encontrado',
                'data' => $this->formatProduct($product),
            ];
        }, 'Producto no encontrado');
    }

    public function store(Request $request): JsonResponse
    {
        $name = $request->input('name');
        $category = $request->input('category');

        if (empty($name) || empty($category)) {
            return response()->json(['success' => false, 'message' => 'Los campos nombre y categoria son requeridos'], 400);
        }

        try {
            $uploadedImages = $this->handleUploadedImages($request);

            $product = DB::transaction(function () use ($request, $name, $category, $uploadedImages) {
                $rawSlug = $request->filled('slug') ? (string) $request->input('slug') : (string) $name;
                $slug = $this->ensureUniqueSlug(Str::slug($rawSlug) ?: 'producto');

                $stock = is_numeric($request->input('stock')) ? max(0, (int) $request->input('stock')) : 15;
                $isNew = $this->toBool($request->input('isNew', true));
                $isFeatured = $this->toBool($request->input('isFeatured', false));
                $brandIconUrl = $this->sanitizeAssetUrl($request->input('brandIconUrl'));

                $product = Product::create([
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $request->input('description'),
                    'material' => $request->input('material'),
                    'category' => $category,
                    'category_id' => $request->input('category_id') ?: null,
                    'subcategory_id' => $request->input('subcategory_id') ?: null,
                    'options' => $request->input('options', ''),
                    'stock' => $stock,
                    'isNew' => $isNew,
                    'isFeatured' => $isFeatured,
                    'marca' => $request->input('marca'),
                    'gramaje' => $request->input('gramaje'),
                    'brandIconUrl' => $brandIconUrl,
                ]);

                $this->syncImages($product->id, $request, $uploadedImages);
                $this->syncColors($product->id, $request->input('colors'));
                $this->syncVariants($product->id, $request->input('variants'), (string) $category);

                return $product;
            });

            if (strcasecmp(trim((string) $category), 'Plaxtilineas') === 0) {
                $this->notificador->notificarProductoNuevo($product->id);
            }

            $this->catalogCache->invalidate();

            return response()->json([
                'success' => true,
                'message' => 'Producto creado exitosamente',
                'data' => ['id' => $product->id],
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Log::error('ProductController::store error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al crear producto',
                'detail' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'El ID debe ser un número entero positivo'], 400);
        }

        $product = Product::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
        }

        try {
            $oldStock = (int) $product->stock;
            $uploadedImages = $this->handleUploadedImages($request);

            $allowedFields = [
                'name', 'slug', 'description', 'material', 'category',
                'category_id', 'subcategory_id', 'options', 'stock',
                'isNew', 'isFeatured', 'marca', 'gramaje', 'brandIconUrl',
            ];

            $updates = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $val = $request->input($field);
                    if (in_array($field, ['category_id', 'subcategory_id'], true) && $val === '') {
                        $val = null;
                    } elseif (in_array($field, ['isNew', 'isFeatured'], true)) {
                        $val = $this->toBool($val);
                    } elseif ($field === 'stock' && is_numeric($val)) {
                        $val = max(0, (int) $val);
                    } elseif ($field === 'brandIconUrl') {
                        $val = $this->sanitizeAssetUrl(is_string($val) ? $val : null);
                    } elseif ($field === 'slug') {
                        $rawSlug = trim((string) $val);
                        if ($rawSlug === '' && $request->filled('name')) {
                            $rawSlug = (string) $request->input('name');
                        }
                        $val = $rawSlug !== '' ? $this->ensureUniqueSlug(Str::slug($rawSlug) ?: 'producto', $id) : $product->slug;
                    }
                    $updates[$field] = $val;
                }
            }

            if (empty($updates) && !$request->has('images') && empty($uploadedImages) && !$request->has('colors') && !$request->has('variants')) {
                return response()->json(['success' => false, 'message' => 'No hay campos para actualizar'], 400);
            }

            DB::transaction(function () use ($product, $updates, $request, $uploadedImages) {
                if (!empty($updates)) {
                    $product->update($updates);
                }

                if ($request->has('images') || !empty($uploadedImages)) {
                    ProductImage::where('product_id', $product->id)->delete();
                    $this->syncImages($product->id, $request, $uploadedImages);
                }

                if ($request->has('colors')) {
                    ProductColor::where('product_id', $product->id)->delete();
                    $this->syncColors($product->id, $request->input('colors'));
                }

                if ($request->has('variants')) {
                    ProductVariant::where('product_id', $product->id)->delete();
                    $this->syncVariants($product->id, $request->input('variants'), (string) ($updates['category'] ?? $product->category));
                }
            });

            if (isset($updates['stock']) && (int) $updates['stock'] !== $oldStock) {
                $this->recordStockEvent($product->id, (int) $updates['stock'], 'admin');
            }

            $this->catalogCache->invalidate();

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado exitosamente',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Log::error('ProductController::update error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar producto',
                'detail' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'El ID debe ser un número entero positivo'], 400);
        }

        $product = Product::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
        }

        $product->delete();
        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado exitosamente',
        ]);
    }

    public function updateInventoryStock(Request $request, int $id): JsonResponse
    {
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'El ID debe ser un número entero positivo'], 422);
        }

        $data = $request->all();
        $unexpected = array_diff(array_keys($data), ['stock']);
        if (!empty($unexpected)) {
            return response()->json(['success' => false, 'message' => 'Solo se permite actualizar el campo stock'], 422);
        }

        if (!array_key_exists('stock', $data) || filter_var($data['stock'], FILTER_VALIDATE_INT) === false || (int) $data['stock'] < 0) {
            return response()->json(['success' => false, 'message' => 'stock debe ser un entero mayor o igual a cero'], 422);
        }

        $product = Product::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
        }

        $newStock = (int) $data['stock'];
        if ((int) $product->stock !== $newStock) {
            $product->update(['stock' => $newStock]);
            ProductVariant::where('product_id', $id)->update(['stock' => $newStock]);
            $this->recordStockEvent($id, $newStock, 'ecommerce');
            $this->catalogCache->invalidate();
        }

        $product->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Stock actualizado',
            'data' => [
                'product_id' => $id,
                'stock' => (int) $product->stock,
                'updated_at' => $product->updated_at,
            ],
        ]);
    }

    public function adjustInventoryStock(Request $request, int $id): JsonResponse
    {
        $startedAt = microtime(true);
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());
        $idempotencyKey = trim((string) ($request->header('X-Idempotency-Key') ?: $request->input('idempotency_key', '')));

        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'El ID debe ser un número entero positivo'], 422);
        }

        $delta = $request->input('delta');
        if ($delta === null || filter_var($delta, FILTER_VALIDATE_INT) === false || (int) $delta === 0) {
            return response()->json(['success' => false, 'message' => 'delta debe ser un entero distinto de cero'], 422);
        }

        $delta = (int) $delta;

        Log::info('[CATALOGO_STOCK] Solicitud de ajuste de stock recibida', [
            'request_id' => $requestId,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'producto_id' => $id,
            'delta' => $delta,
            'ip' => $request->ip(),
            'origin' => $request->header('Origin'),
        ]);

        // Protección de idempotencia (10 min): evita aplicar dos veces la misma operación
        if ($idempotencyKey !== '') {
            $cachedPayload = \Illuminate\Support\Facades\Cache::get("stock_idemp:{$idempotencyKey}");
            if (is_array($cachedPayload)) {
                Log::warning('[CATALOGO_STOCK] Ajuste duplicado bloqueado por idempotency_key', [
                    'request_id' => $requestId,
                    'idempotency_key' => $idempotencyKey,
                    'producto_id' => $id,
                    'delta' => $delta,
                ]);

                return response()->json(array_merge($cachedPayload, ['idempotent_replay' => true]), 200);
            }
        }

        $result = DB::transaction(function () use ($id, $delta) {
            $beforeRow = DB::table('products')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'stock']);

            if (!$beforeRow) {
                return ['status' => 404];
            }

            $stockAntes = (int) ($beforeRow->stock ?? 0);
            $stockDespues = $stockAntes + $delta;

            if ($stockDespues < 0) {
                return [
                    'status' => 409,
                    'stock' => $stockAntes,
                ];
            }

            DB::table('products')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->update(['stock' => $stockDespues, 'updated_at' => now()]);

            ProductVariant::where('product_id', $id)->update(['stock' => $stockDespues]);

            return [
                'status' => 200,
                'stock_antes' => $stockAntes,
                'stock' => $stockDespues,
                'updated_at' => now(),
            ];
        });

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($result['status'] === 404) {
            Log::warning('[CATALOGO_STOCK] Producto no encontrado para ajuste', [
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'producto_id' => $id,
                'delta' => $delta,
                'duracion_ms' => $elapsedMs,
            ]);

            return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
        }

        if ($result['status'] === 409) {
            Log::warning('[CATALOGO_STOCK] Stock insuficiente para ajuste', [
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'producto_id' => $id,
                'delta' => $delta,
                'stock_actual' => $result['stock'],
                'duracion_ms' => $elapsedMs,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Stock insuficiente para realizar el ajuste',
                'data' => [
                    'product_id' => $id,
                    'stock' => $result['stock'],
                    'delta' => $delta,
                ],
            ], 409);
        }

        $this->recordStockEvent($id, $result['stock'], 'ecommerce');
        $this->catalogCache->invalidate();

        $responsePayload = [
            'success' => true,
            'message' => 'Stock ajustado',
            'data' => [
                'product_id' => $id,
                'stock_antes' => $result['stock_antes'],
                'stock' => $result['stock'],
                'delta' => $delta,
                'updated_at' => $result['updated_at'],
            ],
        ];

        if ($idempotencyKey !== '') {
            \Illuminate\Support\Facades\Cache::put("stock_idemp:{$idempotencyKey}", $responsePayload, now()->addMinutes(10));
        }

        Log::info('[CATALOGO_STOCK] Ajuste de stock aplicado correctamente', [
            'request_id' => $requestId,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'producto_id' => $id,
            'delta' => $delta,
            'stock_antes' => $result['stock_antes'],
            'stock_despues' => $result['stock'],
            'duracion_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json($responsePayload);
    }

    private function formatProduct(Product $product): array
    {
        $arr = $product->toArray();
        $arr['category_name'] = $product->categoryRelation?->name;
        $arr['subcategory_name'] = $product->subcategory?->name;
        $arr['isNew'] = (bool) $product->isNew;
        $arr['isFeatured'] = (bool) $product->isFeatured;
        $arr['images'] = $product->images->map(fn ($img) => [
            'url' => $img->url,
            'description' => $img->description,
            'is_primary' => (bool) $img->is_primary,
        ])->values()->all();
        $arr['colors'] = $product->colors->pluck('color')->values()->all();
        $arr['variants'] = $product->variants->toArray();
        unset($arr['category_relation'], $arr['subcategory']);

        return $arr;
    }

    private function handleUploadedImages(Request $request): array
    {
        $files = [];
        foreach (['imagenes', 'imagen'] as $key) {
            if ($request->hasFile($key)) {
                $raw = $request->file($key);
                $files = is_array($raw) ? $raw : [$raw];
                break;
            }
        }

        $uploaded = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                if ($file->getSize() > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('El archivo excede el tamaño máximo permitido de 5MB');
                }
                $uploaded[] = $this->cloudinary->uploadImage($file, 'catalogo_plaxtilineas');
            }
        }

        return $uploaded;
    }

    private function syncImages(int $productId, Request $request, array $uploadedImages): void
    {
        $rawImages = $request->input('images');
        $urlImages = is_string($rawImages) ? json_decode($rawImages, true) : $rawImages;
        $primarySet = false;

        if (!empty($urlImages) && is_array($urlImages)) {
            foreach ($urlImages as $img) {
                $rawUrl = is_array($img) ? ($img['url'] ?? null) : $img;
                $url = $this->sanitizeAssetUrl(is_string($rawUrl) ? $rawUrl : null);
                $desc = is_array($img) ? ($img['description'] ?? null) : null;
                $isPrimary = is_array($img) && (!empty($img['is_primary']) || !empty($img['isPrimary']));
                if ($isPrimary) {
                    $primarySet = true;
                }
                if ($url) {
                    ProductImage::create([
                        'product_id' => $productId,
                        'url' => $url,
                        'description' => $desc,
                        'is_primary' => $isPrimary,
                    ]);
                }
            }
        }

        if (!empty($uploadedImages)) {
            $primaryIdx = (int) ($request->input('primaryFileIndex') ?? $request->input('primary_file_index') ?? -1);
            foreach ($uploadedImages as $idx => $img) {
                $isPrimary = ($idx === $primaryIdx || !empty($img['is_primary']));
                if ($isPrimary) {
                    $primarySet = true;
                }
                ProductImage::create([
                    'product_id' => $productId,
                    'url' => $img['url'],
                    'description' => $img['original_name'] ?? null,
                    'is_primary' => $isPrimary,
                ]);
            }
        }

        if (!$primarySet) {
            ProductImage::where('product_id', $productId)->orderBy('id')->limit(1)->update(['is_primary' => 1]);
        }
    }

    private function syncColors(int $productId, mixed $rawColors): void
    {
        if (empty($rawColors)) {
            return;
        }
        $colors = is_string($rawColors) ? explode(',', $rawColors) : (array) $rawColors;
        $colors = array_filter(array_map('trim', $colors), fn ($c) => $c !== '');
        foreach ($colors as $color) {
            ProductColor::create(['product_id' => $productId, 'color' => $color]);
        }
    }

    private function syncVariants(int $productId, mixed $rawVariants, string $category): void
    {
        if (empty($rawVariants)) {
            return;
        }
        $variants = is_string($rawVariants) ? json_decode($rawVariants, true) : $rawVariants;
        if (!is_array($variants)) {
            return;
        }
        foreach ($variants as $idx => $variant) {
            if (empty($variant['name'])) {
                continue;
            }
            $sku = !empty($variant['sku'])
                ? trim((string) $variant['sku'])
                : $this->generateDefaultSku($category, $productId, $idx + 1);
            $stock = isset($variant['stock']) && is_numeric($variant['stock']) ? max(0, (int) $variant['stock']) : 15;

            ProductVariant::create([
                'product_id' => $productId,
                'name' => $variant['name'],
                'sku' => $sku,
                'available' => isset($variant['available']) ? $this->toBool($variant['available']) : true,
                'stock' => $stock,
                'price' => isset($variant['price']) ? (float) $variant['price'] : 0,
            ]);
        }
    }

    private function sanitizeAssetUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('#^(https?://|/|assets/)#i', $trimmed)) {
            return $trimmed;
        }
        throw new InvalidArgumentException('URL de recurso no válida o protocolo no permitido: solo se permite http(s) o rutas relativas');
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $base = $slug;
        $counter = 1;
        while (Product::where('slug', $slug)->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }
        return $slug;
    }

    private function generateDefaultSku(string $category, int $productId, int $index): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $category ?: 'PLX'), 0, 3) ?: 'PLX');
        return "{$prefix}-{$productId}-" . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }

    private function toBool(mixed $val): bool
    {
        return $val === '1' || $val === 1 || $val === true || $val === 'true';
    }

    private function recordStockEvent(int $productId, int $stock, string $origen): void
    {
        try {
            $event = InventoryEvent::create([
                'producto_id' => $productId,
                'stock_nuevo' => $stock,
                'origen' => $origen,
            ]);

            Cache::put('inventory:max_id', (int) $event->id, 300);

            if (random_int(1, 50) === 1) {
                InventoryEvent::where('creado_at', '<', now()->subHours(24))->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar evento de stock', ['producto_id' => $productId, 'error' => $e->getMessage()]);
        }
    }
}

