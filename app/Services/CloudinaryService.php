<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudinaryService
{
    private string $cloudName;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->cloudName = (string) env('CLOUDINARY_CLOUD_NAME', '');
        $this->apiKey = (string) env('CLOUDINARY_API_KEY', '');
        $this->apiSecret = (string) env('CLOUDINARY_API_SECRET', '');
    }

    public function uploadImage(UploadedFile $file, string $folder = 'catalogo_plaxtilineas'): array
    {
        if ($this->cloudName === '' || $this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Credenciales de Cloudinary no configuradas en .env');
        }

        $timestamp = time();
        $params = [
            'folder' => $folder,
            'timestamp' => $timestamp,
        ];
        ksort($params);

        $paramString = str_replace(['+', '%7E'], [' ', '~'], http_build_query($params));
        $signature = sha1($paramString . $this->apiSecret);

        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";

        $response = Http::withOptions([
            'verify' => app()->environment('production'),
        ])->attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post($url, [
            'folder' => $folder,
            'timestamp' => $timestamp,
            'api_key' => $this->apiKey,
            'signature' => $signature,
        ]);

        $data = $response->json();

        if (!$response->successful() || empty($data['secure_url'])) {
            throw new RuntimeException(
                'Error al subir a Cloudinary: ' . ($data['error']['message'] ?? $response->body())
            );
        }

        return [
            'success' => true,
            'url' => $data['secure_url'],
            'public_id' => $data['public_id'] ?? null,
            'original_name' => $file->getClientOriginalName(),
        ];
    }

    public function uploadRawFile(string $filePath, string $folder = 'backups/catalogo_db', bool $useSecondary = false): array
    {
        $cloudName = $useSecondary ? (string) env('CLOUDINARY_BACKUP2_CLOUD_NAME', '') : $this->cloudName;
        $apiKey = $useSecondary ? (string) env('CLOUDINARY_BACKUP2_API_KEY', '') : $this->apiKey;
        $apiSecret = $useSecondary ? (string) env('CLOUDINARY_BACKUP2_API_SECRET', '') : $this->apiSecret;

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            return ['success' => false, 'error' => 'Credenciales Cloudinary no configuradas'];
        }

        $timestamp = time();
        $params = [
            'folder' => $folder,
            'timestamp' => $timestamp,
        ];
        ksort($params);
        $paramString = str_replace(['+', '%7E'], [' ', '~'], http_build_query($params));
        $signature = sha1($paramString . $apiSecret);

        $url = "https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload";

        $response = Http::withOptions([
            'verify' => app()->environment('production'),
        ])->attach(
            'file',
            file_get_contents($filePath),
            basename($filePath)
        )->post($url, [
            'folder' => $folder,
            'timestamp' => $timestamp,
            'api_key' => $apiKey,
            'signature' => $signature,
        ]);

        return $response->json() ?? ['success' => false, 'error' => 'Respuesta vacía'];
    }
}

