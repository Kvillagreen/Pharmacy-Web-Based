<?php

namespace App\Services\v1;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FortmedSmsService
{
    public function fetchReplies(int $limit = 20): array
    {
        $response = $this->request()
            ->get($this->endpoint('replies.php'), [
                'limit' => max(1, min($limit, 100)),
            ]);

        $decoded = $this->decodeResponse($response);

        return [
            'status' => $response->status(),
            'raw' => $decoded,
            'messages' => $this->normalizeReplies($decoded),
        ];
    }

    public function sendMessage(array $payload): array
    {
        $response = $this->request()
            ->post($this->endpoint('messages.php'), $payload);

        return [
            'status' => $response->status(),
            'raw' => $this->decodeResponse($response),
        ];
    }

    public function defaults(): array
    {
        return [
            'sender_name' => (string) config('services.fortmed_sms.sender_name', ''),
            'from_number' => (string) config('services.fortmed_sms.from_number', ''),
        ];
    }

    private function request()
    {
        return Http::acceptJson()
            ->contentType('application/json')
            ->withHeaders([
                'X-API-Key' => (string) config('services.fortmed_sms.api_key'),
            ])
            ->timeout(20)
            ->connectTimeout(10);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.fortmed_sms.base_url'), '/') . '/' . ltrim($path, '/');
    }

    private function decodeResponse(Response $response): mixed
    {
        $json = $response->json();

        if ($json !== null) {
            return $json;
        }

        return $response->body();
    }

    private function normalizeReplies(mixed $payload): array
    {
        $records = [];

        if (is_array($payload)) {
            if ($this->isList($payload)) {
                $records = $payload;
            } else {
                foreach (['data', 'replies', 'messages', 'items', 'results'] as $key) {
                    if (isset($payload[$key]) && is_array($payload[$key])) {
                        $records = $payload[$key];
                        break;
                    }
                }
            }
        }

        return collect($records)
            ->filter(fn ($record) => is_array($record))
            ->map(function (array $record) {
                $fromNumber = $record['FromNumber'] ?? $record['from_number'] ?? $record['from'] ?? $record['mobile'] ?? '';
                $toNumber = $record['ToNumber'] ?? $record['to_number'] ?? $record['to'] ?? '';
                $messageBody = $record['MessageBody'] ?? $record['message_body'] ?? $record['message'] ?? $record['body'] ?? '';
                $receivedAt = $record['ReceivedAt'] ?? $record['received_at'] ?? $record['created_at'] ?? $record['date'] ?? null;

                return [
                    'id' => $record['ReplyID'] ?? $record['reply_id'] ?? $record['id'] ?? md5(json_encode($record)),
                    'from_number' => (string) $fromNumber,
                    'to_number' => (string) $toNumber,
                    'message_body' => (string) $messageBody,
                    'sender_name' => (string) ($record['SenderName'] ?? $record['sender_name'] ?? ''),
                    'received_at' => $receivedAt,
                    'raw' => $record,
                ];
            })
            ->values()
            ->all();
    }

    private function isList(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
