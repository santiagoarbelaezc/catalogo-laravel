<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    private const MAX_EVENTS = 200;

    public function changes(Request $request): JsonResponse
    {
        try {
            $maxId = $this->getCachedMaxEventId();
            $sinceRaw = $request->query('since_id');

            if ($sinceRaw === null || $sinceRaw === '' || !ctype_digit((string) $sinceRaw)) {
                return $this->respond([
                    'enabled' => true,
                    'reset' => false,
                    'last_id' => $maxId,
                    'events' => [],
                    'has_more' => false,
                ]);
            }

            $sinceId = (int) $sinceRaw;

            if ($sinceId > $maxId) {
                return $this->respond([
                    'enabled' => true,
                    'reset' => true,
                    'last_id' => $maxId,
                    'events' => [],
                    'has_more' => false,
                ]);
            }

            if ($sinceId === $maxId) {
                return $this->respond([
                    'enabled' => true,
                    'reset' => false,
                    'last_id' => $maxId,
                    'events' => [],
                    'has_more' => false,
                ]);
            }

            $rows = InventoryEvent::where('id', '>', $sinceId)
                ->orderBy('id')
                ->limit(self::MAX_EVENTS + 1)
                ->get();

            $hasMore = $rows->count() > self::MAX_EVENTS;
            if ($hasMore) {
                $rows->pop();
            }

            $events = $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'producto_id' => (int) $r->producto_id,
                'stock_nuevo' => (int) $r->stock_nuevo,
                'origen' => $r->origen,
                'creado_at' => $r->creado_at,
            ])->values()->all();

            $lastId = !empty($events) ? $events[count($events) - 1]['id'] : $sinceId;

            return $this->respond([
                'enabled' => true,
                'reset' => false,
                'last_id' => $lastId,
                'events' => $events,
                'has_more' => $hasMore,
            ]);
        } catch (\Throwable $e) {
            Log::warning('InventoryController::changes fallback', ['error' => $e->getMessage()]);
            return $this->respond([
                'enabled' => false,
                'reset' => false,
                'last_id' => 0,
                'events' => [],
                'has_more' => false,
            ]);
        }
    }

    public function stream(Request $request): StreamedResponse
    {
        $lastEventId = (int) ($request->header('Last-Event-ID') ?? $request->query('last_id', 0));

        return response()->stream(function () use (&$lastEventId) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @ob_implicit_flush(true);

            try {
                if ($lastEventId === 0) {
                    $lastEventId = $this->getCachedMaxEventId();
                }
            } catch (\Throwable) {
                echo "event: error\ndata: " . json_encode(['message' => 'Tabla de eventos no disponible']) . "\n\n";
                @flush();
                return;
            }

            echo "id: {$lastEventId}\nevent: connected\ndata: " . json_encode([
                'status' => 'connected',
                'last_id' => $lastEventId,
            ]) . "\n\n";
            @flush();

            $startTime = time();
            $maxDuration = 20;

            while ((time() - $startTime) < $maxDuration) {
                if (connection_aborted()) {
                    break;
                }

                try {
                    $maxId = $this->getCachedMaxEventId();
                    $events = $maxId > $lastEventId
                        ? Cache::remember("inventory:events:since:{$lastEventId}", 3, function () use ($lastEventId) {
                            return InventoryEvent::where('id', '>', $lastEventId)
                                ->orderBy('id')
                                ->limit(50)
                                ->get()
                                ->map(fn ($ev) => [
                                    'id' => (int) $ev->id,
                                    'producto_id' => (int) $ev->producto_id,
                                    'stock_nuevo' => (int) $ev->stock_nuevo,
                                    'origen' => $ev->origen,
                                    'creado_at' => $ev->creado_at,
                                ])
                                ->all();
                        })
                        : [];

                    if (! empty($events)) {
                        foreach ($events as $ev) {
                            $lastEventId = $ev['id'];
                            echo "id: {$lastEventId}\n";
                            echo "event: stock_update\n";
                            echo 'data: ' . json_encode($ev) . "\n\n";
                        }
                        @flush();
                    } else {
                        echo ": heartbeat\n\n";
                        @flush();
                    }
                } catch (\Throwable) {
                    break;
                }

                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function getCachedMaxEventId(): int
    {
        return (int) Cache::remember('inventory:max_id', 5, fn () => (int) (InventoryEvent::max('id') ?? 0));
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}

