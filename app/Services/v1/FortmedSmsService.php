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
        $fromNumber = trim((string) ($payload['FromNumber'] ?? config('services.mysmsgate_sms.from_number', '')));
        $senderName = trim((string) ($payload['SenderName'] ?? config('services.mysmsgate_sms.sender_name', '')));

        $providerPayload = [
            'to' => '+'.$this->normalizePhoneNumber($payload['ToNumber'] ?? ''),
            'message' => $payload['MessageBody'] ?? '',
        ];
        $slot=config('services.mysmsgate_sms.slot');
        if($slot!==null && $slot!=='')$providerPayload['slot']=(int)$slot;

        if ($device=trim((string)config('services.mysmsgate_sms.device_id',''))) $providerPayload['device_id']=$device;

        $response = $this->performRequest(
            'POST',
            $this->endpoint('send'),
            $providerPayload
        );

        $accepted=$response['status']>=200 && $response['status']<300 && is_array($response['decoded']) && ($response['decoded']['success']??false)===true && $this->extractProviderMessageId($response['decoded']);
        return [
            'status' => $accepted ? $response['status'] : ($response['status']>=400?$response['status']:502),
            'raw' => $response['decoded'],
        ];
    }

    public function defaults(): array
    {
        $senderName = 'Sto. Rosario Drug Store';
        $fromNumber = trim((string) config('services.mysmsgate_sms.from_number', ''));

        if ($fromNumber === '') {
            $branch = \App\Models\v1\Branch::where('status', 'active')
                ->whereNotNull('branch_contact')
                ->where('branch_contact', '!=', '')
                ->first();
            $fromNumber = (string) ($branch?->branch_contact ?? '');
        }

        if ($senderName === '') {
            $company = \App\Models\v1\Company::first();
            $senderName = (string) ($company?->company_name ?? 'KMV Pharmacy');
        }

        return [
            'sender_name' => $senderName,
            'from_number' => $fromNumber,
        ];
    }

    public function diagnostics(): array
    {
        $token = $this->apiToken();
        $probe = $this->fetchReplies(1);
        $raw = is_array($probe['raw']) ? $probe['raw'] : ['response' => $probe['raw']];

        return [
            'config' => $this->debugConfig(),
            'database' => $this->debugDatabase(),
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

    public function deviceStatus(): array {
        $result=$this->performRequest('GET',$this->endpoint('devices'));
        return ['status'=>$result['status'],'devices'=>collect($result['decoded']['devices']??[])->map(fn($d)=>['name'=>$d['name']??'Device','status'=>$d['status']??'unknown','default_sim_slot'=>$d['default_sim_slot']??null])->all(),'message'=>is_array($result['decoded'])?($result['decoded']['message']??null):null];
    }

    public function debugContext(): array
    {
        return [
            'config' => $this->debugConfig(),
            'database' => $this->debugDatabase(),
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

    /** Shared gateway records must be routed independently of the viewer. */
    public function syncInboundMessages(array $messages, ?int $userId = null, ?int $branchId = null): void
    {
        $branches = \App\Models\v1\Branch::where('status', 'active')->get();
        $contacts = $branches->groupBy(fn ($branch) => $this->normalizePhoneNumber($branch->branch_contact));
        foreach ($messages as $message) {
            $id = (string) ($message['id'] ?? '');
            if ($id === '') continue;
            $direction = ($message['direction'] ?? 'inbound') === 'outbound' ? 'outbound' : 'inbound';
            $existing = SmsMessage::where('direction', $direction)->where('provider_message_id', $id)->first();
            if ($existing?->is_deleted) continue;
            if ($existing?->scope_verified) {
                if ($direction==='outbound') $existing->update(['provider_payload'=>$message['raw']??$existing->provider_payload]);
                continue;
            }
            $originalId = $message['raw']['original_message_id'] ?? $message['original_message_id'] ?? null;
            $parent = $originalId ? SmsMessage::where('direction', 'outbound')->where('scope_verified', true)
                ->where('provider_message_id', $originalId)->first() : null;
            $phone = $this->normalizePhoneNumber($direction === 'inbound' ? ($message['to_number'] ?? '') : ($message['from_number'] ?? ''));
            $matches = $phone !== '' ? $contacts->get($phone, collect()) : collect();
            // Catalog templates include the selected branch name before ':'.
            // Only use that explicit routing label among branches sharing the destination.
            if ($direction === 'inbound' && $matches->count() > 1) {
                $label = trim(explode(':', (string) ($message['message_body'] ?? ''), 2)[0]);
                $matches = $matches->filter(fn ($branch) => strcasecmp(
                    preg_replace('/\s+/', ' ', trim($branch->branch_name)), $label) === 0);
            }
            $resolvedBranch = $parent?->branch_id ?? ($matches->count() === 1 ? $matches->first()->branch_id : null);

            // An explicit, unique branch label can route a shared gateway reply.
            // Never guess from the latest customer interaction or the first branch.
            if (!$resolvedBranch && $direction === 'inbound' && $phone === '') {
                $label=trim(explode(':',(string)($message['message_body']??''),2)[0]);
                $named=$branches->filter(fn($b)=>strcasecmp(trim($b->branch_name),$label)===0);
                if($named->count()===1)$resolvedBranch=$named->first()->branch_id;
            }
            if (!$resolvedBranch) continue;

            $from = $this->normalizePhoneNumber($message['from_number'] ?? '');
            $to = $this->normalizePhoneNumber($message['to_number'] ?? '');
            if ($to === '') {
                $branchObj = $branches->firstWhere('branch_id', $resolvedBranch);
                $to = $this->normalizePhoneNumber($branchObj?->branch_contact ?: config('services.mysmsgate_sms.from_number'));
            }
            SmsMessage::updateOrCreate(['direction' => $direction, 'provider_message_id' => $id], [
                'scope_verified' => true, 'branch_id' => $resolvedBranch, 'user_id' => null,
                'provider_original_message_id' => $originalId,
                'reference_number' => $existing?->reference_number ?: $this->generateReferenceNumber('IN'),
                'from_number' => $from, 'to_number' => $to,
                'normalized_from_number' => $from, 'normalized_to_number' => $to,
                'counterparty_number' => $direction === 'inbound' ? $from : $to,
                'message_body' => $this->decodeStoredMessageText((string) ($message['message_body'] ?? '')),
                'provider_received_at' => $this->parseProviderTimestamp($message['received_at'] ?? null),
                'provider_payload' => $message['raw'] ?? [], 'is_deleted' => false,
            ]);
        }
    }

    public function syncGateway(): array
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('sms_gateway_sync_lock', 20);
        if (!$lock->get()) return ['success' => true];
        try {
            $recent = \Illuminate\Support\Facades\Cache::get('sms_gateway_sync_result');
            if ($recent !== null) return $recent;
            $result = $this->fetchReplies(100);
            $success = $result['status'] >= 200 && $result['status'] < 300;
            if ($success) $this->syncInboundMessages($result['messages']);
            $status = ['success' => $success];
            \Illuminate\Support\Facades\Cache::put('sms_gateway_sync_result', $status, 15);
            return $status;
        } finally { $lock->release(); }
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
            'scope_verified' => true,
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

    public function getStoredConversations(int $limit = 20, array $branchIds = [], ?int $beforeId = null): array
    {
        if (!$this->smsMessageTableExists()) {
            return [
                'conversations' => [],
                'total_messages' => 0,
                'unique_customers' => 0,
            ];
        }

        $query = SmsMessage::query()->whereIn('branch_id', $branchIds)->where('scope_verified', true)
            ->when($beforeId, fn ($q) => $q->where('sms_message_id', '<', $beforeId))
            ->orderByDesc('sms_message_id');

        if ($this->supportsDeletedSmsLogColumn()) {
            $query->where('is_deleted', false);
        }

        $pageSize = max(20, min($limit * 10, 250));
        $messages = $query->limit($pageSize + 1)->get();
        $hasMore = $messages->count() > $pageSize;
        $messages = $messages->take($pageSize);

        $grouped = $messages
            ->groupBy(fn (SmsMessage $message) => $message->counterparty_number ?: 'unknown')
            ->map(fn (Collection $conversation, string $key) => $this->transformConversation($conversation, $key))
            ->sortByDesc(fn (array $conversation) => $conversation['last_received_at'] ?? '')
            ->values()
            ->all();

        return [
            'conversations' => $grouped,
            'total_messages' => $messages->count(),
            'unique_customers' => collect($grouped)->count(),
            'next_before_id' => $hasMore ? $messages->last()?->sms_message_id : null,
        ];
    }

    public function getStoredLogs(int $limit = 100, array $branchIds = []): array
    {
        if (!$this->smsMessageTableExists()) {
            return [];
        }

        $query = SmsMessage::query()->whereIn('branch_id', $branchIds)->where('scope_verified', true)
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
            'received_at' => $this->formatSmsTimestamp($message->provider_received_at),
        ])->values()->all();
    }

    public function deleteStoredMessage(int $messageId, array $branchIds = []): bool
    {
        if (!$this->smsMessageTableExists()) {
            return false;
        }

        $query = SmsMessage::query()->whereIn('branch_id', $branchIds)->where('scope_verified', true)->where('sms_message_id', $messageId);

        if (!$this->supportsDeletedSmsLogColumn()) {
                return $query->delete() > 0;
        }

        return $query->update(['is_deleted' => true]) > 0;
    }

    public function deleteConversation(?string $counterpartyNumber, mixed $cutoffAt = null, array $branchIds = []): int
    {
        if (!$this->smsMessageTableExists()) {
            return 0;
        }

        $normalized = $this->normalizePhoneNumber($counterpartyNumber);
        if ($normalized === '') {
            return 0;
        }

        $cutoff = $cutoffAt ? $this->parseProviderTimestamp($cutoffAt) : now();
        if (!$cutoff) {
            return 0;
        }

        $query = SmsMessage::query()->whereIn('branch_id', $branchIds)->where('scope_verified', true)
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
                'verify' => config('services.http_ca_bundle', true),
            ])
            ->timeout(8)
            ->connectTimeout(3);
    }

    private function endpoint(string $path): string
    {
        $baseUrl = trim((string) config('services.mysmsgate_sms.proxy_url', ''))
            ?: (string) config('services.mysmsgate_sms.base_url');

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
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
            \Log::warning('SMS gateway request failed.', ['type' => class_basename($exception)]);
            // Never retry a timed-out send: the provider may already have accepted it.
            return ['status' => 503, 'decoded' => ['success' => false, 'message' => 'SMS gateway unavailable.']];
        }
    }

    private function decodeBody(?string $body): mixed
    {
        $body = trim((string) $body);

        if ($body === '') {
            return '';
        }

        if ($this->looksLikeCloudflareChallenge($body)) {
            return [
                'success' => false,
                'message' => 'SMS gateway request was blocked by Cloudflare before reaching the API.',
                'error' => [
                    'type' => 'cloudflare_challenge',
                    'hint' => 'Laravel Cloud outbound IP is being challenged. Configure SMS_PROXY_URL or ask MySmsGate to whitelist the server/API path.',
                ],
            ];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $body;
    }

    private function looksLikeCloudflareChallenge(string $body): bool
    {
        $lower = strtolower($body);

        return str_contains($lower, 'just a moment')
            || str_contains($lower, 'cf-browser-verification')
            || str_contains($lower, 'challenge-platform')
            || str_contains($lower, 'cloudflare');
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

    private function debugConfig(): array
    {
        $token = $this->apiToken();

        return [
            'base_url' => (string) config('services.mysmsgate_sms.base_url', ''),
            'api_key_configured' => $token !== '' && $token !== 'YOUR_MYSMSGATE_API_KEY',
            'api_key_length' => strlen($token),
            'sender_name' => (string) config('services.mysmsgate_sms.sender_name', ''),
            'from_number' => (string) config('services.mysmsgate_sms.from_number', ''),
            'slot' => (int) config('services.mysmsgate_sms.slot', 0),
        ];
    }

    private function debugDatabase(): array
    {
        $columns = [
            'sms_message_id',
            'reference_number',
            'template_tag',
            'direction',
            'provider_message_id',
            'provider_original_message_id',
            'user_id',
            'branch_id',
            'sender_name',
            'from_number',
            'to_number',
            'normalized_from_number',
            'normalized_to_number',
            'counterparty_number',
            'message_body',
            'provider_received_at',
            'provider_payload',
            'is_deleted',
        ];

        try {
            $hasTable = Schema::hasTable('sms_messages');

            return [
                'connection' => (string) config('database.default'),
                'sms_messages_table' => $hasTable,
                'columns' => $hasTable
                    ? collect($columns)
                        ->mapWithKeys(fn (string $column) => [$column => Schema::hasColumn('sms_messages', $column)])
                        ->all()
                    : [],
            ];
        } catch (\Throwable $exception) {
            return [
                'connection' => (string) config('database.default'),
                'sms_messages_table' => false,
                'error' => [
                    'type' => class_basename($exception),
                    'message' => $exception->getMessage(),
                ],
            ];
        }
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
            $timezone = config('app.timezone', 'Asia/Manila');
            $timestamp = trim((string) $value);
            $sourceTimezone = $this->timestampHasTimezone($timestamp) ? null : 'UTC';

            return Carbon::parse($timestamp, $sourceTimezone)->timezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function timestampHasTimezone(string $value): bool
    {
        return preg_match('/(?:[zZ]|[+-]\d{2}:?\d{2})$/', trim($value)) === 1;
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
                'delivery_status' => $message->direction==='outbound' ? ($message->provider_payload['status']??'pending') : null,
                'template_tag' => $message->template_tag,
                'direction' => $message->direction,
                'from_number' => $this->formatDisplayPhoneNumber($message->from_number),
                'to_number' => $this->formatDisplayPhoneNumber($message->to_number),
                'normalized_from_number' => $message->normalized_from_number,
                'normalized_to_number' => $message->normalized_to_number,
                'message_body' => $message->message_body,
                'sender_name' => $message->sender_name,
                'received_at' => $this->formatSmsTimestamp($message->provider_received_at),
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
            'received_at' => $this->formatSmsTimestamp($latest?->provider_received_at),
            'last_received_at' => $this->formatSmsTimestamp($latest?->provider_received_at),
            'message_count' => $sorted->count(),
            'history' => $history,

        ];
    }

    private function normalizeTemplateTag(mixed $value): ?string
    {
        $templateTag = trim((string) ($value ?? ''));

        return $templateTag !== '' ? $templateTag : 'Custom Reply';
    }

    private function formatSmsTimestamp(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            $timezone = config('app.timezone', 'Asia/Manila');
            $timestamp = $value instanceof Carbon
                ? $value->copy()
                : Carbon::parse((string) $value, $timezone);

            return $timestamp->timezone($timezone)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
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
