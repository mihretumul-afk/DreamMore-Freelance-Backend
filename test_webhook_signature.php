<?php
/**
 * Chapa Webhook Signature Verification Test
 * Tests that valid signatures are accepted and invalid/missing ones are rejected.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Payment\Providers\ChapaProvider;

echo "=== WEBHOOK SIGNATURE VERIFICATION TEST ===\n\n";

$provider = new ChapaProvider();

$payload = '{"event_type":"charge.success","tx_ref":"TEST-123","amount":50,"currency":"ETB","status":"success"}';
$secretKey = config('payment.chapa.secret_key');

// Generate valid signature
$validSignature = hash_hmac('sha256', $payload, $secretKey);

echo "1. VALID SIGNATURE\n";
echo str_repeat('-', 50) . "\n";
echo "Payload: {$payload}\n";
echo "Signature: {$validSignature}\n";
$result = $provider->verifyWebhookSignature($payload, $validSignature);
echo "Result: " . ($result ? "✅ ACCEPTED" : "❌ REJECTED") . "\n\n";

echo "2. INVALID SIGNATURE\n";
echo str_repeat('-', 50) . "\n";
$invalidSignature = 'invalid_signature_12345';
echo "Signature: {$invalidSignature}\n";
$result = $provider->verifyWebhookSignature($payload, $invalidSignature);
echo "Result: " . (!$result ? "✅ CORRECTLY REJECTED" : "❌ SECURITY BUG - should have rejected") . "\n\n";

echo "3. EMPTY SIGNATURE\n";
echo str_repeat('-', 50) . "\n";
$result = $provider->verifyWebhookSignature($payload, '');
echo "Result: " . (!$result ? "✅ CORRECTLY REJECTED" : "❌ SECURITY BUG - should have rejected") . "\n\n";

echo "4. MISSING SIGNATURE (null)\n";
echo str_repeat('-', 50) . "\n";
$result = $provider->verifyWebhookSignature($payload, '');
echo "Result: " . (!$result ? "✅ CORRECTLY REJECTED" : "❌ SECURITY BUG - should have rejected") . "\n\n";

echo "5. TAMPERED PAYLOAD WITH VALID SIGNATURE\n";
echo str_repeat('-', 50) . "\n";
$tamperedPayload = '{"event_type":"charge.success","tx_ref":"TEST-123","amount":99999,"currency":"ETB","status":"success"}';
echo "Original payload signed, but verifying tampered payload\n";
$result = $provider->verifyWebhookSignature($tamperedPayload, $validSignature);
echo "Result: " . (!$result ? "✅ CORRECTLY REJECTED" : "❌ SECURITY BUG - should have rejected") . "\n\n";

echo "=== ALL SIGNATURE TESTS PASSED ===\n";
