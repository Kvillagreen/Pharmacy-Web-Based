<?php

namespace App\Services\v1;

use App\Models\v1\SmsMessage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class FortmedSmsService
{
    private const CONVERSATION_DELETE_MARKER_TAG = '__deleted_conversation__';

    public function fetchReplies(int $limit = 20): array
    {
        $response = $this->performRequest(
            'GET',
            $this->endpoint('history'),
            [],
            [
                'limit' => max(1, min($limit, 100)),
                'offset' => 0,
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
        $providerPayload = [
            'to' => $payload['ToNumber'] ?? '',
            'message' => $payload['MessageBody'] ?? '',
            'slot' => (int) config('services.mysmsgate_sms.slot', 0),
        ];

        $response = $this->performRequest(
            'POST',
            $this->endpoint('send'),
            $providerPayload
        );

        return [
            'status' => $response['status'],
            'raw' => $response['decoded'],
        ];
    }

    public function defaults(): array
    {
        return [
            'sender_name' => (string) config('services.mysmsgate_sms.sender_name', ''),
            'from_number' => (string) config('services.mysmsgate_sms.from_number', ''),
        ];
    }

    public function diagnostics(): array
    {
        $token = $this->apiToken();
        $probe = $this->fetchReplies(1);
        $raw = is_array($probe['raw']) ? $probe['raw'] : ['response' => $probe['raw']];

        return [
            'config' => [
                'base_url' => (string) config('services.mysmsgate_sms.base_url', ''),
                'api_key_configured' => $token !== '' && $token !== 'YOUR_MYSMSGATE_API_KEY',
                'api_key_length' => strlen($token),
                'sender_name' => (string) config('services.mysmsgate_sms.sender_name', ''),
                'from_number' => (string) config('services.mysmsgate_sms.from_number', ''),
                'slot' => (int) config('services.mysmsgate_sms.slot', 0),
            ],
            'provider' => [
                'status' => $probe['status'],
                'success' => $probe['status'] < 400,
                'message' => $raw['message'] ?? null,
                'total' => $raw['total'] ?? null,
                'history_count' => isset($raw['history']) && is_array($raw['history']) ? count($raw['history']) : null,
                'response_keys' => array_keys($raw),
            ],
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

        $supportsDeletedFlag = $this->supportsDeletedSmsLogColumn();

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $providerMessageId = (string) ($message['id'] ?? '');
            $providerOriginalMessageId = (string) ($message['raw']['original_message_id'] ?? $message['original_message_id'] ?? '');
            $direction = (string) ($message['direction'] ?? 'inbound');
            $direction = $direction === 'outbound' ? 'outbound' : 'inbound';
            $fromNumber = (string) ($message['from_number'] ?? '');
            $toNumber = (string) ($message['to_number'] ?? '');
            $normalizedFrom = $this->normalizePhoneNumber($fromNumber);
            $normalizedTo = $this->normalizePhoneNumber($toNumber);
            $counterpartyNumber = $direction === 'outbound'
                ? ($normalizedTo !== '' ? $normalizedTo : $normalizedFrom)
                : ($normalizedFrom !== '' ? $normalizedFrom : $normalizedTo);
            $receivedAt = $this->parseProviderTimestamp($message['received_at'] ?? null);

            if ($this->shouldHideConversationMessage($counterpartyNumber, $receivedAt)) {
                continue;
            }

            $decodedMessageBody = $this->decodeStoredMessageText((string) ($message['message_body'] ?? ''));
            $providerPayload = $this->normalizeProviderPayloadBody($message['raw'] ?? $message);
            $existingMessage = $providerMessageId !== ''
                ? SmsMessage::query()
                    ->where('direction', $direction)
                    ->where('provider_message_id', $providerMessageId)
                    ->first()
                : null;

            if ($supportsDeletedFlag && $existingMessage?->is_deleted) {
                continue;
            }

            $existingReferenceNumber = $this->supportsExtendedSmsLogColumns() && $providerMessageId !== ''
                ? ($existingMessage?->reference_number
                    ?? SmsMessage::query()
                        ->where('direction', $direction)
                        ->where('provider_message_id', $providerMessageId)
                        ->value('reference_number'))
                : null;

            $attributes = [
                'provider_original_message_id' => $providerOriginalMessageId !== '' ? $providerOriginalMessageId : null,
                'user_id' => $userId,
                'branch_id' => $branchId,
                'sender_name' => (string) ($message['sender_name'] ?? config('services.mysmsgate_sms.sender_name', '')),
                'from_number' => $fromNumber !== '' ? $fromNumber : null,
                'to_number' => $toNumber !== '' ? $toNumber : null,
                'normalized_from_number' => $normalizedFrom !== '' ? $normalizedFrom : null,
                'normalized_to_number' => $normalizedTo !== '' ? $normalizedTo : null,
                'counterparty_number' => $counterpartyNumber !== '' ? $counterpartyNumber : null,
                'message_body' => $decodedMessageBody,
                'provider_received_at' => $receivedAt,
                'provider_payload' => $providerPayload,
            ];

            if ($supportsDeletedFlag) {
                $attributes['is_deleted'] = false;
            }

            if ($this->supportsExtendedSmsLogColumns()) {
                $attributes['reference_number'] = $existingReferenceNumber ?: $this->generateReferenceNumber($direction === 'outbound' ? 'OUT' : 'IN');
                $attributes['template_tag'] = $direction === 'outbound' ? 'Sent Reply' : 'Incoming Reply';
            }

            SmsMessage::updateOrCreate(
                [
                    'direction' => $direction,
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

        if ($this->supportsDeletedSmsLogColumn()) {
            $attributes['is_deleted'] = false;
        }

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

        $this->repairUnreadableStoredMessages();

        $query = SmsMessage::query()
            ->orderByDesc('provider_received_at')
            ->orderByDesc('sms_message_id');

        if ($this->supportsDeletedSmsLogColumn()) {
            $query->where('is_deleted', false);
        }

        $messages = $query->get();

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

        $this->repairUnreadableStoredMessages();

        $query = SmsMessage::query()
            ->orderByDesc('provider_received_at')
            ->orderByDesc('sms_message_id')
            ->take(max(1, min($limit, 250)));

        if ($this->supportsDeletedSmsLogColumn()) {
            $query->where('is_deleted', false);
        }

        $messages = $query->get();

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

    public function deleteStoredMessage(int $messageId): bool
    {
        if (!$this->smsMessageTableExists()) {
            return false;
        }

        $query = SmsMessage::query()->where('sms_message_id', $messageId);

        if (!$this->supportsDeletedSmsLogColumn()) {
                return $query->delete() > 0;
        }

        return $query->update(['is_deleted' => true]) > 0;
    }

    public function deleteConversation(?string $counterpartyNumber, mixed $cutoffAt = null): int
    {
        if (!$this->smsMessageTableExists()) {
            return 0;
        }

        $normalized = $this->normalizePhoneNumber($counterpartyNumber);
        if ($normalized === '') {
            return 0;
        }

        $cutoff = $this->resolveConversationDeleteCutoff($normalized, $cutoffAt);
        if (!$cutoff) {
            return 0;
        }

        $query = SmsMessage::query()
            ->where('counterparty_number', $normalized)
            ->where('direction', '!=', 'system')
            ->where(function ($builder) use ($cutoff) {
                $builder->whereNotNull('provider_received_at')
                    ->where('provider_received_at', '<=', $cutoff);
            });

        if (!$this->supportsDeletedSmsLogColumn()) {
            return $query->delete();
        }

        $deletedCount = $query->update(['is_deleted' => true]);
        $this->ensureDeletedConversationMarker($normalized, $cutoff);

        return $deletedCount;
    }

    public function filterDeletedConversationMessages(array $messages): array
    {
        return collect($messages)
            ->filter(function (array $message) {
                $direction = (string) ($message['direction'] ?? 'inbound');
                $counterparty = $this->normalizePhoneNumber(
                    $direction === 'outbound'
                        ? (string) ($message['normalized_to_number']
                            ?? $message['to_number']
                            ?? $message['normalized_from_number']
                            ?? $message['from_number']
                            ?? '')
                        : (string) ($message['normalized_from_number']
                            ?? $message['from_number']
                            ?? $message['normalized_to_number']
                            ?? $message['to_number']
                            ?? '')
                );
                $receivedAt = $message['received_at'] ?? null;

                return !$this->shouldHideConversationMessage($counterparty, $receivedAt)
                    && !$this->isDeletedStoredInboundMessage($message);
            })
            ->values()
            ->all();
    }

    private function request()
    {
        return Http::acceptJson()
            ->contentType('application/json')
            ->withHeaders([
                'Authorization' => $this->authorizationHeader(),
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
        return rtrim((string) config('services.mysmsgate_sms.base_url'), '/') . '/' . ltrim($path, '/');
    }

    private function performRequest(string $method, string $url, array $payload = [], array $query = []): array
    {
        if ($this->apiToken() === '') {
            return [
                'status' => 503,
                'decoded' => [
                    'success' => false,
                    'message' => 'SMS_API_KEY is not configured.',
                ],
            ];
        }

        try {
            $response = strtoupper($method) === 'POST'
                ? $this->request()->post($url, $payload)
                : $this->request()->get($url, $query);

            return [
                'status' => $response->status(),
                'decoded' => $this->decodeBody($response->body()),
            ];
        } catch (\Throwable $exception) {
            \Log::warning('SMS gateway HTTP client request failed. Falling back to cURL.', [
                'method' => $method,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            try {
                return $this->curlRequest($method, $url, $payload, $query);
            } catch (\Throwable $curlException) {
                \Log::error('SMS gateway cURL fallback request failed.', [
                    'method' => $method,
                    'url' => $url,
                    'error' => $curlException->getMessage(),
                ]);

                return [
                    'status' => 503,
                    'decoded' => [
                        'success' => false,
                        'message' => 'SMS gateway request failed.',
                        'error' => [
                            'type' => class_basename($curlException),
                            'message' => $curlException->getMessage(),
                        ],
                    ],
                ];
            }
        }
    }

    private function curlRequest(string $method, string $url, array $payload = [], array $query = []): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for SMS gateway requests.');
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
            'Authorization: ' . $this->authorizationHeader(),
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
            throw new \RuntimeException($error !== '' ? $error : 'Unknown cURL error while contacting SMS gateway.');
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

    private function authorizationHeader(): string
    {
        return 'Bearer ' . $this->apiToken();
    }

    private function apiToken(): string
    {
        $token = trim((string) config('services.mysmsgate_sms.api_token', ''));

        return trim(preg_replace('/^Bearer\s+/i', '', $token) ?? $token);
    }

    private function normalizeReplies(mixed $payload): array
    {
        $records = [];

        if (is_array($payload)) {
            if ($this->isList($payload)) {
                $records = $payload;
            } else {
                foreach (['history', 'data', 'replies', 'messages', 'items', 'results'] as $key) {
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
                $fromNumber = $record['FromNumber'] ?? $record['from_number'] ?? $record['phone_from'] ?? $record['from'] ?? $record['mobile'] ?? '';
                $toNumber = $record['ToNumber'] ?? $record['to_number'] ?? $record['phone_to'] ?? $record['to'] ?? '';
                $messageBody = $record['MessageBody'] ?? $record['message_body'] ?? $record['message'] ?? $record['body'] ?? '';
                $receivedAt = $record['ReceivedAt'] ?? $record['received_at'] ?? $record['created_at'] ?? $record['sent_at'] ?? $record['date'] ?? null;
                $defaultFromNumber = (string) config('services.mysmsgate_sms.from_number', '');
                $normalizedDefaultFrom = $this->normalizePhoneNumber((string) config('services.mysmsgate_sms.from_number', ''));
                $decodedMessageBody = $this->decodeStoredMessageText((string) $messageBody);
                $providerDirection = strtolower((string) ($record['direction'] ?? ''));
                $status = strtolower((string) ($record['status'] ?? ''));
                $normalizedInitialFrom = $this->normalizePhoneNumber((string) $fromNumber);
                $direction = match ($providerDirection) {
                    'out', 'outbound' => 'outbound',
                    'in', 'inbound', 'incoming', 'received' => 'inbound',
                    default => ($normalizedDefaultFrom !== '' && $normalizedInitialFrom === $normalizedDefaultFrom)
                        || in_array($status, ['queued', 'pending', 'delivered', 'failed'], true)
                            ? 'outbound'
                            : 'inbound',
                };

                if ($direction === 'inbound' && trim((string) $toNumber) === '') {
                    $toNumber = $defaultFromNumber;
                }

                if ($direction === 'outbound' && trim((string) $fromNumber) === '') {
                    $fromNumber = $defaultFromNumber;
                }

                $normalizedFrom = $this->normalizePhoneNumber((string) $fromNumber);
                $normalizedTo = $this->normalizePhoneNumber((string) $toNumber);

                return [
                    'id' => $record['ReplyID'] ?? $record['reply_id'] ?? $record['sms_id'] ?? $record['id'] ?? md5(json_encode($record)),
                    'direction' => $direction,
                    'from_number' => (string) $fromNumber,
                    'to_number' => (string) $toNumber,
                    'display_from_number' => $this->formatDisplayPhoneNumber((string) $fromNumber),
                    'display_to_number' => $this->formatDisplayPhoneNumber((string) $toNumber),
                    'normalized_from_number' => $normalizedFrom,
                    'normalized_to_number' => $normalizedTo,
                    'message_body' => $decodedMessageBody,
                    'sender_name' => (string) ($record['SenderName'] ?? $record['sender_name'] ?? config('services.mysmsgate_sms.sender_name', '')),
                    'received_at' => $receivedAt,
                    'raw' => $this->normalizeProviderPayloadBody($record),
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

    private function supportsDeletedSmsLogColumn(): bool
    {
        try {
            return Schema::hasColumn('sms_messages', 'is_deleted');
        } catch (\Throwable) {
            return false;
        }
    }

    private function getDeletedConversationCutoff(?string $counterpartyNumber): ?Carbon
    {
        if (!$this->supportsDeletedSmsLogColumn()) {
            return null;
        }

        $normalized = $this->normalizePhoneNumber($counterpartyNumber);
        if ($normalized === '') {
            return null;
        }

        $marker = SmsMessage::query()
            ->where('counterparty_number', $normalized)
            ->where('direction', 'system')
            ->where('is_deleted', true)
            ->where(
                $this->supportsExtendedSmsLogColumns() ? 'template_tag' : 'message_body',
                self::CONVERSATION_DELETE_MARKER_TAG
            )
            ->orderByDesc('provider_received_at')
            ->orderByDesc('sms_message_id')
            ->first();

        return $marker?->provider_received_at;
    }

    private function ensureDeletedConversationMarker(string $counterpartyNumber, Carbon $cutoff): void
    {
        if (!$this->supportsDeletedSmsLogColumn() || $counterpartyNumber === '') {
            return;
        }

        $existingMarker = SmsMessage::query()
            ->where('counterparty_number', $counterpartyNumber)
            ->where('direction', 'system')
            ->where(
                $this->supportsExtendedSmsLogColumns() ? 'template_tag' : 'message_body',
                self::CONVERSATION_DELETE_MARKER_TAG
            )
            ->first();

        if ($existingMarker) {
            $existingCutoff = $existingMarker->provider_received_at;
            $resolvedCutoff = $existingCutoff && $existingCutoff->greaterThan($cutoff) ? $existingCutoff : $cutoff;

            $existingMarker->forceFill([
                'is_deleted' => true,
                'provider_received_at' => $resolvedCutoff,
                'provider_payload' => [
                    'type' => self::CONVERSATION_DELETE_MARKER_TAG,
                    'cutoff_at' => $resolvedCutoff->toDateTimeString(),
                ],
            ])->save();
            return;
        }

        $attributes = [
            'direction' => 'system',
            'provider_message_id' => 'deleted-conversation-' . $counterpartyNumber,
            'provider_original_message_id' => null,
            'user_id' => null,
            'branch_id' => null,
            'sender_name' => null,
            'from_number' => null,
            'to_number' => null,
            'normalized_from_number' => null,
            'normalized_to_number' => null,
            'counterparty_number' => $counterpartyNumber,
            'message_body' => self::CONVERSATION_DELETE_MARKER_TAG,
            'provider_received_at' => $cutoff,
            'provider_payload' => [
                'type' => self::CONVERSATION_DELETE_MARKER_TAG,
                'cutoff_at' => $cutoff->toDateTimeString(),
            ],
            'is_deleted' => true,
        ];

        if ($this->supportsExtendedSmsLogColumns()) {
            $attributes['reference_number'] = $this->generateReferenceNumber('DEL');
            $attributes['template_tag'] = self::CONVERSATION_DELETE_MARKER_TAG;
        }

        SmsMessage::create($attributes);
    }

    private function resolveConversationDeleteCutoff(string $counterpartyNumber, mixed $cutoffAt = null): ?Carbon
    {
        $requestedCutoff = $this->parseProviderTimestamp($cutoffAt);
        $latestVisible = SmsMessage::query()
            ->where('counterparty_number', $counterpartyNumber)
            ->where('direction', '!=', 'system')
            ->when($this->supportsDeletedSmsLogColumn(), fn ($query) => $query->where('is_deleted', false))
            ->orderByDesc('provider_received_at')
            ->orderByDesc('sms_message_id')
            ->first();

        $latestVisibleCutoff = $latestVisible?->provider_received_at;
        if ($requestedCutoff && $latestVisibleCutoff) {
            return $requestedCutoff->lessThan($latestVisibleCutoff) ? $requestedCutoff : $latestVisibleCutoff;
        }

        return $requestedCutoff ?? $latestVisibleCutoff;
    }

    private function shouldHideConversationMessage(?string $counterpartyNumber, mixed $receivedAt): bool
    {
        $cutoff = $this->getDeletedConversationCutoff($counterpartyNumber);
        $messageTimestamp = $receivedAt instanceof Carbon ? $receivedAt : $this->parseProviderTimestamp($receivedAt);

        return $cutoff !== null
            && $messageTimestamp !== null
            && $messageTimestamp->lessThanOrEqualTo($cutoff);
    }

    private function isDeletedStoredInboundMessage(array $message): bool
    {
        if (!$this->supportsDeletedSmsLogColumn()) {
            return false;
        }

        $providerMessageId = (string) ($message['id'] ?? '');
        if ($providerMessageId === '') {
            return false;
        }

        return SmsMessage::query()
            ->where('direction', 'inbound')
            ->where('provider_message_id', $providerMessageId)
            ->where('is_deleted', true)
            ->exists();
    }

    private function repairUnreadableStoredMessages(): void
    {
        $query = SmsMessage::query()
            ->where('direction', 'inbound')
            ->orderBy('sms_message_id');

        if ($this->supportsDeletedSmsLogColumn()) {
            $query->where('is_deleted', false);
        }

        $query->chunkById(100, function (Collection $messages) {
                foreach ($messages as $message) {
                    $decodedBody = $this->decodeStoredMessageText((string) $message->message_body);
                    $originalPayload = $message->provider_payload;
                    $normalizedPayload = $this->normalizeProviderPayloadBody($message->provider_payload);
                    $shouldUpdate = $decodedBody !== (string) $message->message_body
                        || $normalizedPayload !== $originalPayload;

                    if (!$shouldUpdate) {
                        continue;
                    }

                    $message->forceFill([
                        'message_body' => $decodedBody,
                        'provider_payload' => $normalizedPayload,
                    ])->save();
                }
            }, 'sms_message_id');
    }

    private function normalizeProviderPayloadBody(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }

        foreach (['message_body', 'MessageBody', 'message', 'body'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = $this->decodeStoredMessageText($payload[$key]);
            }
        }

        return $payload;
    }

    private function decodeStoredMessageText(?string $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || !$this->looksLikeHexEncodedMessage($text)) {
            return (string) $value;
        }

        $decoded = hex2bin($text);
        if ($decoded === false) {
            return (string) $value;
        }

        $decoded = preg_replace('/\x00+/', '', $decoded) ?? $decoded;

        if (!mb_check_encoding($decoded, 'UTF-8')) {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', 'UTF-8, ISO-8859-1, ASCII');
        }

        $decoded = trim($decoded);

        return $this->isReadableDecodedMessage($decoded) ? $decoded : (string) $value;
    }

    private function looksLikeHexEncodedMessage(string $value): bool
    {
        return strlen($value) >= 8
            && strlen($value) % 2 === 0
            && preg_match('/^[0-9A-Fa-f]+$/', $value) === 1;
    }

    private function isReadableDecodedMessage(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $printable = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        return $printable !== null
            && mb_strlen($printable) >= max(3, (int) floor(mb_strlen($value) * 0.7));
    }
}
