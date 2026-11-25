<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;

    public function __construct(private JwtService $jwtService) {}

    public function login(Request $request): JsonResponse
    {
        $rateKey = 'login:' . sha1($request->ip() ?? '0.0.0.0');

        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_LOGIN_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateKey);
            $waitMinutes = (int) ceil($seconds / 60);

            return response()->json([
                'success' => false,
                'message' => "Demasiados intentos fallidos. Intenta nuevamente en {$waitMinutes} minuto(s).",
            ], 429);
        }

        $email = $request->input('email');
        $password = $request->input('password');

        if (empty($email) || empty($password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email y contraseña son requeridos',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato de email inválido',
            ], 400);
        }

        $user = User::where('email', $email)->where('is_active', 1)->first();

        if ($user && password_verify($password, $user->password)) {
            RateLimiter::clear($rateKey);

            $token = $this->jwtService->generateToken($user);

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'token' => $token,
                    'expiresIn' => '24h',
                ],
            ]);
        }

        RateLimiter::hit($rateKey, self::LOCKOUT_SECONDS);

        return response()->json([
            'success' => false,
            'message' => 'Credenciales inválidas',
        ], 401);
    }

    public function profile(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('jwt_user');
        if (empty($jwtUser['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de usuario requeridos',
            ], 400);
        }

        $user = User::select('id', 'username', 'email', 'role')
            ->where('id', $jwtUser['id'])
            ->where('is_active', 1)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil obtenido',
            'data' => $user,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $email = $request->input('email');
        $password = $request->input('password');

        if (empty($username) || empty($email) || empty($password)) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre de usuario, email y contraseña son requeridos',
            ], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato de email inválido',
            ], 400);
        }

        if (strlen($password) < 8) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña debe tener mínimo 8 caracteres',
            ], 400);
        }

        $exists = User::where('email', $email)->orWhere('username', $username)->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario ya existe',
            ], 409);
        }

        $requestedRole = (string) $request->input('role', 'user');
        $role = in_array($requestedRole, ['admin', 'user'], true) ? $requestedRole : 'user';

        $user = User::create([
            'username' => $username,
            'email' => $email,
            'password' => $password, // hashed automatically via casts
            'role' => $role,
            'is_active' => 1,
        ]);

        $userData = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
        ];

        $token = $this->jwtService->generateToken($userData);

        return response()->json([
            'success' => true,
            'message' => 'Usuario registrado exitosamente',
            'data' => [
                'user' => $userData,
                'token' => $token,
                'expiresIn' => '24h',
            ],
        ], 201);
    }
}

