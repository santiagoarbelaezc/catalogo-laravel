<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    public function __construct(private CloudinaryService $cloudinary) {}

    public function run(Request $request): JsonResponse
    {
        $expectedToken = (string) config('catalogo.backup.secret_token', '');
        $providedToken = (string) $request->query('token', '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return response()->json(['success' => false, 'message' => 'Token de backup inválido'], 401);
        }

        $lockKey = 'catalogo_backup_lock';
        if (Cache::has($lockKey)) {
            return response()->json(['success' => false, 'message' => 'Backup ejecutado recientemente. Espera 1 hora.'], 429);
        }

        Cache::put($lockKey, true, now()->addHour());

        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup_' . date('Ymd_His') . '.sql.gz';

        try {
            $tables = DB::select('SHOW TABLES');
            $sql = "-- Backup Catalogo Plaxtilineas (" . date('c') . ")\nSET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $row) {
                $table = array_values((array) $row)[0];
                $create = DB::select("SHOW CREATE TABLE `{$table}`");
                $createSql = ((array) $create[0])['Create Table'] ?? '';
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n";

                $rows = DB::table($table)->get();
                foreach ($rows as $r) {
                    $vals = array_map(fn ($v) => $v === null ? 'NULL' : DB::getPdo()->quote((string) $v), (array) $r);
                    $cols = implode('`, `', array_keys((array) $r));
                    $sql .= "INSERT INTO `{$table}` (`{$cols}`) VALUES (" . implode(', ', $vals) . ");\n";
                }
                $sql .= "\n";
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            file_put_contents($tempFile, gzencode($sql, 9));

            $primary = $this->cloudinary->uploadRawFile($tempFile, 'backups/catalogo_db', false);
            $secondary = $this->cloudinary->uploadRawFile($tempFile, 'backups/catalogo_db', true);

            return response()->json([
                'success' => true,
                'message' => 'Backup completado',
                'primary_uploaded' => !empty($primary['secure_url']),
                'secondary_uploaded' => !empty($secondary['secure_url']),
            ]);
        } catch (\Throwable $e) {
            Log::error('BackupController error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al generar backup'], 500);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}

