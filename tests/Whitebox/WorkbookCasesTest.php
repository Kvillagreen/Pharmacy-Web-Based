<?php

use App\Http\Controllers\v1\{AuthController, UserController};
use App\Http\Requests\v1\{LoginRequest, RegisterRequest, MethodBranchRequest, MethodCompanyRequest, MethodMedicineRequest, MethodTransactionRequest};
use App\Models\v1\{Batch, Branch, Company, Inventory, Medicine, SuperAdmin, Transaction, User, UserNotification};
use App\Services\v1\{FortmedSmsService, MedicineQuery, UserQuery};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, Http, Mail, Route, Validator};

// Workbook WB-001 through WB-021. No placeholder assertions or live integrations.
beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    Mail::fake();
});

function wbUser(string $role = 'owner', array $attributes = []): User
{
    return User::factory()->create(['role' => $role, 'status' => 'approved', ...$attributes]);
}

function wbStock(User $user, array $quantities = [2, 5]): array
{
    $medicine = Medicine::factory()->create(['price' => 10, 'cost_price' => 5, 'stocks' => array_sum($quantities), 'is_dangerous' => false, 'needs_protection' => false]);
    $inventories = [];
    foreach ($quantities as $i => $quantity) {
        $batch = Batch::factory()->create(['batch_number' => 'WB-BATCH-'.$i, 'expiry_date' => today()->addMonths(15 + $i)->toDateString()]);
        $inventories[] = Inventory::create(['branch_id' => $user->branch_id, 'medicine_id' => $medicine->medicine_id, 'batch_id' => $batch->batch_id, 'stocks' => $quantity, 'cost_price' => 5]);
    }
    return [$medicine, $inventories];
}

function wbSale(User $user, Medicine $medicine, int $quantity, array $item = []): array
{
    return ['user_id' => $user->user_id, 'branch_id' => $user->branch_id, 'transaction_type' => 'regular',
        'total_amount' => 10 * $quantity, 'sub_total' => 10 * $quantity, 'used_amount' => 10 * $quantity,
        'change' => 0, 'discount' => 0, 'payment_method' => 'Cash',
        'items' => [['medicine_id' => $medicine->medicine_id, 'quantity' => $quantity, ...$item]]];
}

function wbMedicineInput(User $user): array
{
    return ['branch_id' => $user->branch_id, 'medicine_name' => 'Whitebox Medicine', 'generic_name' => 'Test Generic',
        'category' => 'Analgesic', 'pricing_type' => 'branded', 'cost_price' => 10,
        'reorder_level' => 2, 'stocks' => 12, 'dosage' => 500, 'unit' => 'mg', 'units_per_box' => 10,
        'type' => 'Tablet', 'is_dangerous' => false, 'needs_protection' => false,
        'location' => 'Test shelf', 'batch_number' => '', 'mfg_date' => today()->subMonth()->toDateString(),
        'received_date' => today()->toDateString(), 'expiry_date' => today()->addYears(2)->toDateString()];
}

test('WB-001 API contract - public catalog response envelope', function () {
    $response = $this->getJson('/api/v1/catalog')->assertOk()->assertJsonStructure(['success', 'message', 'data']);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('data'))->toBeArray();
});

test('WB-002 Dashboard - authenticated aggregation and eight KPI keys', function () {
    $user = wbUser('admin');
    $this->actingAs($user, 'sanctum');
    [$medicine] = wbStock($user);
    $this->postJson('/api/v1/transaction', wbSale($user, $medicine, 2))->assertCreated();
    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonStructure(['data' => ['summary' => [
        'total_revenue', 'transaction_count', 'average_sale', 'inventory_value', 'low_stock_count',
        'out_of_stock_count', 'expiring_30_count', 'expired_count',
    ]]])->assertJsonPath('data.summary.total_revenue', 20)->assertJsonPath('data.summary.transaction_count', 1);
});

test('WB-003 User visibility - own branch, own company and foreign scope', function () {
    $staff = wbUser('staff');
    $sibling = Branch::factory()->create(['company_id' => $staff->branch->company_id]);
    $other = Branch::factory()->create();
    $sameCompany = wbUser('staff', ['branch_id' => $sibling->branch_id]);
    $foreign = wbUser('staff', ['branch_id' => $other->branch_id]);
    $owner = wbUser('owner', ['branch_id' => $staff->branch_id]);
    $method = new ReflectionMethod(UserController::class, 'applyUserVisibilityScope');
    $request = Request::create('/', 'GET', ['company_id' => $other->company_id]);
    $staffIds = $method->invoke(new UserController(), $request, User::query(), $staff)->pluck('user_id')->all();
    expect($staffIds)->toContain($staff->user_id)->not->toContain($sameCompany->user_id)->not->toContain($foreign->user_id);
    $ownerIds = $method->invoke(new UserController(), $request, User::query(), $owner)->pluck('user_id')->all();
    expect($ownerIds)->toContain($staff->user_id)->toContain($sameCompany->user_id)->not->toContain($foreign->user_id);
});

test('WB-004 FEFO - ordered depletion and shortage rollback', function () {
    $user = wbUser(); $this->actingAs($user, 'sanctum');
    [$medicine, $stocks] = wbStock($user);
    $this->postJson('/api/v1/transaction', wbSale($user, $medicine, 4))->assertCreated();
    expect(array_map(fn ($i) => (int) $i->fresh()->stocks, $stocks))->toBe([0, 3]);
    $this->postJson('/api/v1/transaction', wbSale($user, $medicine, 3))->assertCreated();
    expect(array_map(fn ($i) => (int) $i->fresh()->stocks, $stocks))->toBe([0, 0]);
    $before = Transaction::count();
    $items = DB::table('transaction_items')->count();
    $this->postJson('/api/v1/transaction', wbSale($user, $medicine, 1))->assertStatus(422);
    expect(Transaction::count())->toBe($before);
    expect(DB::table('transaction_items')->count())->toBe($items);
    expect((int) $medicine->fresh()->stocks)->toBe(0);
});

test('WB-005 Notifications - cap, transfer action and read-count transition', function () {
    $user = wbUser('admin', ['notify_user_registrations' => false, 'notify_low_stock' => false, 'notify_expiry_alerts' => false, 'notify_security_alerts' => false]);
    $this->actingAs($user, 'sanctum');
    $make = fn ($type) => UserNotification::create(['user_id' => $user->user_id, 'branch_id' => $user->branch_id, 'type' => $type, 'title' => 'Whitebox notification', 'message' => 'Fixture only']);
    $ordinary = $make('system'); $transfer = $make('transfer_request');
    $before = $this->getJson('/api/v1/header/notifications')->assertOk()->json('data');
    $this->putJson('/api/v1/header/notifications/'.$ordinary->user_notification_id.'/read')->assertOk();
    expect($ordinary->fresh()->read_at)->not->toBeNull();
    $after = $this->getJson('/api/v1/header/notifications')->assertOk()->json('data');
    for ($i = 0; $i < 14; $i++) $make('system');
    expect(count($this->getJson('/api/v1/header/notifications')->assertOk()->json('data.notifications')))->toBeLessThanOrEqual(12);
    $actionable = collect($before['notifications'])->firstWhere('notification_id', $transfer->user_notification_id)['is_actionable'] ?? false;
    // Assert the exact workbook expectation; do not silently rewrite obsolete cases to pass.
    expect(['transfer_actionable' => $actionable, 'unread_before' => $before['unread_count'], 'unread_after' => $after['unread_count']])
        ->toBe(['transfer_actionable' => true, 'unread_before' => 2, 'unread_after' => 1]);
});

test('WB-006 Packaging - units per box boundaries 0, 1, 10000, 10001', function () {
    $rules = (new MethodMedicineRequest())->rules()['units_per_box'];
    foreach ([[0, false], [1, true], [10000, true], [10001, false]] as [$value, $valid]) {
        expect(Validator::make(['units_per_box' => $value], ['units_per_box' => $rules])->passes())->toBe($valid);
    }
});

test('WB-007 Authentication - protected user and super-admin endpoints', function () {
    foreach (['/api/v1/user', '/api/v1/admin/super-admins'] as $url) {
        $this->getJson($url)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }
});

test('WB-008 Public access - catalog and branch collections without token', function () {
    Branch::factory()->create();
    foreach ([$this->getJson('/api/v1/catalog'), $this->postJson('/api/v1/branch-public')] as $response) {
        $response->assertOk()->assertJsonPath('success', true);
        expect($response->json('data'))->toBeArray();
    }
});

test('WB-009 Super admin - list create hash relist and login', function () {
    $creator = SuperAdmin::create(['first_name' => 'Test', 'last_name' => 'Creator', 'email' => 'creator@whitebox.test', 'password' => Hash::make('Test-password-123')]);
    $this->actingAs($creator, 'sanctum');
    $this->getJson('/api/v1/admin/super-admins')->assertOk()->assertJsonFragment(['email' => $creator->email]);
    $payload = ['first_name' => 'Test', 'last_name' => 'New Admin', 'email' => 'new-admin@whitebox.test', 'password' => 'Test-password-123'];
    $this->postJson('/api/v1/admin/super-admins', $payload)->assertCreated();
    $stored = SuperAdmin::where('email', $payload['email'])->firstOrFail();
    expect(Hash::check($payload['password'], $stored->password))->toBeTrue();
    expect($stored->password)->not->toBe($payload['password']);
    $this->getJson('/api/v1/admin/super-admins')->assertOk()->assertJsonFragment(['email' => $payload['email']]);
    $login = $this->postJson('/api/v1/admin/login', ['email' => $payload['email'], 'password' => $payload['password']])
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.role', 'super_admin');
    expect($login->json('token'))->toBeString()->not->toBeEmpty();
});

test('WB-010 Routing - exact unknown endpoint envelope', function () {
    $this->getJson('/api/v1/this-endpoint-does-not-exist')->assertNotFound()->assertExactJson(['success' => false, 'message' => 'Endpoint not found']);
});

test('WB-011 RBAC - all five operational roles have Sales POS', function () {
    foreach (['staff', 'pharmacist', 'branch_manager', 'owner', 'admin'] as $role) {
        foreach ([[AuthController::class, 'rolePermissionNames'], [UserController::class, 'defaultRolePermissionNames']] as [$class, $method]) {
            expect((new ReflectionMethod($class, $method))->invoke(new $class(), $role))->toContain('sales');
        }
    }
});

test('WB-012 RBAC - super-admin excluded from branch POS', function () {
    expect((new ReflectionMethod(AuthController::class, 'rolePermissionNames'))->invoke(new AuthController(), 'super_admin'))->not->toContain('sales');
});

test('WB-013 Exact batch - explicit inventory and batch selection', function () {
    $rules = (new MethodTransactionRequest())->rules();
    expect($rules['items.*.inventory_id'])->toContain('exists:inventories,inventory_id');
    expect($rules['items.*.batch_id'])->toContain('exists:batches,batch_id');
    $user = wbUser(); $this->actingAs($user, 'sanctum');
    [$medicine, $stocks] = wbStock($user);
    $this->postJson('/api/v1/transaction', wbSale($user, $medicine, 1, ['inventory_id' => $stocks[1]->inventory_id, 'batch_id' => $stocks[1]->batch_id]))->assertCreated();
    expect(array_map(fn ($i) => (int) $i->fresh()->stocks, $stocks))->toBe([2, 4]);
    expect((int) DB::table('transaction_items')->value('batch_id'))->toBe((int) $stocks[1]->batch_id);
});

test('WB-014 Controlled drugs - S2 exactly twelve digits', function () {
    $rules = (new MethodTransactionRequest())->rules()['prescriber_s2_license_number'];
    foreach (['123456789012' => true, '12345678901' => false, '1234567890123' => false, 'ABCDEFGHIJKL' => false] as $value => $valid) {
        expect(Validator::make(['s2' => (string) $value], ['s2' => $rules])->passes())->toBe($valid);
    }
});

test('WB-015 Inventory - mandatory packaging and generated batch identity', function () {
    $rules = (new MethodMedicineRequest())->rules();
    expect(Validator::make([], ['units_per_box' => $rules['units_per_box']])->fails())->toBeTrue();
    $user = wbUser(); $this->actingAs($user, 'sanctum');
    $created = $this->postJson('/api/v1/medicine', wbMedicineInput($user))->assertCreated();
    expect($created->json('data.batch.batch_number'))->toBeString()->not->toBeEmpty();
    expect((int) $created->json('data.medicine.units_per_box'))->toBe(10);
});

test('WB-016 Pricing - required inputs and branded generic SRP calculation', function () {
    $rules = (new MethodMedicineRequest())->rules();
    foreach (['cost_price', 'pricing_type'] as $field) expect($rules[$field])->toContain('required');
    expect(Validator::make(['cost_price' => 0], ['cost_price' => $rules['cost_price']])->fails())->toBeTrue();
    foreach (['branded' => [10, 110], 'generic' => [50, 150]] as $type => [$markup, $price]) {
        $request = new MethodMedicineRequest(['pricing_type' => $type, 'cost_price' => 100, 'markup_percent' => 999, 'price' => 1]);
        (new ReflectionMethod($request, 'prepareForValidation'))->invoke($request);
        expect((float) $request->input('markup_percent'))->toBe((float) $markup);
        expect((float) $request->input('price'))->toBe((float) $price);
    }
});

test('WB-017 Application - HTTP kernel root smoke test', function () {
    $this->get('/')->assertSuccessful();
});

test('WB-018 Protected route matrix - every registered protected API method', function () {
    $checked = [];
    foreach (Route::getRoutes() as $route) {
        if (!str_starts_with($route->uri(), 'api/v1/') || !in_array('auth:sanctum', $route->gatherMiddleware(), true)) continue;
        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) continue;
            $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
            $checked[] = "$method $uri";
        }
    }
    expect(count($checked))->toBeGreaterThanOrEqual(37);
    if ($dir = getenv('WHITEBOX_EVIDENCE_DIR')) file_put_contents($dir.'/protected-routes.json', json_encode($checked, JSON_PRETTY_PRINT));
});

test('WB-019 Query services - operator mapping and unsafe sort rejection', function () {
    $medicine = new MedicineQuery();
    expect($medicine->transform(Request::create('/', 'GET', ['price' => ['gt' => 10], 'password' => ['eq' => 'secret']])))->toBe([['price', '>', 10]]);
    expect($medicine->getSort(Request::create('/', 'GET', ['sort' => '-stocks'])))->toBe(['inventories.stocks', 'desc']);
    $users = new UserQuery();
    expect($users->transform(Request::create('/', 'GET', ['role' => ['eq' => 'staff'], 'password' => ['eq' => 'secret']])))->toBe([['role', '=', 'staff']]);
    expect($users->getSort(Request::create('/', 'GET', ['sort' => '-first_name'])))->toBe(['first_name', 'desc']);
    foreach ([$medicine, $users] as $service) {
        expect($service->getSort(Request::create('/', 'GET', ['sort' => 'password; DROP TABLE users'])))->toBeNull();
        expect($service->transform(Request::create('/', 'GET', ['unknown' => ['eq' => 1], 'role' => ['unsafe' => 1]])))->toBe([]);
    }
});

test('WB-020 SMS - local international bare and empty phone normalization', function () {
    $service = app(FortmedSmsService::class);
    foreach (['09171234567', '9171234567', '+639171234567', ''] as $input) {
        expect($service->normalizePhoneNumber($input))->toBe($input === '' ? '' : '639171234567');
        expect($service->formatDisplayPhoneNumber($input))->toBe($input === '' ? '' : '09171234567');
    }
});

test('WB-021 Core validation - mandatory fields across six request classes', function () {
    $matrix = [LoginRequest::class => ['email', 'password'], RegisterRequest::class => ['firstName', 'lastName', 'email', 'password', 'branchId', 'role'],
        MethodBranchRequest::class => ['company_id', 'branch_name', 'branch_address', 'branch_contact'],
        MethodCompanyRequest::class => ['company_name', 'company_email', 'tin_number'],
        MethodMedicineRequest::class => ['medicine_name', 'generic_name', 'cost_price', 'units_per_box', 'branch_id'],
        MethodTransactionRequest::class => ['user_id', 'branch_id', 'transaction_type', 'items', 'total_amount']];
    foreach ($matrix as $class => $fields) {
        $rules = (new $class())->rules();
        foreach ($fields as $field) {
            expect($rules)->toHaveKey($field);
            expect(Validator::make([], [$field => $rules[$field]])->errors()->has($field))->toBeTrue();
        }
    }
});
