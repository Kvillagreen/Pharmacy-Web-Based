<?php

namespace App\Services\v1;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FilesApi
{
    private const ALLOWED_CATEGORIES = ['document', 'prescription', 'dangerous_drug', 'valid_id'];

    public function upload(UploadedFile $file, string $category, array $metadata = []): array
    {
        $category = $this->normalizeCategory($category);

        if (config('services.files_api.use_local_storage', false)) {
            return $this->uploadToLocalStorage($file, $category);
        }

        $response = $this->pendingRequest()
            ->attach(
                'file',
                fopen($file->getRealPath(), 'rb'),
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
            )
            ->post($this->endpoint('upload.php'), [
                'category' => $category,
            ]);

        $this->throwIfUnexpected($response, [201], 'Unable to upload file.');

        return $this->normalizeFileResponse($response, 'upload');
    }

    public function list(array $filters = []): array
    {
        if (config('services.files_api.use_local_storage', false)) {
            return [];
        }

        $response = $this->pendingRequest()->get($this->endpoint('files.php'), array_filter([
            'id' => $filters['id'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        $this->throwIfUnexpected($response, [200], 'Unable to list files.');

        $data = $response->json('data') ?? [];
        if (isset($filters['category']) && is_array($data)) {
            $data = array_values(array_filter($data, fn ($row) => ($row['category'] ?? null) === $filters['category']));
        }

        return $data;
    }

    public function metadata(string $fileId): array
    {
        if (config('services.files_api.use_local_storage', false)) {
            return [];
        }

        $response = $this->pendingRequest()->get($this->endpoint('files.php'), ['id' => $fileId]);
        $this->throwIfUnexpected($response, [200], 'Unable to load file metadata.');

        return $response->json('data') ?? [];
    }

    public function replace(string $fileId, UploadedFile $file, string $category, array $metadata = []): array
    {
        $category = $this->normalizeCategory($category);

        if (config('services.files_api.use_local_storage', false)) {
            $disk = Storage::disk(config('transactions.documents_disk', 'public'));
            $disk->delete((string) $fileId);
            $storedPath = $disk->putFileAs('transactions/documents', $file, $this->buildLocalStorageFilename($file));

            if ($storedPath === false) {
                throw new FilesApiException('Unable to store file locally.');
            }

            return [
                'file_id' => $storedPath,
                'file_name' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'format' => strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
                'category' => $category,
                'raw' => ['success' => true, 'data' => [
                    'id' => $storedPath,
                    'file_name' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'format' => strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
                    'category' => $category,
                ]],
            ];
        }

        $response = $this->pendingRequest()
            ->attach(
                'file',
                fopen($file->getRealPath(), 'rb'),
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
            )
            ->post($this->endpoint('update.php'), [
                'id' => $fileId,
                'category' => $category,
            ]);

        $this->throwIfUnexpected($response, [200], 'Unable to replace file.');

        return $this->normalizeFileResponse($response, 'replace');
    }

    public function updateMetadata(string $fileId, array $metadata): array
    {
        if (config('services.files_api.use_local_storage', false)) {
            return [];
        }

        $category = isset($metadata['category']) ? $this->normalizeCategory((string) $metadata['category']) : null;
        if ($category === null) {
            return $this->metadata($fileId);
        }

        $response = $this->pendingRequest()->post($this->endpoint('update.php'), [
            'id' => $fileId,
            'category' => $category,
        ]);

        $this->throwIfUnexpected($response, [200], 'Unable to update file metadata.');

        return $this->normalizeFileResponse($response, 'metadata');
    }

    public function delete(string $fileId): void
    {
        if (config('services.files_api.use_local_storage', false)) {
            Storage::disk(config('transactions.documents_disk', 'public'))->delete((string) $fileId);
            return;
        }

        $response = $this->pendingRequest()->delete($this->endpoint('delete.php') . '?id=' . rawurlencode($fileId));

        $this->throwIfUnexpected($response, [200, 404], 'Unable to delete file.');
    }

    public function downloadToTemporaryFile(string $fileName): array
    {
        if (config('services.files_api.use_local_storage', false)) {
            $disk = Storage::disk(config('transactions.documents_disk', 'public'));
            if (!$disk->exists((string) $fileName)) {
                throw new FilesApiException('Unable to download file.');
            }

            $path = $disk->path((string) $fileName);
            return [
                'path' => $path,
                'content_type' => $disk->mimeType((string) $fileName) ?: 'application/octet-stream',
            ];
        }

        $response = $this->pendingRequest()
            ->accept('*/*')
            ->get($this->endpoint('view.php'), ['file_name' => $fileName]);

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
        return Http::acceptJson()
            ->timeout((int) config('services.files_api.timeout', 15))
            ->connectTimeout((int) config('services.files_api.connect_timeout', 5))
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
            ]);
    }

    private function endpoint(string $script): string
    {
        $baseUrl = rtrim((string) config('services.files_api.url'), '/');
        if ($baseUrl === '') {
            throw new FilesApiException('Files API is not configured. Set FILES_API_URL on the server.');
        }

        if (!str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new FilesApiException('FILES_API_URL must use HTTPS.');
        }

        if (str_ends_with(strtolower($baseUrl), '/upload')) {
            return substr($baseUrl, 0, -strlen('upload')) . pathinfo($script, PATHINFO_FILENAME);
        }

        if (str_ends_with(strtolower($baseUrl), '/upload.php')) {
            return preg_replace('#/upload\.php$#i', '/' . $script, $baseUrl);
        }

        return $baseUrl . '/' . ltrim($script, '/');
    }

    private function uploadToLocalStorage(UploadedFile $file, string $category): array
    {
        $disk = Storage::disk(config('transactions.documents_disk', 'public'));
        $storedPath = $disk->putFileAs('transactions/documents', $file, $this->buildLocalStorageFilename($file));

        if ($storedPath === false) {
            throw new FilesApiException('Unable to store file locally.');
        }

        return [
            'file_id' => $storedPath,
            'file_name' => $storedPath,
            'original_name' => $file->getClientOriginalName(),
            'format' => strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
            'category' => $category,
            'raw' => ['success' => true, 'data' => [
                'id' => $storedPath,
                'file_name' => $storedPath,
                'original_name' => $file->getClientOriginalName(),
                'format' => strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
                'category' => $category,
            ]],
        ];
    }

    private function buildLocalStorageFilename(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'bin');
        $baseName = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'document');
        return sprintf('%s-%s.%s', $baseName ?: 'document', bin2hex(random_bytes(8)), $extension);
    }

    private function normalizeCategory(string $category): string
    {
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new FilesApiException('Invalid file category.');
        }

        return $category;
    }

    private function normalizeFileResponse(Response $response, string $operation): array
    {
        $json = $response->json();
        if (!is_array($json) || ($json['success'] ?? false) !== true || !is_array($json['data'] ?? null)) {
            throw new FilesApiException('Files API ' . $operation . ' response was invalid.', $response->status(), $json);
        }

        $data = $json['data'];
        $id = $data['id'] ?? null;
        $fileName = $data['file_name'] ?? null;

        if ($id === null || $fileName === null) {
            throw new FilesApiException('Files API ' . $operation . ' response was missing file metadata.', $response->status(), $json);
        }

        return [
            'file_id' => (string) $id,
            'file_name' => (string) $fileName,
            'original_name' => $data['original_name'] ?? null,
            'format' => $data['format'] ?? $data['file_format'] ?? null,
            'category' => $data['category'] ?? null,
            'raw' => $json,
        ];
    }

    private function throwIfUnexpected(Response $response, array $expectedStatuses, string $fallback): void
    {
        if (in_array($response->status(), $expectedStatuses, true)) {
            return;
        }

        $body = $response->json();
        $message = is_array($body)
            ? (string) ($body['message'] ?? $body['error'] ?? $fallback)
            : $fallback;

        throw new FilesApiException($message, $response->status(), $body);
    }
}
