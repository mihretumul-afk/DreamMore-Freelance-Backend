<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FreelancerProfile;
use App\Models\User;

$freelancers = FreelancerProfile::with('user')->get();

echo "=== FREELANCER APPROVAL STATUS ===" . PHP_EOL;
echo str_repeat('-', 70) . PHP_EOL;

if ($freelancers->isEmpty()) {
    echo "No freelancers found in the database." . PHP_EOL;
}

foreach ($freelancers as $f) {
    $user = $f->user;
    if (!$user) {
        echo "Profile ID {$f->id}: No user found!" . PHP_EOL;
        continue;
    }
    $hasCreds = $user->hasApprovedCredentials();
    $hasPendingCreds = $user->credentials()->where('status', 'pending')->exists();
    $hasPendingVerifs = $user->verifications()->where('status', 'pending')->exists();
    $totalCreds = $user->credentials()->count();
    $totalVerifs = $user->verifications()->count();

    echo "Name: {$user->name}" . PHP_EOL;
    echo "  Email: {$user->email}" . PHP_EOL;
    echo "  Profile Status: {$f->approval_status}" . PHP_EOL;
    echo "  Credentials: {$totalCreds} total, Approved: " . ($hasCreds ? 'YES' : 'NO') . PHP_EOL;
    echo "  Pending Credentials: " . ($hasPendingCreds ? 'YES' : 'NO') . PHP_EOL;
    echo "  Pending Verifications: " . ($hasPendingVerifs ? 'YES' : 'NO') . PHP_EOL;
    echo "  Would pass middleware: " . ($f->approval_status === 'approved' && $hasCreds ? 'YES ✅' : 'NO ❌') . PHP_EOL;
    echo str_repeat('-', 70) . PHP_EOL;
}
