<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\v1\Branch;
use App\Models\v1\Company;
use App\Models\v1\PasswordResetCode;
use App\Models\v1\Permission;
use App\Models\v1\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class QaseAuthenticationWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private function user(array $attributes = []): User
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->company_id]);

        return User::factory()->create(array_merge([
            'branch_id' => $branch->branch_id,
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('ValidPass123'),
            'status' => 'approved',
            'role' => 'staff',
        ], $attributes));
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->firstOrCreate(
            ['permission_name' => $name],
            ['description' => ucfirst($name)]
        );
    }

    private function registrationPayload(Branch $branch, array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Test',
            'lastName' => 'Registrant',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'ValidPass123',
            'branchId' => $branch->branch_id,
            'role' => 'staff',
            'address' => 'Bacolod City',
        ], $overrides);
    }

    public function test_tc_001_valid_credentials_issue_token_and_profile(): void
    {
        $user = $this->user();
        $user->permissions()->sync([$this->permission('sales')->permission_id]);

        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ValidPass123']);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.user_id', $user->user_id)
            ->assertJsonPath('data.branch_id', $user->branch_id)->assertJsonPath('data.permissions.0', 'sales');
        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue(Carbon::parse($response->json('expires_at'))->between(now()->addHours(7)->addMinutes(59), now()->addHours(8)->addMinute()));
    }

    public function test_tc_002_invalid_password_and_unknown_email_use_generic_failure(): void
    {
        $user = $this->user();
        foreach ([['email' => $user->email, 'password' => 'wrong'], ['email' => 'missing@example.test', 'password' => 'wrong']] as $payload) {
            $this->postJson('/api/v1/login', $payload)->assertOk()->assertJson(['success' => false, 'message' => 'Invalid credentials']);
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tc_003_pending_account_cannot_login(): void
    {
        $user = $this->user(['status' => 'pending']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ValidPass123'])
            ->assertOk()->assertJson(['success' => false, 'message' => 'Your account is not yet approved']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tc_005_login_validation_rejects_missing_and_malformed_fields(): void
    {
        foreach ([[[], 'Email is required.'], [['email' => 'bad', 'password' => 'x'], 'Email is invalid.'], [['email' => 'a@b.test'], 'Password is required.']] as [$payload, $message]) {
            $this->postJson('/api/v1/login', $payload)->assertStatus(422)->assertJson(['success' => false, 'message' => $message]);
        }
    }

    public function test_tc_006_login_revokes_previously_issued_tokens(): void
    {
        $user = $this->user();
        $oldToken = $user->createToken('old')->plainTextToken;
        $new = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ValidPass123'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($oldToken)->postJson('/api/v1/auth-user')->assertUnauthorized();
        $this->withToken($new->json('token'))->postJson('/api/v1/auth-user')->assertOk();
    }

    public function test_tc_007_logout_invalidates_current_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('current')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk()->assertJson(['success' => true, 'message' => 'Logged out']);
        auth()->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth-user')->assertUnauthorized();
    }

    public function test_tc_008_login_token_expires_after_eight_hours(): void
    {
        Carbon::setTestNow('2026-09-07 08:00:00');
        $user = $this->user();
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ValidPass123'])->assertOk();
        $this->assertSame(8, (int) now()->diffInHours(Carbon::parse($response->json('expires_at'))));
        $token = PersonalAccessToken::query()->firstOrFail();
        $this->assertSame('2026-09-07 16:00:00', $token->expires_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    public function test_tc_009_concurrent_submission_lock_rejects_duplicate(): void
    {
        $user = $this->user();
        $token = $user->createToken('lock')->plainTextToken;
        $payload = [];
        $key = 'request_lock:' . $user->user_id . ':api/v1/logout:' . hash('sha256', json_encode($payload));
        $lock = Cache::lock($key, 15);
        $this->assertTrue($lock->get());
        try {
            $this->withToken($token)->postJson('/api/v1/logout', $payload)->assertStatus(429)->assertJsonPath('success', false);
        } finally {
            $lock->release();
        }
    }

    public function test_tc_012_backend_denies_module_when_required_token_ability_is_absent(): void
    {
        $user = $this->user();
        $token = $user->createToken('limited', ['sales'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/reports')->assertForbidden();
    }

    public function test_tc_013_token_abilities_equal_user_permissions(): void
    {
        $user = $this->user();
        $user->permissions()->sync([$this->permission('sales')->permission_id, $this->permission('inventory')->permission_id]);
        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ValidPass123'])->assertOk();
        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertEqualsCanonicalizing(['sales', 'inventory'], $token->abilities);
    }

    public function test_tc_014_registration_creates_pending_hashed_user_with_permissions(): void
    {
        $branch = Branch::factory()->create();
        $this->permission('sales'); $this->permission('sms'); $this->permission('inventory'); $this->permission('fefo'); $this->permission('drugs'); $this->permission('settings'); $this->permission('dashboard');
        $payload = $this->registrationPayload($branch);
        $response = $this->postJson('/api/v1/register', $payload)->assertOk()->assertJsonPath('success', true);
        $user = User::findOrFail($response->json('data.user_id'));
        $this->assertSame('pending', $user->status);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertContains('sales', $user->permissions()->pluck('permission_name')->all());
    }

    public function test_tc_015_registration_rejects_duplicate_email(): void
    {
        $user = $this->user();
        $this->postJson('/api/v1/register', $this->registrationPayload($user->branch, ['email' => $user->email]))
            ->assertStatus(422)->assertJsonPath('message', 'Email already exists.');
    }

    public function test_tc_016_registration_enforces_eight_character_password(): void
    {
        $branch = Branch::factory()->create();
        $this->postJson('/api/v1/register', $this->registrationPayload($branch, ['password' => 'short']))
            ->assertStatus(422)->assertJsonPath('message', 'Password must be at least 8 characters.');
    }

    public function test_tc_017_registration_requires_every_mandatory_field(): void
    {
        $branch = Branch::factory()->create();
        $payload = $this->registrationPayload($branch);
        foreach (['firstName', 'lastName', 'email', 'password', 'branchId', 'role', 'address'] as $field) {
            $invalid = $payload; unset($invalid[$field]);
            $this->postJson('/api/v1/register', $invalid)->assertStatus(422)->assertJsonPath('success', false);
        }
    }

    public function test_tc_018_registration_role_assigns_current_default_permissions(): void
    {
        foreach (['dashboard', 'sales', 'sms', 'inventory', 'fefo', 'drugs', 'reports', 'users', 'settings', 'branches'] as $name) $this->permission($name);
        foreach (['staff' => ['sales','sms','inventory','fefo','drugs','settings'], 'branch_manager' => ['dashboard','sales','reports'], 'owner' => ['users','branches','sales']] as $role => $expected) {
            $branch = Branch::factory()->create();
            $response = $this->postJson('/api/v1/register', $this->registrationPayload($branch, ['role' => $role]))->assertOk();
            $actual = User::findOrFail($response->json('data.user_id'))->permissions()->pluck('permission_name')->all();
            foreach ($expected as $name) $this->assertContains($name, $actual, "$role lacks $name");
        }
    }

    public function test_tc_019_reset_request_creates_ten_minute_code_and_sends_mail(): void
    {
        Mail::fake(); Carbon::setTestNow('2026-09-07 09:00:00');
        $user = $this->user();
        $this->postJson('/api/v1/forgot-password/request', ['email' => $user->email])->assertOk()
            ->assertJson(['success' => true, 'message' => 'Password reset code sent successfully.', 'resend_available_in' => 60]);
        $row = PasswordResetCode::where('email', $user->email)->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $row->code);
        $this->assertSame('2026-09-07 09:10:00', $row->expires_at->format('Y-m-d H:i:s'));
        Mail::assertSent(PasswordResetCodeMail::class);
        Carbon::setTestNow();
    }

    public function test_tc_020_unknown_reset_email_has_neutral_response_without_side_effect(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/forgot-password/request', ['email' => 'unknown@example.test'])->assertOk()
            ->assertJson(['success' => true, 'message' => 'If the email exists, a password reset code has been sent.']);
        $this->assertDatabaseCount('password_reset_codes', 0); Mail::assertNothingSent();
    }

    public function test_tc_021_reset_resend_is_throttled_then_replaces_old_code(): void
    {
        Mail::fake(); Carbon::setTestNow('2026-09-07 10:00:00'); $user = $this->user();
        $this->postJson('/api/v1/forgot-password/request', ['email' => $user->email])->assertOk();
        $old = PasswordResetCode::where('email', $user->email)->firstOrFail();
        $this->postJson('/api/v1/forgot-password/resend', ['email' => $user->email])->assertStatus(429);
        Carbon::setTestNow('2026-09-07 10:01:01');
        $this->postJson('/api/v1/forgot-password/resend', ['email' => $user->email])->assertOk();
        $this->assertNotNull($old->fresh()->used_at); $this->assertDatabaseCount('password_reset_codes', 2); Carbon::setTestNow();
    }

    public function test_tc_022_valid_reset_changes_password_and_consumes_code(): void
    {
        $user = $this->user(); $user->createToken('old');
        $row = PasswordResetCode::create(['user_id'=>$user->user_id,'email'=>$user->email,'code'=>'123456','expires_at'=>now()->addMinutes(10),'resend_available_at'=>now()]);
        $this->postJson('/api/v1/forgot-password/reset', ['email'=>$user->email,'code'=>'123456','password'=>'NewValid123','password_confirmation'=>'NewValid123'])->assertOk();
        $this->assertTrue(Hash::check('NewValid123', $user->fresh()->password)); $this->assertNotNull($row->fresh()->used_at); $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_tc_023_expired_reset_code_is_rejected_and_consumed(): void
    {
        $user=$this->user(); $row=PasswordResetCode::create(['user_id'=>$user->user_id,'email'=>$user->email,'code'=>'123456','expires_at'=>now()->subSecond(),'resend_available_at'=>now()]);
        $this->postJson('/api/v1/forgot-password/reset', ['email'=>$user->email,'code'=>'123456','password'=>'NewValid123','password_confirmation'=>'NewValid123'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertNotNull($row->fresh()->used_at);
    }

    public function test_tc_024_invalid_and_used_reset_codes_are_rejected(): void
    {
        $user=$this->user(); PasswordResetCode::create(['user_id'=>$user->user_id,'email'=>$user->email,'code'=>'123456','expires_at'=>now()->addMinute(),'resend_available_at'=>now(),'used_at'=>now()]);
        foreach (['000000','123456'] as $code) $this->postJson('/api/v1/forgot-password/reset', ['email'=>$user->email,'code'=>$code,'password'=>'NewValid123','password_confirmation'=>'NewValid123'])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_tc_025_reset_requires_confirmed_eight_character_password(): void
    {
        $user=$this->user();
        foreach ([['short','short'],['NewValid123','different']] as [$password,$confirmation]) {
            $this->postJson('/api/v1/forgot-password/reset', ['email'=>$user->email,'code'=>'123456','password'=>$password,'password_confirmation'=>$confirmation])->assertStatus(422)->assertJsonValidationErrors('password');
        }
    }

    public function test_tc_026_successful_reset_revokes_all_tokens(): void
    {
        $user=$this->user(); $user->createToken('a'); $user->createToken('b');
        PasswordResetCode::create(['user_id'=>$user->user_id,'email'=>$user->email,'code'=>'123456','expires_at'=>now()->addMinute(),'resend_available_at'=>now()]);
        $this->postJson('/api/v1/forgot-password/reset', ['email'=>$user->email,'code'=>'123456','password'=>'NewValid123','password_confirmation'=>'NewValid123'])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id'=>$user->user_id]);
    }
}
