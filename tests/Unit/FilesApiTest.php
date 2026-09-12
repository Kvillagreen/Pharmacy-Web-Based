<?php

namespace Tests\Unit;

use App\Services\v1\FilesApi;
use App\Services\v1\FilesApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FilesApiTest extends TestCase
{
    public function test_it_stores_uploads_locally_when_explicitly_enabled(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config()->set('services.files_api.use_local_storage', true);
        config()->set('services.files_api.url', 'https://pharmacy-web-based.kvelop.com/api/upload');
        config()->set('services.files_api.key', '');
        config()->set('transactions.documents_disk', 'public');
        \Illuminate\Support\Facades\Storage::fake('public');

        $file = UploadedFile::fake()->create('prescription.pdf', 120, 'application/pdf');

        $result = (new FilesApi())->upload($file, 'prescription');

        $this->assertStringStartsWith('transactions/documents/', $result['file_id']);
        $this->assertStringStartsWith('transactions/documents/', $result['file_name']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($result['file_name']);
        Http::assertSentCount(0);
    }

    public function test_it_uploads_to_hostinger_in_every_environment_by_default(): void
    {
        Http::preventStrayRequests();
        config()->set('services.files_api.url', 'https://pharmacy-web-based.kvelop.com/api/upload');
        $this->assertFalse(config('services.files_api.use_local_storage'));

        foreach (['local', 'testing', 'production'] as $environment) {
            app()->detectEnvironment(fn () => $environment);
            Http::fake([
                'https://pharmacy-web-based.kvelop.com/api/upload' => Http::response([
                    'success' => true,
                    'data' => ['id' => '42', 'file_name' => 'stored.pdf'],
                ], 201),
            ]);

            $result = (new FilesApi())->upload(
                UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf'),
                'prescription'
            );

            $this->assertSame('42', $result['file_id']);
            $this->assertSame('stored.pdf', $result['file_name']);
            Http::assertSent(fn ($request) => $request->method() === 'POST'
                && $request->url() === 'https://pharmacy-web-based.kvelop.com/api/upload'
                && $request->hasFile('file')
                && str_contains($request->body(), 'name="category"')
                && str_contains($request->body(), 'prescription'));
        }
    }

    public function test_it_rejects_non_https_api_urls_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config()->set('services.files_api.url', 'http://pharmacy-web-based.kvelop.com/api/upload');
        config()->set('services.files_api.key', '');

        $this->expectException(FilesApiException::class);
        $this->expectExceptionMessage('FILES_API_URL must use HTTPS.');

        (new FilesApi())->metadata('42');
    }

    public function test_it_downloads_from_private_view_script_to_temporary_file_locally(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config()->set('services.files_api.url', 'https://pharmacy-web-based.kvelop.com/api/upload');
        config()->set('services.files_api.key', '');

        Http::fake([
            'https://pharmacy-web-based.kvelop.com/api/view?file_name=stored.pdf' => Http::response('%PDF-test', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $download = (new FilesApi())->downloadToTemporaryFile('stored.pdf');

        $this->assertFileExists($download['path']);
        $this->assertSame('%PDF-test', file_get_contents($download['path']));
        $this->assertSame('application/pdf', $download['content_type']);
        @unlink($download['path']);

        Http::assertSent(fn ($request) => $request->url() === 'https://pharmacy-web-based.kvelop.com/api/view?file_name=stored.pdf');
    }
}
