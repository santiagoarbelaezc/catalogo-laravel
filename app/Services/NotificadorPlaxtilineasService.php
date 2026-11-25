<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificadorPlaxtilineasService
{
    public function notificarProductoNuevo(int $productId): array
    {
        try {
            $enabled = filter_var(env('PLAXTILINEAS_NOTIFY_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
            if (!$enabled) {
                return ['sent' => false, 'reason' => 'disabled'];
            }

            $baseUrl = rtrim((string) env('PLAXTILINEAS_BACKEND_URL', ''), '/');
            $secret = trim((string) env('PLAXTILINEAS_WEBHOOK_SECRET', ''));

            if ($baseUrl === '' || $secret === '') {
                Log::warning('NotificadorPlaxtilineas: falta configurar URL o SECRET', ['producto_id' => $productId]);
                return ['sent' => false, 'reason' => 'missing_config'];
            }

            $product = Product::with(['images', 'variants', 'categoryRelation'])->find($productId);
            if (!$product) {
                return ['sent' => false, 'reason' => 'not_found'];
            }

            if (strcasecmp(trim((string) $product->category), 'Plaxtilineas') !== 0) {
                return ['sent' => false, 'reason' => 'not_plaxtilineas'];
            }

            $imagenUrl = null;
            foreach ($product->images as $img) {
                $candidate = trim((string) $img->url);
                if (str_starts_with($candidate, 'https://') && strlen($candidate) <= 2048) {
                    $imagenUrl = $candidate;
                    break;
                }
            }

            if ($imagenUrl === null) {
                return ['sent' => false, 'reason' => 'no_https_image'];
            }

            $precio = null;
            foreach ($product->variants as $variant) {
                if (is_numeric($variant->price) && (float) $variant->price > 0) {
                    $raw = (float) $variant->price;
                    $precio = ($raw == (int) $raw) ? (int) $raw : $raw;
                    break;
                }
            }

            $payload = [
                'producto_id' => $product->id,
                'nombre' => $product->name,
                'descripcion' => $product->description,
                'categoria' => $product->categoryRelation?->name ?? $product->category,
                'imagen_url' => $imagenUrl,
                'precio' => $precio,
            ];

            $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

            $response = Http::timeout(8)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Catalogo-Timestamp' => $timestamp,
                    'X-Catalogo-Signature' => $signature,
                ])
                ->withBody($rawBody, 'application/json')
                ->post("{$baseUrl}/api/notificaciones/producto-nuevo");

            return ['sent' => $response->successful(), 'status' => $response->status()];
        } catch (\Throwable $e) {
            Log::warning('NotificadorPlaxtilineas error', ['producto_id' => $productId, 'error' => $e->getMessage()]);
            return ['sent' => false, 'reason' => 'exception'];
        }
    }
}

