<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Payment Return Routes
|--------------------------------------------------------------------------
| These handle redirects from payment providers (e.g., Chapa) after
| checkout. They show a simple confirmation page and let the frontend
| polling mechanism handle wallet updates.
*/
Route::get('/payment/success', function () {
    return response()->json([
        'message' => 'Payment completed successfully.',
        'instruction' => 'Your wallet balance will be updated shortly. You may close this page.',
    ]);
})->name('payment.success');

Route::get('/payment/failed', function () {
    return response()->json([
        'message' => 'Payment was not completed.',
        'instruction' => 'No charges were made. You may close this page.',
    ]);
})->name('payment.failed');

// Catch-all for SPA routes — return the frontend's index.html
// so React Router can handle the route client-side.
Route::fallback(function () {
    $indexPath = public_path('index.html');
    if (file_exists($indexPath)) {
        return response()->file($indexPath);
    }
    return response()->json(['error' => 'Page not found'], 404);
});
