<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Subcategory;
use App\Services\CatalogCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(private CatalogCacheService $catalogCache) {}

    public function index(Request $request): JsonResponse
    {
        $brand = $request->query('brand') ?? $request->query('linea');
        $suffix = empty($brand)
            ? 'categories_all'
            : 'categories_brand_' . sha1(mb_strtolower((string) $brand));

        return $this->catalogCache->respond($request, $suffix, function () use ($brand) {
            $query = Category::with('subcategories')->orderBy('name');

            if (!empty($brand)) {
                $escaped = addcslashes((string) $brand, '%_\\');
                $query->where(function ($q) use ($brand, $escaped) {
                    $q->where('brand', $brand)
                      ->orWhere('brand', 'LIKE', "%{$escaped}%");
                });
            }

            return [
                'success' => true,
                'data' => $query->get()->toArray(),
            ];
        });
    }

    public function store(Request $request): JsonResponse
    {
        $name = $request->input('name');
        if (empty($name)) {
            return response()->json([
                'success' => false,
                'message' => 'El nombre de la categoría es requerido',
            ], 400);
        }

        $brand = $request->input('brand') ?: ($request->input('linea') ?: 'Plaxtilineas');

        $category = Category::create([
            'name' => $name,
            'brand' => $brand,
        ]);

        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Categoría creada',
            'data' => [
                'id' => $category->id,
                'brand' => $category->brand,
            ],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $name = $request->input('name');
        if (empty($name)) {
            return response()->json([
                'success' => false,
                'message' => 'El nombre es requerido',
            ], 400);
        }

        $category = Category::find($id);
        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada',
            ], 404);
        }

        $category->name = $name;
        if (!empty($request->input('brand'))) {
            $category->brand = $request->input('brand');
        }
        $category->save();
        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Categoría actualizada',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        Category::where('id', $id)->delete();
        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Categoría eliminada',
        ]);
    }

    public function subcategories(Request $request, int $id): JsonResponse
    {
        return $this->catalogCache->respond($request, "subcategories_cat_{$id}", function () use ($id) {
            $subcategories = Subcategory::where('category_id', $id)
                ->orderBy('name')
                ->get()
                ->toArray();

            return [
                'success' => true,
                'data' => $subcategories,
            ];
        });
    }

    public function storeSubcategory(Request $request): JsonResponse
    {
        $name = $request->input('name');
        $categoryId = $request->input('category_id');

        if (empty($name) || empty($categoryId)) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre y categoría son requeridos',
            ], 400);
        }

        $subcategory = Subcategory::create([
            'category_id' => $categoryId,
            'name' => $name,
        ]);

        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Subcategoría creada',
            'data' => ['id' => $subcategory->id],
        ], 201);
    }

    public function updateSubcategory(Request $request, int $id): JsonResponse
    {
        $name = $request->input('name');
        if (empty($name)) {
            return response()->json([
                'success' => false,
                'message' => 'El nombre es requerido',
            ], 400);
        }

        Subcategory::where('id', $id)->update(['name' => $name]);
        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Subcategoría actualizada',
        ]);
    }

    public function destroySubcategory(int $id): JsonResponse
    {
        Subcategory::where('id', $id)->delete();
        $this->catalogCache->invalidate();

        return response()->json([
            'success' => true,
            'message' => 'Subcategoría eliminada',
        ]);
    }
}

