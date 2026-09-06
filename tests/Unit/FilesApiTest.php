<?php

namespace Tests\Unit;

use App\Services\v1\FilesApi;
use App\Services\v1\FilesApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FilesApiTest extends TestCase
{
    public function test_it_uploads_to_configured_hostinger_upload_script_without_api_key_header(): void
    {
        config()->set('services.files_api.url', 'https://pharmacy-web-based.kvelop.com/api/upload.php');
        config()->set('services.files_api.key', '');

        Http::fake([
            'https://pharmacy-web-based.kvelop.com/api/upload.php' => Http::response([
                'success' => true,
                'message' => 'File uploaded',
                'data' => [
                    'id' => 42,
                    'file_name' => 'stored.pdf',
                    'original_name' => 'prescription.pdf',
                    'format' => 'pdf',
                    'category' => 'prescription',
                ],
            ], 201),
        ]);

        $file = UploadedFile::fake()->create('prescription.pdf', 120, 'application/pdf');

        $result = (new FilesApi())->upload($file, 'prescription');

        $this->assertSame('42', $result['file_id']);
        $this->assertSame('stored.pdf', $result['file_name']);
        Http::assertSent(fn ($request) => $request->url() === 'https://pharmacy-web-based.kvelop.com/api/upload.php'
            && ! $request->hasHeader('X-API-Key')
            && str_contains((string) $request->body(), 'name="category"')
            && str_contains((string) $request->body(), 'prescription'));
    }

    public function test_it_rejects_non_https_api_urls(): void
    {
        config()->set('services.files_api.url', 'http://pharmacy-web-based.kvelop.com/api/upload.php');
        config()->set('services.files_api.key', '');

        $this->expectException(FilesApiException::class);
        $this->expectExceptionMessage('FILES_API_URL must use HTTPS.');

        (new FilesApi())->metadata('42');
    }

    public function test_it_downloads_from_private_view_script_to_temporary_file_without_api_key_header(): void
    {
        config()->set('services.files_api.url', 'https://pharmacy-web-based.kvelop.com/api/upload.php');
        config()->set('services.files_api.key', '');

        Http::fake([
            'https://pharmacy-web-based.kvelop.com/api/view.php?file_name=stored.pdf' => Http::response('%PDF-test', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $download = (new FilesApi())->downloadToTemporaryFile('stored.pdf');

        $this->assertFileExists($download['path']);
        $this->assertSame('%PDF-test', file_get_contents($download['path']));
        $this->assertSame('application/pdf', $download['content_type']);
        @unlink($download['path']);

        Http::assertSent(fn ($request) => $request->url() === 'https://pharmacy-web-based.kvelop.com/api/view.php?file_name=stored.pdf'
            && ! $request->hasHeader('X-API-Key'));
    }
}
