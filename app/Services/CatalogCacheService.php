<?php

namespace App\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Servicio central de caché versionada para catálogo y categorías,
 * con fallback automático a snapshots JSON en disco ante límites de MySQL.
 */
class CatalogCacheService
{
    private const VERSION_KEY = 'catalog:version';
    private const TTL_HOURS = 24;
    private const SNAPSHOT_DIR = 'app/catalog_snapshot';

    /**
     * Obtiene datos desde caché versionada o MySQL, con fallback a snapshot JSON.
     *
     * @param  Closure(): array<string, mixed>|null  $queryFn  Debe retornar el payload completo (o null si no existe)
     */
    public function respond(
        Request $request,
        string $cacheSuffix,
        Closure $queryFn,
        ?string $notFoundMessage = null
    ): JsonResponse {
        $version = $this->getVersion();
        $etag = "\"catalog-v{$version}-" . substr(sha1($cacheSuffix), 0, 10) . '"';

        if ($this->clientHasMatchingEtag($request, $etag)) {
            return response()->json(null, 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=120');
        }

        $cacheKey = "catalog:v{$version}:{$cacheSuffix}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->cachedJsonResponse($cached, $etag);
        }

        try {
            $payload = $queryFn();

            if ($payload === null) {
                return response()->json([
                    'success' => false,
                    'message' => $notFoundMessage ?? 'Recurso no encontrado',
                ], 404)->header('Cache-Control', 'no-store');
            }

            Cache::put($cacheKey, $payload, now()->addHours(self::TTL_HOURS));
            $this->saveSnapshot($cacheSuffix, $payload);

            return $this->cachedJsonResponse($payload, $etag);
        } catch (QueryException|PDOException|\Throwable $e) {
            if (! $this->isDatabaseFailure($e)) {
                throw $e;
            }

            Log::error('CatalogCacheService: error de BD, intentando snapshot', [
                'suffix' => $cacheSuffix,
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            $snapshot = $this->loadSnapshot($cacheSuffix);
            if ($snapshot !== null) {
                $response = response()->json($snapshot['payload'], 200)
                    ->header('Cache-Control', 'no-store')
                    ->header('X-Catalog-Fallback', 'snapshot');

                if (! empty($snapshot['saved_at'])) {
                    $response->header('X-Catalog-Snapshot-At', $snapshot['saved_at']);
                }

                return $response;
            }

            return response()->json([
                'success' => false,
                'message' => 'Servicio temporalmente saturado, intenta en unos momentos',
            ], 503)->header('Cache-Control', 'no-store');
        }
    }

    /**
     * Invalida toda la caché del catálogo al instante incrementando la versión global.
     */
    public function invalidate(): int
    {
        Cache::add(self::VERSION_KEY, 1, now()->addDays(30));

        return (int) Cache::increment(self::VERSION_KEY);
    }

    /**
     * Devuelve la versión actual del catálogo en caché.
     */
    public function getVersion(): int
    {
        return (int) Cache::remember(self::VERSION_KEY, now()->addDays(30), fn () => 1);
    }

    private function cachedJsonResponse(array $payload, string $etag): JsonResponse
    {
        return response()->json($payload, 200)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=120');
    }

    private function clientHasMatchingEtag(Request $request, string $etag): bool
    {
        $ifNoneMatch = trim((string) $request->header('If-None-Match', ''));
        if ($ifNoneMatch === '') {
            return false;
        }

        // Soportar múltiples ETags o prefijo débil W/
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $clean = ltrim(trim($candidate), 'W/');
            if ($clean === $etag) {
                return true;
            }
        }

        return false;
    }

    private function isDatabaseFailure(\Throwable $e): bool
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            return true;
        }

        $code = (int) $e->getCode();
        $msg = strtolower($e->getMessage());

        return in_array($code, [1226, 1040, 2002, 2006], true)
            || str_contains($msg, 'max_queries_per_hour')
            || str_contains($msg, 'max_user_connections')
            || str_contains($msg, 'too many connections')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'server has gone away')
            || str_contains($msg, 'connection timed out');
    }

    private function saveSnapshot(string $cacheSuffix, array $payload): void
    {
        try {
            $path = $this->snapshotPath($cacheSuffix);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $envelope = json_encode([
                'saved_at' => now()->toIso8601String(),
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE);

            if ($envelope !== false) {
                file_put_contents($path, $envelope, LOCK_EX);
            }
        } catch (\Throwable $e) {
            Log::warning('CatalogCacheService: no se pudo guardar snapshot', [
                'suffix' => $cacheSuffix,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{saved_at: string|null, payload: array<string, mixed>}|null
     */
    private function loadSnapshot(string $cacheSuffix): ?array
    {
        $path = $this->snapshotPath($cacheSuffix);
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['payload']) || ! is_array($decoded['payload'])) {
            return null;
        }

        return [
            'saved_at' => $decoded['saved_at'] ?? null,
            'payload' => $decoded['payload'],
        ];
    }

    private function snapshotPath(string $cacheSuffix): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cacheSuffix);
        if (strlen($safe) > 80) {
            $safe = substr($safe, 0, 50) . '_' . substr(sha1($cacheSuffix), 0, 16);
        }

        return storage_path(self::SNAPSHOT_DIR . DIRECTORY_SEPARATOR . $safe . '.json');
    }
}
