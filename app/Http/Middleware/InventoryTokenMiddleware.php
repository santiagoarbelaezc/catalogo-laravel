<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InventoryTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = trim((string) config('catalogo.inventory.sync_token', ''));

        if ($expectedToken === '') {
            Log::error('INVENTORY_SYNC_TOKEN no está configurado');
            return response()->json([
                'success' => false,
                'message' => 'Integración de inventario no configurada',
            ], 500);
        }

        $authHeader = trim((string) $request->header('Authorization', ''));
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return response()->json([
                'success' => false,
                'message' => 'Token de integración no proporcionado',
            ], 401);
        }

        $providedToken = trim($matches[1]);
        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Token de integración inválido',
            ], 401);
        }

        return $next($request);
    }
}

