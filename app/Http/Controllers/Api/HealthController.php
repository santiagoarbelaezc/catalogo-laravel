<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Endpoint de salud protegido por Bearer token para monitoreo desde Atalaya.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.atalaya.token', '');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $dbOk = $this->checkDatabase();
        $cacheOk = $this->checkCache();
        [$queueOk, $failedJobs] = $this->checkQueue($dbOk);
        $schedulerOk = $this->checkScheduler($cacheOk);

        $status = ! $dbOk
            ? 'down'
            : ((! $cacheOk || ! $queueOk || ! $schedulerOk) ? 'degraded' : 'ok');

        return response()->json([
            'status' => $status,
            'db' => $dbOk,
            'cache' => $cacheOk,
            'queue' => $queueOk,
            'failed_jobs' => $failedJobs,
            'scheduler' => $schedulerOk,
            'disk_free_pct' => $this->getDiskFreePct(),
            'memory_used_mb' => round(memory_get_usage(true) / 1048576, 2),
            'load_avg_1m' => $this->getLoadAvg1m(),
        ], $dbOk ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            $probeKey = 'atalaya:health:probe';
            Cache::put($probeKey, 'ok', 10);

            return Cache::get($probeKey) === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: bool, 1: int}
     */
    private function checkQueue(bool $dbOk): array
    {
        if (! $dbOk) {
            return [false, 0];
        }

        try {
            if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
                DB::table('jobs')->limit(1)->count();
            }

            $failedJobs = Schema::hasTable('failed_jobs')
                ? (int) DB::table('failed_jobs')->count()
                : 0;

            return [true, $failedJobs];
        } catch (\Throwable) {
            return [false, 0];
        }
    }

    private function checkScheduler(bool $cacheOk): bool
    {
        if (! $cacheOk) {
            return false;
        }

        $heartbeat = Cache::get('scheduler:heartbeat');

        return is_numeric($heartbeat) && (now()->timestamp - (int) $heartbeat) < 180;
    }

    private function getDiskFreePct(): float
    {
        $total = @disk_total_space(base_path()) ?: 0;
        $free = @disk_free_space(base_path()) ?: 0;

        return $total > 0 ? round(($free / $total) * 100, 2) : 0.0;
    }

    private function getLoadAvg1m(): ?float
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return (is_array($load) && isset($load[0])) ? round((float) $load[0], 2) : null;
    }
}
