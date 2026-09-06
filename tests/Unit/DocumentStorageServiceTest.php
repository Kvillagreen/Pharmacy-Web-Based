<?php

namespace Tests\Unit;

use App\Services\v1\DocumentStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentStorageServiceTest extends TestCase
{
    public function test_it_saves_files_locally_when_not_in_production(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config()->set('transactions.documents_disk', 'public');
        Storage::fake('public');

        $file = UploadedFile::fake()->create('prescription.pdf', 120, 'application/pdf');

        $path = (new DocumentStorageService())->store($file, 'transactions/documents', 'prescription');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('transactions/documents/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_uploads_to_hostinger_files_api_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config()->set('transactions.documents_disk', 'hostinger_files');
        config()->set('transactions.hostinger_files_api_url', 'https://example.com/files');
        config()->set('transactions.hostinger_files_api_key', 'secret-key');

        Http::fake([
            'https://example.com/files' => Http::response([
                'uuid' => 'abc123',
                'url' => 'https://example.com/files/abc123',
            ], 200),
        ]);

        $file = UploadedFile::fake()->create('prescription.pdf', 120, 'application/pdf');

        $path = (new DocumentStorageService())->store($file, 'transactions/documents', 'prescription');

        $this->assertSame('https://example.com/files/abc123', $path);
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'secret-key')
            && $request->url() === 'https://example.com/files'
            && str_contains((string) $request->body(), 'name="category"')
            && str_contains((string) $request->body(), 'prescription'));
    }

    public function test_it_maps_valid_id_and_dangerous_drug_categories_for_production_uploads(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config()->set('transactions.hostinger_files_api_url', 'https://example.com/files');
        config()->set('transactions.hostinger_files_api_key', 'secret-key');

        Http::fake([
            'https://example.com/files' => Http::response([
                'uuid' => 'valid-id-uuid',
                'url' => 'https://example.com/files/valid-id-uuid',
            ], 200),
        ]);

        $validId = UploadedFile::fake()->create('valid-id.jpg', 120, 'image/jpeg');
        $dangerousDrug = UploadedFile::fake()->create('dangerous-drug.pdf', 120, 'application/pdf');

        (new DocumentStorageService())->store($validId, 'transactions/documents', 'valid_id');
        (new DocumentStorageService())->store($dangerousDrug, 'transactions/documents', 'dangerous_drug');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'name="category"')
            && str_contains((string) $request->body(), 'valid_id'));
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'name="category"')
            && str_contains((string) $request->body(), 'dangerous_drug'));
    }
}
