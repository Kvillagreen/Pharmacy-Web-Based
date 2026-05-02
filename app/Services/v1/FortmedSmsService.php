<?php

namespace App\Services\v1;

use App\Models\v1\SmsMessage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class FortmedSmsService
{
    public function fetchReplies(int $limit = 20): array
    {
        $response = $this->performRequest(
            'GET',
            $this->endpoint('replies.php'),
            [],
            [
                'limit' => max(1, min($limit, 100)),
                '_ts' => now()->timestamp,
            ]
        );

        return [
            'status' => $response['status'],
            'raw' => $response['decoded'],
            'messages' => $this->normalizeReplies($response['decoded']),
        ];
    }

    public function sendMessage(array $payload): array
    {
        $response = $this->performRequest(
            'POST',
            $this->endpoint('messages.php'),
            $payload
        );

        return [
            'status' => $response['status'],
            'raw' => $response['decoded'],
        ];
    }

    public function defaults(): array
    {
        return [
            'sender_name' => (string) config('services.fortmed_sms.sender_name', ''),
            'from_number' => (string) config('services.fortmed_sms.from_number', ''),
        ];
    }

    public function normalizePhoneNumber(?string $number): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if ($digits === null || $digits === '') {
            return '';
        }

        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '63' . substr($digits, 1);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '63' . $digits;
        }

        if (str_starts_with($digits, '639') && strlen($digits) === 12) {
            return $digits;
        }

        return $digits;
    }

    public function formatDisplayPhoneNumber(?string $number): string
    {
        $normalized = $this->normalizePhoneNumber($number);

        if (str_starts_with($normalized, '639') && strlen($normalized) === 12) {
            return '0' . substr($normalized, 2);
        }

        return (string) $number;
    }

    public function syncInboundMessages(array $messages, ?int $userId = null, ?int $branchId = null): void
    {
        if (!$this->smsMessageTableExists()) {
            return;
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $providerMessageId = (string) ($message['id'] ?? '');
            $providerOriginalMessageId = (string) ($message['raw']['original_message_id'] ?? $message['original_message_id'] ?? '');
            $fromNumber = (string) ($message['from_number'] ?? '');
            $toNumber = (string) ($message['to_number'] ?? '');
            $normalizedFrom = $this->normalizePhoneNumber($fromNumber);
            $normalizedTo = $this->normalizePhoneNumber($toNumber);
            $counterpartyNumber = $normalizedFrom !== '' ? $normalizedFrom : $normalizedTo;
            $existingReferenceNumber = $this->supportsExtendedSmsLogColumns() && $providerMessageId !== ''
                ? SmsMessage::query()
                    ->where('direction', 'inbound')
                    ->where('provider_message_id', $providerMessageId)
                    ->value('reference_number')
                : null;

            $attributes = [
                'provider_original_message_id' => $providerOriginalMessageId !== '' ? $providerOriginalMessageId : null,
                'user_id' => $userId,
                'branch_id' => $branchId,
                'sender_name' => (string) ($message['sender_name'] ?? ''),
                'from_number' => $fromNumber !== '' ? $fromNumber : null,
                'to_number' => $toNumber !== '' ? $toNumber : null,
                'normalized_from_number' => $normalizedFrom !== '' ? $normalizedFrom : null,
                'normalized_to_number' => $normalizedTo !== '' ? $normalizedTo : null,
                'counterparty_number' => $counterpartyNumber !== '' ? $counterpartyNumber : null,
                'message_body' => (string) ($message['message_body'] ?? ''),
                'provider_received_at' => $this->parseProviderTimestamp($message['received_at'] ?? null),
                'provider_payload' => $message['raw'] ?? $message,
            ];

            if ($this->supportsExtendedSmsLogColumns()) {
                $attributes['reference_number'] = $existingReferenceNumber ?: $this->generateReferenceNumber('IN');
                $attributes['template_tag'] = 'Incoming Reply';
            }

            SmsMessage::updateOrCreate(
                [
                    'direction' => 'inbound',
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                ],
                $attributes
            );
        }
    }

    public function storeOutboundMessage(
        array $payload,
        mixed $providerResponse = null,
        ?int $userId = null,
        ?int $branchId = null,
        array $metadata = []
    ): SmsMessage
    {
        if (!$this->smsMessageTableExists()) {
            throw new \RuntimeException('SMS message log table is not available yet.');
        }

        $fromNumber = (string) ($payload['FromNumber'] ?? '');
        $toNumber = (string) ($payload['ToNumber'] ?? '');
        $attributes = [
            'direction' => 'outbound',
            'provider_message_id' => $this->extractProviderMessageId($providerResponse),
            'provider_original_message_id' => null,
            'user_id' => $userId,
            'branch_id' => $branchId,
            'sender_name' => (string) ($payload['SenderName'] ?? ''),
            'from_number' => $fromNumber !== '' ? $fromNumber : null,
            'to_number' => $toNumber !== '' ? $toNumber : null,
            'normalized_from_number' => $this->normalizePhoneNumber($fromNumber) ?: null,
            'normalized_to_number' => $this->normalizePhoneNumber($toNumber) ?: null,
            'counterparty_number' => $this->normalizePhoneNumber($toNumber) ?: null,
            'message_body' => (string) ($payload['MessageBody'] ?? ''),
            'provider_received_at' => now(),
            'provider_payload' => is_array($providerResponse) ? $providerResponse : ['response' => $providerResponse],
        ];

        if ($this->supportsExtendedSmsLogColumns()) {
            $attributes['reference_number'] = (string) ($metadata['reference_number'] ?? $this->generateReferenceNumber('OUT'));
            $attributes['template_tag'] = $this->normalizeTemplateTag($metadata['template_tag'] ?? null);
        }

        return SmsMessage::create($attributes);
    }

    public function getStoredConversations(int $limit = 20): array
    {
        if (!$this->smsMessageTableExists()) {
            return [
                'conversations' => [],
                'total_messages' => 0,
                'unique_customers' => 0,
            ];
        }

        $messages = SmsMessage::query()
            ->orderByDesc('provider_received_at')
            ->orderByDesc('sms_message_id')
            ->get();

        $grouped = $messages
            ->groupBy(fn (SmsMessage $message) => $message->counterparty_number ?: 'unknown')
            ->map(fn (Collection $conversation, string $key) => $this->transformConversation($conversation, $key))
            ->sortByDesc(fn (array $conversation) => $conversation['last_received_at'] ?? '')
            ->values()
            ->take(max(1, min($limit, 100)))
            ->all();

        return [
            'conversations' => $grouped,
            'total_messages' => $messages->count(),
            'unique_customers' => collect($grouped)->count(),
        ];
    }

    public function getStoredLogs(int $limit = 100): array
    {
        if (!$this->smsMessageTableExists()) {
            return [];
        }

        $messages = SmsMessage::query()
            ->orderByDesc('provider_received_at')
            ->orderByDesc('sms_message_id')
            ->take(max(1, min($limit, 250)))
            ->get();

        return $messages->map(fn (SmsMessage $message) => [
            'id' => $message->sms_message_id,
            'reference_number' => $message->reference_number,
            'template_tag' => $message->template_tag,
            'direction' => $message->direction,
            'sender_name' => $message->sender_name,
            'from_number' => $this->formatDisplayPhoneNumber($message->from_number),
            'to_number' => $this->formatDisplayPhoneNumber($message->to_number),
            'normalized_from_number' => $message->normalized_from_number,
            'normalized_to_number' => $message->normalized_to_number,
            'counterparty_number' => $this->formatDisplayPhoneNumber($message->counterparty_number),
            'message_body' => $message->message_body,
            'received_at' => optional($message->provider_received_at)->toDateTimeString(),
        ])->values()->all();
    }

    private function request()
    {
        return Http::acceptJson()
            ->contentType('application/json')
            ->withHeaders([
                'X-API-Key' => (string) config('services.fortmed_sms.api_key'),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])
            ->withOptions([
                'proxy' => [
                    'http' => '',
                    'https' => '',
                    'no' => ['*'],
                ],
                'verify' => false,
            ])
            ->retry(2, 300)
            ->timeout(20)
            ->connectTimeout(10);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.fortmed_sms.base_url'), '/') . '/' . ltrim($path, '/');
    }

    private function performRequest(string $method, string $url, array $payload = [], array $query = []): array
    {
        try {
            $response = strtoupper($method) === 'POST'
                ? $this->request()->post($url, $payload)
                : $this->request()->get($url, $query);

            return [
                'status' => $response->status(),
                'decoded' => $this->decodeBody($response->body()),
            ];
        } catch (\Throwable $exception) {
            \Log::warning('FortMed HTTP client request failed. Falling back to cURL.', [
                'method' => $method,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return $this->curlRequest($method, $url, $payload, $query);
        }
    }

    private function curlRequest(string $method, string $url, array $payload = [], array $query = []): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for FortMed SMS requests.');
        }

        $queryString = http_build_query(array_filter($query, fn ($value) => $value !== null && $value !== ''));
        $requestUrl = $queryString !== '' ? $url . '?' . $queryString : $url;
        $ch = curl_init($requestUrl);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Pragma: no-cache',
            'Expires: 0',
            'X-API-Key: ' . (string) config('services.fortmed_sms.api_key'),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Unknown cURL error while contacting FortMed SMS.');
        }

        return [
            'status' => $status > 0 ? $status : 500,
            'decoded' => $this->decodeBody($body),
        ];
    }

    private function decodeBody(?string $body): mixed
    {
        $body = trim((string) $body);

        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $body;
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
                $normalizedFrom = $this->normalizePhoneNumber((string) $fromNumber);
                $normalizedTo = $this->normalizePhoneNumber((string) $toNumber);

                return [
                    'id' => $record['ReplyID'] ?? $record['reply_id'] ?? $record['id'] ?? md5(json_encode($record)),
                    'from_number' => (string) $fromNumber,
                    'to_number' => (string) $toNumber,
                    'display_from_number' => $this->formatDisplayPhoneNumber((string) $fromNumber),
                    'display_to_number' => $this->formatDisplayPhoneNumber((string) $toNumber),
                    'normalized_from_number' => $normalizedFrom,
                    'normalized_to_number' => $normalizedTo,
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

    private function parseProviderTimestamp(mixed $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractProviderMessageId(mixed $providerResponse): ?string
    {
        if (!is_array($providerResponse)) {
            return null;
        }

        foreach (['id', 'message_id', 'MessageID', 'sms_id'] as $key) {
            if (!empty($providerResponse[$key])) {
                return (string) $providerResponse[$key];
            }
        }

        if (isset($providerResponse['data']) && is_array($providerResponse['data'])) {
            foreach (['id', 'message_id', 'MessageID', 'sms_id'] as $key) {
                if (!empty($providerResponse['data'][$key])) {
                    return (string) $providerResponse['data'][$key];
                }
            }
        }

        return null;
    }

    private function transformConversation(Collection $conversation, string $key): array
    {
        $sorted = $conversation
            ->sortBy([
                ['provider_received_at', 'desc'],
                ['sms_message_id', 'desc'],
            ])
            ->values();

        /** @var SmsMessage|null $latest */
        $latest = $sorted->first();
        $history = $sorted
            ->sortBy([
                ['provider_received_at', 'asc'],
                ['sms_message_id', 'asc'],
            ])
            ->map(fn (SmsMessage $message) => [
                'id' => $message->sms_message_id,
                'reference_number' => $message->reference_number,
                'template_tag' => $message->template_tag,
                'direction' => $message->direction,
                'from_number' => $this->formatDisplayPhoneNumber($message->from_number),
                'to_number' => $this->formatDisplayPhoneNumber($message->to_number),
                'normalized_from_number' => $message->normalized_from_number,
                'normalized_to_number' => $message->normalized_to_number,
                'message_body' => $message->message_body,
                'sender_name' => $message->sender_name,
                'received_at' => optional($message->provider_received_at)->toDateTimeString(),
            ])
            ->values()
            ->all();

        return [
            'id' => 'conversation-' . ($key ?: ($latest?->sms_message_id ?? 'unknown')),
            'from_number' => $this->formatDisplayPhoneNumber($key),
            'to_number' => $latest ? $this->formatDisplayPhoneNumber($latest->to_number) : '',
            'reply_to_number' => $this->formatDisplayPhoneNumber($key),
            'normalized_customer_number' => $key,
            'reference_number' => $latest?->reference_number,
            'template_tag' => $latest?->template_tag,
            'message_body' => $latest?->message_body ?? '',
            'sender_name' => $latest?->sender_name ?? '',
            'received_at' => optional($latest?->provider_received_at)->toDateTimeString(),
            'last_received_at' => optional($latest?->provider_received_at)->toDateTimeString(),
            'message_count' => $sorted->count(),
            'history' => $history,
            'raw' => $latest?->provider_payload ?? [],
        ];
    }

    private function normalizeTemplateTag(mixed $value): ?string
    {
        $templateTag = trim((string) ($value ?? ''));

        return $templateTag !== '' ? $templateTag : 'Custom Reply';
    }

    private function generateReferenceNumber(string $prefix = 'SMS'): string
    {
        return strtoupper($prefix) . '-' . now()->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function smsMessageTableExists(): bool
    {
        try {
            return Schema::hasTable('sms_messages');
        } catch (\Throwable) {
            return false;
        }
    }

    private function supportsExtendedSmsLogColumns(): bool
    {
        try {
            return Schema::hasColumns('sms_messages', ['reference_number', 'template_tag']);
        } catch (\Throwable) {
            return false;
        }
    }
}
