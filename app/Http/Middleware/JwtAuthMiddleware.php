<?php

namespace App\Http\Middleware;

use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    public function __construct(private JwtService $jwtService) {}

    public function handle(Request $request, Closure $next, ?string $requiredRole = null): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json([
                'success' => false,
                'message' => 'Token no proporcionado. Incluye Authorization: Bearer <token>',
            ], 401);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Formato de token inválido. Usa: Bearer <token>',
            ], 401);
        }

        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'El token está vacío',
            ], 401);
        }

        try {
            $payload = $this->jwtService->verifyToken($token);
        } catch (RuntimeException $e) {
            $code = $e->getCode() === 500 ? 500 : 401;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $code);
        }

        if ($requiredRole !== null && ($payload['role'] ?? '') !== $requiredRole) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado: se requieren permisos de administrador',
            ], 403);
        }

        $request->attributes->set('jwt_user', $payload);

        return $next($request);
    }
}

