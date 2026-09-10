<?php

/**
 * Email pipeline smoke test — REAL end-to-end verification.
 *
 * Boots the full Laravel app, triggers notification events through the
 * REAL NotificationService code path, then shows:
 *   1. The resolved mail config (which driver actually sends)
 *   2. That in-app notifications are still created (additive check)
 *   3. That email jobs were pushed to the database queue
 *
 * Usage:
 *   php scripts/email-smoke-test.php
 *
 * Then send the queued emails for real:
 *   php artisan queue:work --stop-when-empty
 *
 * Test emails go to Gmail plus-addresses of the configured sender
 * (mihretumul+freelancer@gmail.com / mihretumul+employer@gmail.com),
 * which land in the sender's own Gmail inbox.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

echo "\n==============================================\n";
echo " 1. RESOLVED MAIL CONFIG (what will really send)\n";
echo "==============================================\n";
printf("  MAIL_MAILER     : %s\n", config('mail.default'));
printf("  HOST:PORT       : %s:%s\n", config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'));
printf("  ENCRYPTION      : %s\n", config('mail.mailers.smtp.scheme') ?: 'tls (env MAIL_ENCRYPTION)');
printf("  USERNAME        : %s\n", config('mail.mailers.smtp.username'));
printf("  FROM            : %s <%s>\n", config('mail.from.name'), config('mail.from.address'));
printf("  QUEUE_CONNECTION: %s\n", config('queue.default'));
printf("  APP_FRONTEND_URL: %s\n", config('app.frontend_url'));

if (config('mail.default') === 'log') {
    echo "\n  *** WARNING: 'log' driver — emails are NOT really sent,\n      only written to the log file. ***\n";
}

echo "\n==============================================\n";
echo " 2. TEST USERS (Gmail plus-addresses -> your inbox)\n";
echo "==============================================\n";

$base = preg_replace('/@.*/', '', (string) config('mail.from.address'));
$domain = 'gmail.com'; // plus-addressing only works for gmail

$freelancer = User::firstOrCreate(
    ['email' => "{$base}+freelancer@{$domain}"],
    ['name' => 'Smoke Test Freelancer', 'password' => bcrypt('password'), 'role' => 'freelancer', 'status' => 'active']
);
$employer = User::firstOrCreate(
    ['email' => "{$base}+employer@{$domain}"],
    ['name' => 'Smoke Test Employer', 'password' => bcrypt('password'), 'role' => 'employer', 'status' => 'active']
);
echo "  freelancer: #{$freelancer->id} {$freelancer->email}\n";
echo "  employer  : #{$employer->id} {$employer->email}\n";

echo "\n==============================================\n";
echo " 3. TRIGGER REAL EVENTS via NotificationService\n";
echo "==============================================\n";

$inAppBefore = Notification::whereIn('user_id', [$freelancer->id, $employer->id])->count();

NotificationService::newProposal($employer->id, $freelancer->name, 'Build a Laravel Marketplace', 999);
echo "  -> newProposal() called (employer)\n";

NotificationService::milestoneFunded($freelancer->id, 'Homepage redesign', 'Website for ACME', 999, 1500.00);
echo "  -> milestoneFunded() called (freelancer)\n";

NotificationService::milestonePaid($freelancer->id, 'Homepage redesign', 999, 1425.00, 75.00, 'PAY-SMOKE-0001', 'Website for ACME');
echo "  -> milestonePaid() called (freelancer)\n";

NotificationService::withdrawalCompleted($freelancer->id, 'WD-SMOKE-0001', 1000.00, 15.00, 985.00);
echo "  -> withdrawalCompleted() called (freelancer)\n";

NotificationService::disputeEmail(
    $employer->id, 'Website for ACME', 999, 'Homepage redesign',
    'Smoke test: deliverables do not match the brief.', 'employer'
);
echo "  -> disputeEmail() called (employer)\n";

NotificationService::messageReceived($employer->id, $freelancer->name, 'Hi! Sending over the first draft now.');
echo "  -> messageReceived() called (employer, offline => email expected)\n";

echo "\n==============================================\n";
echo " 4. IN-APP NOTIFICATIONS STILL CREATED (additive check)\n";
echo "==============================================\n";
$inAppAfter = Notification::whereIn('user_id', [$freelancer->id, $employer->id])->count();
printf("  notifications before: %d\n", $inAppBefore);
printf("  notifications after : %d  (+%d created)\n", $inAppAfter, $inAppAfter - $inAppBefore);

foreach (Notification::whereIn('user_id', [$freelancer->id, $employer->id])->latest('id')->take(6)->get() as $n) {
    printf("   [%s] user #%d | %s | %s\n", $n->type, $n->user_id, $n->title, str($n->message)->limit(50));
}

echo "\n==============================================\n";
echo " 5. QUEUED EMAIL JOBS (database queue evidence)\n";
echo "==============================================\n";
$jobs = DB::table('jobs')->orderByDesc('id')->get();
printf("  jobs in queue: %d\n\n", $jobs->count());

foreach ($jobs as $job) {
    $payload = json_decode($job->payload, true);
    $mailable = $payload['displayName'] ?? $payload['job'] ?? 'unknown';
    $delayed = $job->available_at > time() ? sprintf(' (delayed %ds — anti-spam digest)', $job->available_at - time()) : '';
    printf("   #%d queue=%s | %s%s\n", $job->id, $job->queue, $mailable, $delayed);
}

echo "\n==============================================\n";
echo " DONE. Send the queued emails for real:\n";
echo "   php artisan queue:work --stop-when-empty\n";
echo " Then check the Gmail inbox of ".config('mail.from.address')."\n";
echo " (plus-addressed messages land in the same inbox).\n";
echo " Cleanup test users:\n";
echo "   php artisan tinker --execute=\"App\\Models\\User::whereIn('email',['{$base}+freelancer@{$domain}','{$base}+employer@{$domain}'])->each(fn(\\\$u)=>\\\$u->delete());\"\n";
echo "==============================================\n\n";
