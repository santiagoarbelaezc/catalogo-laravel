<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiController extends Controller
{
    private function callGroqApi(string $apiKey, array $messages, float $temperature = 1): string
    {
        $response = Http::timeout(30)
            ->withOptions(['verify' => app()->environment('production')])
            ->withToken($apiKey)
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => 'openai/gpt-oss-120b',
                'messages' => $messages,
                'temperature' => $temperature,
                'max_completion_tokens' => 2048,
                'top_p' => 1,
                'reasoning_effort' => 'medium',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Error de la API de Groq: ' . $response->body());
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    public function generateDescription(Request $request): JsonResponse
    {
        $productName = trim((string) $request->input('name', ''));
        if ($productName === '') {
            return response()->json(['success' => false, 'message' => 'El nombre del producto es requerido.'], 400);
        }

        $apiKey = (string) env('GROQ_API_KEY_DESC', '');
        if ($apiKey === '') {
            return response()->json(['success' => false, 'message' => 'La API Key de descripción no está configurada.'], 500);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'Eres un experto en marketing y redacción publicitaria para e-commerce. Escribe descripciones de productos que sean atractivas, profesionales, persuasivas y optimizadas para SEO. La descripción debe ser de un solo párrafo, no más de 30-40 palabras. NUNCA envuelvas tu respuesta entre comillas ni uses markdown.',
            ],
            [
                'role' => 'user',
                'content' => "Escribe una descripción comercial para el producto: {$productName}",
            ],
        ];

        try {
            $content = trim($this->callGroqApi($apiKey, $messages, 1), " \t\n\r\0\x0B\"'");
            return response()->json(['success' => true, 'description' => $content]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function suggestSubcategory(Request $request): JsonResponse
    {
        $productName = trim((string) $request->input('name', ''));
        if ($productName === '') {
            return response()->json(['success' => false, 'message' => 'El nombre del producto es requerido.'], 400);
        }

        $apiKey = (string) env('GROQ_API_KEY_SUGGEST', '');
        if ($apiKey === '') {
            return response()->json(['success' => false, 'message' => 'La API Key de sugerencia no está configurada.'], 500);
        }

        $rawCategories = $request->input('categories');
        if ($rawCategories === null) {
            return response()->json(['success' => false, 'message' => 'Las categorías son requeridas para analizar.'], 400);
        }

        $categoriesData = is_string($rawCategories) ? json_decode($rawCategories, true) : $rawCategories;
        $categoriesText = '';
        foreach ($categoriesData ?? [] as $cat) {
            $catName = $cat['name'] ?? '';
            foreach ($cat['subcategories'] ?? [] as $sub) {
                $categoriesText .= "ID: {$sub['id']}, Nombre: {$sub['name']} (Categoría: {$catName})\n";
            }
        }

        if ($categoriesText === '') {
            return response()->json(['success' => true, 'subcategory_id' => null]);
        }

        $productDesc = trim((string) $request->input('description', ''));
        $messages = [
            [
                'role' => 'system',
                'content' => "Eres un sistema clasificador de productos experto. Solo debes responder con el ID numérico de la subcategoría correcta basándote en la lista proporcionada. No des explicaciones, ni añadas puntos, ni palabras extra. Si no hay ninguna coincidencia lógica, responde exactamente con la palabra 'null'.",
            ],
            [
                'role' => 'user',
                'content' => "Producto: {$productName}\nDescripción: {$productDesc}\n\nSubcategorías disponibles:\n{$categoriesText}\n\n¿Cuál es el ID correcto?",
            ],
        ];

        try {
            $content = $this->callGroqApi($apiKey, $messages, 1);
            $subcategoryId = null;
            $categoryId = null;

            if (strtolower(trim($content)) !== 'null' && preg_match('/\d+/', $content, $matches)) {
                $subcategoryId = (int) $matches[0];
                foreach ($categoriesData as $cat) {
                    foreach ($cat['subcategories'] ?? [] as $sub) {
                        if ($sub['id'] == $subcategoryId) {
                            $categoryId = $cat['id'];
                            break 2;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'subcategory_id' => $subcategoryId,
                'category_id' => $categoryId,
            ]);
        } catch (\Throwable $e) {
            Log::error('AI Controller Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function generateSku(Request $request): JsonResponse
    {
        $productName = trim((string) $request->input('name', ''));
        if ($productName === '') {
            return response()->json(['success' => false, 'message' => 'El nombre del producto es requerido.'], 400);
        }

        $category = trim((string) $request->input('category', ''));
        $variantName = trim((string) $request->input('variant_name', ''));
        $rawVariants = $request->input('variants');
        $variants = $rawVariants ? (is_string($rawVariants) ? json_decode($rawVariants, true) : $rawVariants) : [];

        $apiKey = (string) (env('GROQ_API_KEY_SUGGEST') ?: env('GROQ_API_KEY_DESC'));
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $category ?: 'PLX'), 0, 3) ?: 'PLX');

        if ($apiKey === '') {
            $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $productName), 0, 5) ?: 'PROD');
            if (!empty($variants) && is_array($variants)) {
                $skus = [];
                foreach ($variants as $i => $v) {
                    $skus[] = "{$prefix}-{$base}-" . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                }
                return response()->json(['success' => true, 'skus' => $skus]);
            }
            return response()->json(['success' => true, 'sku' => "{$prefix}-{$base}-01"]);
        }

        try {
            if (!empty($variants) && is_array($variants)) {
                $variantList = collect($variants)->map(fn ($v, $i) => ($i + 1) . '. ' . ($v['name'] ?? ''))->implode("\n");
                $messages = [
                    [
                        'role' => 'system',
                        'content' => 'Eres un experto en logística e inventarios. Genera códigos SKU cortos, únicos y estandarizados en mayúsculas (ej: PLX-MALLA-120). Responde únicamente con un JSON array de strings en el mismo orden.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Producto: {$productName}\nCategoría: {$category}\nVariantes:\n{$variantList}",
                    ],
                ];
                $content = $this->callGroqApi($apiKey, $messages, 0.3);
                $decoded = json_decode(trim($content), true);
                return response()->json(['success' => true, 'skus' => is_array($decoded) ? $decoded : []]);
            }

            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Eres un experto en logística. Responde únicamente con un código SKU corto en mayúsculas sin espacios ni comillas (ej: PLX-MALLA-120).',
                ],
                [
                    'role' => 'user',
                    'content' => "Producto: {$productName}\nCategoría: {$category}\nVariante: {$variantName}",
                ],
            ];
            $sku = strtoupper(trim($this->callGroqApi($apiKey, $messages, 0.3), " \t\n\r\0\x0B\"'"));
            return response()->json(['success' => true, 'sku' => $sku]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

