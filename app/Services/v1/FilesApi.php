<?php

namespace App\Services\v1;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FilesApi
{
    private const ALLOWED_CATEGORIES = ['document', 'prescription', 'dangerous_drug', 'valid_id'];

    public function upload(UploadedFile $file, string $category, array $metadata = []): array
    {
        $category = $this->normalizeCategory($category);
        $payload = $this->metadataPayload($category, $metadata);

        $extension = strtolower($file->getClientOriginalExtension());
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'file';
        $uniqueFilename = sprintf('%s-%s%s', Str::slug($base), Str::uuid()->toString(), $extension !== '' ? '.' . $extension : '');

        $baseUrl = rtrim((string) config('services.files_api.url'), '/');

        // Use the pharmacy upload endpoint only in production for the pharmacy domain
        if (app()->environment('production') && str_contains(strtolower($baseUrl), 'pharmacy-web-based.kvelop.com')) {
            $uploadPath = '/api/upload';
        } else {
            $uploadPath = '/files';
        }

        $response = $this->pendingRequest()
            ->attach(
                'file',
                fopen($file->getRealPath(), 'rb'),
                $uniqueFilename,
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
            )
            ->post($this->url($uploadPath), [
                ...$payload,
                'category' => $category,
                'file_name' => $uniqueFilename,
            ]);

        $this->throwIfUnexpected($response, [201], 'Unable to upload file.');

        $json = $response->json();
        if (!is_array($json)) {
            throw new FilesApiException('Files API upload response was invalid.', $response->status(), $json);
        }

        // Normalize responses from different upload endpoints (file_name, id, uuid)
        if (empty($json['uuid'])) {
            if (!empty($json['file_name'])) {
                $json['uuid'] = (string) $json['file_name'];
            } elseif (!empty($json['id'])) {
                $json['uuid'] = (string) $json['id'];
            } else {
                // Fall back to the unique filename we sent
                $json['uuid'] = $uniqueFilename;
            }
        }

        return $json;
    }

    public function list(array $filters = []): array
    {
        $response = $this->pendingRequest()->get($this->url('/files'), array_filter([
            'category' => $filters['category'] ?? null,
            'record_reference' => $filters['record_reference'] ?? null,
            'limit' => isset($filters['limit']) ? max(1, min((int) $filters['limit'], 100)) : null,
            'offset' => isset($filters['offset']) ? max(0, (int) $filters['offset']) : null,
        ], fn ($value) => $value !== null && $value !== ''));

        $this->throwIfUnexpected($response, [200], 'Unable to list files.');

        return $response->json() ?? [];
    }

    public function metadata(string $uuid): array
    {
        $response = $this->pendingRequest()->get($this->url('/files/' . rawurlencode($uuid) . '/metadata'));
        $this->throwIfUnexpected($response, [200], 'Unable to load file metadata.');

        return $response->json() ?? [];
    }

    public function replace(string $uuid, UploadedFile $file, string $category, array $metadata = []): array
    {
        $category = $this->normalizeCategory($category);
        $payload = $this->metadataPayload($category, $metadata);

        $response = $this->pendingRequest()
            ->attach(
                'file',
                fopen($file->getRealPath(), 'rb'),
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
            )
            ->post($this->url('/files/' . rawurlencode($uuid)), [
                ...$payload,
                'category' => $category,
            ]);

        $this->throwIfUnexpected($response, [200], 'Unable to replace file.');

        return $response->json() ?? [];
    }

    public function updateMetadata(string $uuid, array $metadata): array
    {
        $metadata = array_filter($metadata, fn ($value) => $value !== null && $value !== '');
        if (empty($metadata)) {
            throw new FilesApiException('Metadata changes cannot be empty.');
        }

        $response = $this->pendingRequest()->patch($this->url('/files/' . rawurlencode($uuid)), $metadata);
        $this->throwIfUnexpected($response, [200], 'Unable to update file metadata.');

        return $response->json() ?? [];
    }

    public function delete(string $uuid): void
    {
        $response = $this->pendingRequest()->delete($this->url('/files/' . rawurlencode($uuid)));
        $this->throwIfUnexpected($response, [200, 404], 'Unable to delete file.');
    }

    public function downloadToTemporaryFile(string $uuid): array
    {
        // Some deployments (pharmacy-web-based.kvelop.com) expose files via /view?file_name=<name>
        $baseUrl = rtrim((string) config('services.files_api.url'), '/');

        if (app()->environment('production') && str_contains(strtolower($baseUrl), 'pharmacy-web-based.kvelop.com')) {
            $endpoint = '/view?file_name=' . rawurlencode($uuid);
        } else {
            $endpoint = '/files/' . rawurlencode($uuid);
        }

        $response = $this->pendingRequest()->get($this->url($endpoint));
        $this->throwIfUnexpected($response, [200], 'Unable to download file.');

        $path = tempnam(sys_get_temp_dir(), 'files-api-');
        if ($path === false) {
            throw new FilesApiException('Unable to create temporary file for download.');
        }

        file_put_contents($path, $response->body());

        return [
            'path' => $path,
            'content_type' => $response->header('Content-Type') ?: 'application/octet-stream',
        ];
    }

    private function pendingRequest()
    {
        $apiKey = trim((string) config('services.files_api.key'));
        if ($apiKey === '') {
            throw new FilesApiException('Files API is not configured. Set FILES_API_KEY on the server.');
        }

        return Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->timeout((int) config('services.files_api.timeout', 15))
            ->connectTimeout((int) config('services.files_api.connect_timeout', 5))
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
            ]);
    }

    private function url(string $path): string
    {
        $baseUrl = rtrim((string) config('services.files_api.url'), '/');
        if ($baseUrl === '') {
            throw new FilesApiException('Files API is not configured. Set FILES_API_URL on the server.');
        }

        if (!str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new FilesApiException('FILES_API_URL must use HTTPS.');
        }

        return $baseUrl . $path;
    }

    private function normalizeCategory(string $category): string
    {
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new FilesApiException('Invalid file category.');
        }

        return $category;
    }

    private function metadataPayload(string $category, array $metadata): array
    {
        if (in_array($category, ['document', 'valid_id'], true)) {
            return [];
        }

        $required = ['record_reference', 'patient_reference', 'prescriber_name', 'prescription_reference'];
        if ($category === 'dangerous_drug') {
            $required = [...$required, 'drug_name', 'quantity', 'unit'];
        }

        foreach ($required as $field) {
            if (!isset($metadata[$field]) || trim((string) $metadata[$field]) === '') {
                throw new FilesApiException("Missing required Files API metadata: {$field}.");
            }
        }

        return array_filter([
            'record_reference' => (string) $metadata['record_reference'],
            'patient_reference' => (string) $metadata['patient_reference'],
            'prescriber_name' => (string) $metadata['prescriber_name'],
            'prescription_reference' => (string) $metadata['prescription_reference'],
            'authorization_reference' => $metadata['authorization_reference'] ?? null,
            'drug_name' => $metadata['drug_name'] ?? null,
            'quantity' => $metadata['quantity'] ?? null,
            'unit' => $metadata['unit'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function throwIfUnexpected(Response $response, array $expectedStatuses, string $fallback): void
    {
        if (in_array($response->status(), $expectedStatuses, true)) {
            return;
        }

        // Log unexpected response for debugging deployment issues (502s etc.)
        try {
            $body = $response->json();
        } catch (\Throwable $e) {
            $body = $response->body();
        }

        Log::error('Files API unexpected response', [
            'status' => $response->status(),
            'body' => is_string($body) ? $body : json_encode($body),
        ]);

        $message = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? $fallback)
            : $fallback;

        throw new FilesApiException($message, $response->status(), $body);
    }
}
