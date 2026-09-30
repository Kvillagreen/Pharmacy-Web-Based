<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sms:sync {--reconcile : Verify legacy records using stored gateway addressing}', function () {
    $service = app(\App\Services\v1\FortmedSmsService::class);
    if ($this->option('reconcile')) {
        \App\Models\v1\SmsMessage::where('scope_verified', false)->whereNotNull('provider_message_id')
            ->chunkById(100, function ($rows) use ($service) {
                $service->syncInboundMessages($rows->map(fn ($row) => [
                    'id' => $row->provider_message_id, 'direction' => $row->direction,
                    'from_number' => $row->from_number, 'to_number' => $row->to_number,
                    'message_body' => $row->message_body, 'received_at' => $row->provider_received_at,
                    'original_message_id' => $row->provider_original_message_id,
                    'raw' => $row->provider_payload ?? [],
                ])->all());
            }, 'sms_message_id');
    }
    $result = $service->syncGateway();
    $this->line(json_encode([
        'gateway_success' => $result['success'],
        'verified' => \App\Models\v1\SmsMessage::where('scope_verified', true)->count(),
        'quarantined' => \App\Models\v1\SmsMessage::where('scope_verified', false)->count(),
    ]));
    return $result['success'] ? 0 : 1;
})->purpose('Import SMS history using verified branch routing; never sends messages');

\Illuminate\Support\Facades\Schedule::command('sms:sync')->everyMinute()->withoutOverlapping();
