<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\v1\FortmedSmsService;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct(
        private readonly FortmedSmsService $smsService
    ) {
    }

    private function response(bool $success, string $message, $data = null, int $status = 200)
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public function replies(Request $request)
    {
        $limit = (int) $request->input('limit', 20);

        try {
            $result = $this->smsService->fetchReplies($limit);

            if ($result['status'] >= 400) {
                return $this->response(false, 'Unable to load SMS replies.', [
                    'messages' => [],
                    'defaults' => $this->smsService->defaults(),
                    'provider_response' => $result['raw'],
                ], 502);
            }

            return $this->response(true, 'SMS replies loaded successfully.', [
                'messages' => $result['messages'],
                'defaults' => $this->smsService->defaults(),
                'provider_response' => $result['raw'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to fetch SMS replies.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to load SMS replies.', [
                'messages' => [],
                'defaults' => $this->smsService->defaults(),
            ], 500);
        }
    }

    public function send(Request $request)
    {
        $defaults = $this->smsService->defaults();

        $validated = $request->validate([
            'to_number' => ['required', 'string', 'max:30'],
            'message_body' => ['required', 'string', 'max:2000'],
            'sender_name' => ['nullable', 'string', 'max:100'],
            'from_number' => ['nullable', 'string', 'max:30'],
        ]);

        $payload = [
            'SenderName' => trim((string) ($validated['sender_name'] ?? $defaults['sender_name'] ?? '')),
            'ToNumber' => trim((string) $validated['to_number']),
            'MessageBody' => trim((string) $validated['message_body']),
            'FromNumber' => trim((string) ($validated['from_number'] ?? $defaults['from_number'] ?? '')),
        ];

        if ($payload['SenderName'] === '' || $payload['FromNumber'] === '') {
            return $this->response(false, 'Sender name and from number are required before sending.', [
                'defaults' => $defaults,
            ], 422);
        }

        try {
            $result = $this->smsService->sendMessage($payload);

            if ($result['status'] >= 400) {
                return $this->response(false, 'Unable to send SMS reply.', [
                    'provider_response' => $result['raw'],
                ], 502);
            }

            return $this->response(true, 'SMS reply sent successfully.', [
                'provider_response' => $result['raw'],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to send SMS reply.', [
                'error' => $e->getMessage(),
            ]);

            return $this->response(false, 'Unable to send SMS reply.', null, 500);
        }
    }
}
