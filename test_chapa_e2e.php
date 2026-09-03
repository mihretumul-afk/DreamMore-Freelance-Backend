<?php
/**
 * Chapa E2E Test Script
 * 
 * Tests the full payment flow:
 * 1. Create test user with wallet
 * 2. Initiate payment via Chapa
 * 3. Show checkout URL for manual completion
 * 4. Verify payment status
 * 5. Show wallet balance and transaction ledger
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Wallet;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\Payment\AddFundsService;
use App\Services\Payment\Providers\ChapaProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "=== CHAPA E2E TEST ===\n\n";

// Step 1: Create test user and wallet
echo "1. CREATING TEST USER AND WALLET\n";
echo str_repeat('-', 50) . "\n";

// Clean up any previous test data
$existingUser = User::where('email', 'chapa-e2e-test@example.com')->first();
if ($existingUser) {
    // Delete related records first
    Transaction::where('user_id', $existingUser->id)->delete();
    Payment::where('payer_id', $existingUser->id)->delete();
    \App\Models\PaymentMethod::where('user_id', $existingUser->id)->delete();
    Wallet::where('user_id', $existingUser->id)->delete();
    $existingUser->delete();
}

$user = User::create([
    'name' => 'Chapa E2E Test User',
    'email' => 'chapa-e2e-test@example.com',
    'password' => bcrypt('password'),
    'role' => 'employer',
    'email_verified_at' => now(),
]);

$wallet = Wallet::create([
    'user_id' => $user->id,
    'available_balance' => 0,
    'reserved_balance' => 0,
    'currency' => 'ETB',
]);

// Create a test payment method
$paymentMethod = \App\Models\PaymentMethod::create([
    'user_id' => $user->id,
    'type' => \App\Models\PaymentMethod::TYPE_MOBILE_MONEY,
    'provider' => 'chapa',
    'mobile_provider' => 'Telebirr',
    'masked_phone' => '+251****1234',
    'label' => 'Chapa Test - Telebirr',
    'display_label' => 'Chapa Test - Telebirr',
    'nickname' => 'Chapa Test',
    'is_default' => true,
    'is_verified' => true,
]);

echo "User ID: {$user->id}\n";
echo "Wallet ID: {$wallet->id}\n";
echo "Payment Method ID: {$paymentMethod->id}\n";
echo "Initial Balance: {$wallet->available_balance} ETB\n\n";

// Step 2: Show Chapa configuration
echo "2. CHAPA CONFIGURATION\n";
echo str_repeat('-', 50) . "\n";
echo "Provider: " . config('payment.default') . "\n";
echo "Callback URL: " . config('payment.chapa.callback_url') . "\n";
echo "Return URL: " . config('payment.chapa.return_url') . "\n";
echo "Base URL: " . config('payment.chapa.base_url') . "\n\n";

// Step 3: Initiate payment
echo "3. INITIATING PAYMENT\n";
echo str_repeat('-', 50) . "\n";

$paymentAmount = 50.00;
$reference = Payment::generateReference();

echo "Amount: {$paymentAmount} ETB\n";
echo "Reference: {$reference}\n\n";

// Use AddFundsService to initiate the payment
$service = app(AddFundsService::class);
$result = $service->initiate(
    $user->id,
    $paymentAmount,
    $paymentMethod->id
);

$payment = $result['payment'];
$checkoutUrl = $result['checkout_url'] ?? null;

echo "Payment ID: {$payment->id}\n";
echo "Payment Status: {$payment->status}\n";
echo "Provider Reference: {$payment->provider_reference}\n";

if ($checkoutUrl) {
    echo "\n*** CHECKOUT URL ***\n";
    echo "{$checkoutUrl}\n";
    echo "*** OPEN THIS URL IN A BROWSER TO COMPLETE PAYMENT ***\n\n";
    
    echo "After completing payment, press Enter to continue...\n";
    // In a real test, we'd wait for user input
    // For now, we'll check the status after a delay
} else {
    echo "No checkout URL returned (sandbox mode?)\n\n";
}

// Step 4: Check payment status
echo "4. PAYMENT STATUS CHECK\n";
echo str_repeat('-', 50) . "\n";

$payment->refresh();
echo "Current Status: {$payment->status}\n";
echo "Provider Reference: {$payment->provider_reference}\n\n";

// Step 5: Verify with Chapa API
echo "5. VERIFYING WITH CHAPA API\n";
echo str_repeat('-', 50) . "\n";

if ($payment->provider_reference) {
    $provider = new ChapaProvider();
    $verifyResult = $provider->verify($payment->provider_reference);
    
    echo "Chapa Status: {$verifyResult['status']}\n";
    echo "Amount: " . ($verifyResult['amount'] ?? 'N/A') . "\n";
    echo "Error: " . ($verifyResult['error'] ?? 'None') . "\n\n";
    
    if ($verifyResult['status'] === 'completed') {
        echo "Payment confirmed by Chapa!\n\n";
    } else {
        echo "Payment still pending - complete checkout to proceed\n\n";
    }
} else {
    echo "No provider reference to verify\n\n";
}

// Step 6: Show current wallet state
echo "6. CURRENT WALLET STATE\n";
echo str_repeat('-', 50) . "\n";

$wallet->refresh();
echo "User ID: {$user->id}\n";
echo "Available Balance: {$wallet->available_balance} ETB\n";
echo "Reserved Balance: {$wallet->reserved_balance} ETB\n\n";

// Step 7: Show transaction ledger
echo "7. TRANSACTION LEDGER\n";
echo str_repeat('-', 50) . "\n";

$transactions = Transaction::where('user_id', $user->id)
    ->orderBy('created_at', 'desc')
    ->get();

if ($transactions->isEmpty()) {
    echo "No transactions yet\n\n";
} else {
    foreach ($transactions as $tx) {
        echo "ID: {$tx->id} | ";
        echo "Type: {$tx->type} | ";
        echo "Direction: {$tx->direction} | ";
        echo "Amount: {$tx->amount} | ";
        echo "Status: {$tx->status} | ";
        echo "Created: {$tx->created_at}\n";
    }
    echo "\n";
}

// Step 8: Summary
echo "=== TEST SUMMARY ===\n";
echo str_repeat('-', 50) . "\n";
echo "Test User ID: {$user->id}\n";
echo "Test Wallet ID: {$wallet->id}\n";
echo "Payment Reference: {$reference}\n";
echo "Payment Amount: {$paymentAmount} ETB\n";
echo "Current Balance: {$wallet->available_balance} ETB\n";
echo "Transactions Count: {$transactions->count()}\n\n";

echo "To complete this test:\n";
echo "1. Open the checkout URL above in a browser\n";
echo "2. Complete the payment using Chapa's test mode\n";
echo "3. Run this script again to see the updated status\n";
echo "4. Or check the webhook logs at: /api/v1/webhooks/deposit/chapa\n\n";

echo "=== END OF TEST ===\n";
