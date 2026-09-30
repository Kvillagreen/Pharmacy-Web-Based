<?php

namespace Tests\Feature;

use App\Models\v1\{Branch, SmsMessage, User};
use App\Services\v1\FortmedSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function message(int $branch, string $body, bool $verified = true): SmsMessage
    {
        return SmsMessage::create([
            'branch_id' => $branch, 'direction' => 'inbound', 'scope_verified' => $verified,
            'counterparty_number' => '639171234567', 'message_body' => $body,
            'provider_received_at' => now(), 'is_deleted' => false,
        ]);
    }

    public function test_history_logs_and_deletions_are_branch_scoped_even_for_same_customer(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'staff', 'status' => 'approved']);
        $other = Branch::factory()->create(['company_id' => $user->branch->company_id]);
        $own = $this->message($user->branch_id, 'Own message');
        $foreign = $this->message($other->branch_id, 'Other branch secret');
        $legacy = $this->message($user->branch_id, 'Unverified legacy secret', false);
        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/v1/sms/replies')->assertOk()->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.message_body', 'Own message')->assertDontSee('secret');
        $this->getJson('/api/v1/sms/logs')->assertOk()->assertJsonCount(1, 'data.logs');
        Http::assertNothingSent();
        $this->deleteJson('/api/v1/sms/messages/'.$foreign->sms_message_id)->assertNotFound();
        $this->deleteJson('/api/v1/sms/conversations/09171234567')->assertOk();
        $this->assertTrue($own->fresh()->is_deleted);
        $this->assertFalse($foreign->fresh()->is_deleted);
        $this->assertFalse($legacy->fresh()->is_deleted);
        $this->getJson('/api/v1/sms/diagnostics')->assertForbidden();
    }

    public function test_owner_history_is_company_scoped_and_honors_selected_branch(): void
    {
        $user = User::factory()->create(['role' => 'owner', 'status' => 'approved']);
        $sibling = Branch::factory()->create(['company_id' => $user->branch->company_id]);
        $foreign = Branch::factory()->create();
        $this->message($user->branch_id, 'Main');
        $this->message($sibling->branch_id, 'Sibling');
        $this->message($foreign->branch_id, 'Foreign secret');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/sms/logs')->assertOk()->assertJsonCount(2, 'data.logs')->assertDontSee('Foreign secret');
        $this->getJson('/api/v1/sms/logs?branch_id='.$sibling->branch_id)->assertOk()->assertJsonCount(1, 'data.logs')->assertJsonPath('data.logs.0.message_body', 'Sibling');
        $this->getJson('/api/v1/sms/replies?branch_id='.$foreign->branch_id)->assertForbidden();
    }

    public function test_import_uses_unique_gateway_destination_not_viewer_and_never_resurrects_deletions(): void
    {
        $a = Branch::factory()->create(['branch_contact' => '09170000001']);
        $b = Branch::factory()->create(['branch_contact' => '09170000002']);
        $service = app(FortmedSmsService::class);
        $record = ['id' => 'provider-one', 'from_number' => '09171234567', 'to_number' => '639170000001', 'message_body' => 'Routed to A'];
        $service->syncInboundMessages([$record], null, $b->branch_id);
        $stored = SmsMessage::where('provider_message_id', 'provider-one')->firstOrFail();
        $this->assertSame($a->branch_id, $stored->branch_id);
        $this->assertTrue($stored->scope_verified);
        $service->syncInboundMessages([$record], null, $b->branch_id);
        $this->assertSame($a->branch_id, $stored->fresh()->branch_id);
        $service->deleteStoredMessage($stored->sms_message_id, [$a->branch_id]);
        $service->syncInboundMessages([$record]);
        $this->assertTrue($stored->fresh()->is_deleted);
        Branch::factory()->create(['branch_contact' => '09170000001']);
        $record['id'] = 'ambiguous';
        $service->syncInboundMessages([$record]);
        $this->assertDatabaseMissing('sms_messages', ['provider_message_id' => 'ambiguous']);
    }

    public function test_gateway_failure_preserves_saved_history_and_send_is_not_retried(): void
    {
        config(['services.mysmsgate_sms.api_token' => 'test-token']);
        $attempts = 0;
        Http::fake(function () use (&$attempts) { $attempts++; return Http::failedConnection(); });
        $service = app(FortmedSmsService::class);
        $this->assertSame(503, $service->sendMessage(['ToNumber' => '09171234567', 'MessageBody' => 'Test'])['status']);
        $this->assertSame(1, $attempts);
        $user = User::factory()->create(['role' => 'staff', 'status' => 'approved']);
        $this->message($user->branch_id, 'Saved while offline');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sms/sync')->assertStatus(502)->assertDontSee('test-token');
        $this->getJson('/api/v1/sms/replies')->assertOk()->assertJsonPath('data.messages.0.message_body', 'Saved while offline');
    }

    public function test_history_pagination_does_not_drop_messages_or_expose_other_branches(): void
    {
        $user = User::factory()->create(['role' => 'branch_manager', 'status' => 'approved']);
        $foreign = Branch::factory()->create();
        for ($i = 0; $i < 25; $i++) $this->message($user->branch_id, 'Own '.$i);
        $this->message($foreign->branch_id, 'Foreign secret');
        $this->actingAs($user, 'sanctum');
        $first = $this->getJson('/api/v1/sms/replies?limit=1')->assertOk()->assertJsonPath('data.summary.total_messages', 20);
        $cursor = $first->json('data.summary.next_before_id');
        $this->assertNotNull($cursor);
        $this->getJson('/api/v1/sms/replies?limit=1&before_id='.$cursor)->assertOk()
            ->assertJsonPath('data.summary.total_messages', 5)->assertJsonPath('data.summary.next_before_id', null)->assertDontSee('Foreign secret');
    }
}
