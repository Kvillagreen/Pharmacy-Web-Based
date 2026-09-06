<?php

namespace App\Services\v1;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentStorageService
{
    /**
     * Store a document in the local Laravel storage or the Hostinger Files API.
     */
    public function store(UploadedFile $file, string $directory, string $category = 'document'): string|null
    {
        if (app()->environment('production')) {
            return $this->storeInHostingerFiles($file, $category);
        }

        $relativePath = $file->storeAs(
            $directory,
            $this->buildLocalFilename($file),
            ['disk' => config('transactions.documents_disk', 'public')]
        );

        return $relativePath ?: null;
    }

    protected function storeInHostingerFiles(UploadedFile $file, string $category): string
    {
        $apiUrl = rtrim((string) config('transactions.hostinger_files_api_url', env('TRANSACTION_HOSTINGER_FILES_API_URL')), '/');
        $apiKey = (string) config('transactions.hostinger_files_api_key', env('TRANSACTION_HOSTINGER_FILES_API_KEY'));

        if ($apiUrl === '' || $apiKey === '') {
            throw new \RuntimeException('Hostinger Files API is not configured. Set TRANSACTION_HOSTINGER_FILES_API_URL and TRANSACTION_HOSTINGER_FILES_API_KEY in the environment.');
        }

        $allowedCategories = ['document', 'prescription', 'dangerous_drug', 'valid_id'];
        $normalizedCategory = in_array($category, $allowedCategories, true) ? $category : 'document';

        $fileContents = file_get_contents($file->getRealPath());

        if ($fileContents === false || $fileContents === '') {
            $fileContents = $file->getContent();
        }

        $response = Http::withHeaders([
            'X-API-Key' => $apiKey,
        ])->asMultipart()->post($apiUrl, [
            ['name' => 'category', 'contents' => $normalizedCategory],
            [
                'name' => 'file',
                'contents' => $fileContents,
                'filename' => $file->getClientOriginalName(),
                'headers' => ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Unable to upload document to Hostinger Files API.');
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new \RuntimeException('Invalid Hostinger Files API response.');
        }

        return $payload['url']
            ?? $payload['download_url']
            ?? $payload['path']
            ?? $apiUrl . '/' . ($payload['uuid'] ?? Str::uuid()->toString());
    }

    protected function buildLocalFilename(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'document');

        return sprintf('%s-%s.%s', $baseName, Str::uuid()->toString(), $extension !== '' ? $extension : 'bin');
    }
}
