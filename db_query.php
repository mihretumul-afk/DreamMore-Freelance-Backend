<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';

switch ($cmd) {
    case 'activate_users':
        App\Models\User::whereIn('email', [
            'admin@dreammore.com',
            'selamtech@dreammore.com',
            'abebe.tesfaye@dreammore.com',
            'meron.alemu@dreammore.com',
        ])->update([
            'status' => 'active',
            'password' => Illuminate\Support\Facades\Hash::make('DreamMore@2026')
        ]);

        $abebe = App\Models\User::where('email', 'abebe.tesfaye@dreammore.com')->first();
        if ($abebe && !$abebe->hasApprovedCredentials()) {
            App\Models\Verification::create([
                'user_id' => $abebe->id,
                'document_type' => 'national_id',
                'document_number' => 'ETH-TEST-999',
                'document_path' => 'verifications/test.pdf',
                'status' => 'approved',
                'verified_at' => now(),
            ]);
        }

        if ($abebe) {
            App\Models\PortfolioItem::firstOrCreate(
                ['user_id' => $abebe->id, 'title' => 'E-Commerce Platform Redesign'],
                [
                    'description' => 'Redesigned full stack marketplace in React and Laravel.',
                    'project_url' => 'https://example.com/portfolio-item-1',
                    'display_order' => 1,
                ]
            );
        }

        echo json_encode(['status' => 'active_and_passwords_reset', 'abebe_id' => $abebe ? $abebe->id : null]);
        break;
    case 'get_job':
        $job = App\Models\Job::with(['category', 'skills', 'employer'])->find($arg);
        echo json_encode($job);
        break;
    case 'get_proposal':
        $p = App\Models\Proposal::with(['job', 'freelancer', 'portfolioItems'])->find($arg);
        echo json_encode($p);
        break;
    case 'get_contract':
        $c = App\Models\Contract::with(['milestones.submissions.files', 'employer', 'freelancer', 'reviews'])->find($arg);
        echo json_encode($c);
        break;
    case 'get_freelancer_profile':
        $u = App\Models\User::with(['freelancerProfile'])->find($arg);
        $rating = App\Models\Review::where('reviewee_id', $arg)->avg('rating');
        $totalContracts = App\Models\Contract::where('freelancer_id', $arg)->count();
        $completedContracts = App\Models\Contract::where('freelancer_id', $arg)->where('status', 'completed')->count();
        $endedContracts = App\Models\Contract::where('freelancer_id', $arg)->whereIn('status', ['completed', 'cancelled', 'disputed'])->count();
        $successRate = $endedContracts > 0 ? round(($completedContracts / $endedContracts) * 100) : null;
        echo json_encode([
            'user' => $u,
            'avg_rating' => $rating,
            'total_contracts' => $totalContracts,
            'completed_contracts' => $completedContracts,
            'success_rate' => $successRate,
        ]);
        break;
    case 'get_notifications':
        $notes = App\Models\Notification::where('user_id', $arg)->latest()->take(5)->get();
        echo json_encode($notes);
        break;
    case 'get_messages':
        $msgs = App\Models\Message::where('sender_id', $arg)->orWhere('receiver_id', $arg)->latest()->take(5)->get();
        echo json_encode($msgs);
        break;
    default:
        echo json_encode(['error' => 'Unknown command']);
}
