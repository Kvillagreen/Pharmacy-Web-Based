<?php
// Reset passwords for SuperAdmin and owner user to a known temporary password
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\v1\SuperAdmin;
use App\Models\v1\User;
use Illuminate\Support\Facades\Hash;

$pw = 'TempPass123!';

$sa = SuperAdmin::where('email','admin@admin.com')->first();
if ($sa) {
    $sa->password = Hash::make($pw);
    $sa->save();
    echo "SuperAdmin (admin@admin.com) password reset.\n";
} else {
    echo "SuperAdmin admin@admin.com not found.\n";
}

$owner = User::where('email','owner@gmail.com')->first();
if ($owner) {
    $owner->password = Hash::make($pw);
    $owner->save();
    echo "Owner (owner@gmail.com) password reset.\n";
} else {
    echo "Owner owner@gmail.com not found.\n";
}

echo "Passwords set to: $pw\n";
