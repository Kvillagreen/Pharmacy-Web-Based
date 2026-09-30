<?php
// Bootstrap a minimal Laravel app context and list seeded admin/owner users
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\v1\SuperAdmin;
use App\Models\v1\User;

echo "--- SuperAdmin ---\n";
$superAdmins = SuperAdmin::all();
foreach ($superAdmins as $u) {
    $id = property_exists($u, 'super_admin_id') ? $u->super_admin_id : ($u->id ?? 'n/a');
    echo "email: {$u->email} id: {$id} password: {$u->password}\n";
}

echo "--- Users (owner & admin emails) ---\n";
$users = User::whereIn('email', ['owner@gmail.com', 'admin@admin.com'])->get();
foreach ($users as $u) {
    $id = property_exists($u, 'user_id') ? $u->user_id : ($u->id ?? 'n/a');
    $role = $u->role ?? 'NULL';
    echo "email: {$u->email} id: {$id} role: {$role} password: {$u->password}\n";
}
