<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\v1\User;
use Illuminate\Support\Facades\Hash;

$admin = User::firstOrCreate(
    ['email' => 'admin@example.com'],
    [
        'first_name' => 'Admin',
        'last_name' => 'User',
        'password' => Hash::make('password123'),
        'role' => 'admin',
        'status' => 'approved',
        'branch_id' => 1 // Assuming branch 1 exists, change if needed
    ]
);

if ($admin->wasRecentlyCreated) {
    echo "Admin user created successfully!\n";
    echo "Email: admin@example.com\n";
    echo "Password: password123\n";
} else {
    echo "Admin user already exists!\n";
    echo "Email: " . $admin->email . "\n";
    
    // Force update password for the existing user just in case
    $admin->password = Hash::make('password123');
    $admin->role = 'admin';
    $admin->save();
    echo "Password reset to: password123\n";
}
