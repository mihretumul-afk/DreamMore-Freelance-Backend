@extends('emails.layouts.payment')

@section('content')
<h2>Payment Confirmed ✅</h2>

<p>Hi {{ $userName }},</p>

<p>Your payment has been successfully processed and the funds are now held in escrow.</p>

<div class="amount-box info">
    <div class="label">Amount Paid</div>
    <div class="value">{{ $currency }} {{ number_format($amount, 2) }}</div>
    <div class="sub">Held securely in escrow</div>
</div>

<table class="detail-table">
    <tr><td>Transaction Reference</td><td>{{ $reference }}</td></tr>
    <tr><td>Milestone</td><td>{{ $milestoneTitle }}</td></tr>
    <tr><td>Contract</td><td>{{ $contractTitle }}</td></tr>
    <tr><td>Platform Fee</td><td>{{ $currency }} {{ number_format($fee, 2) }}</td></tr>
    <tr><td>Status</td><td style="color: #16a34a; font-weight: 600;">Funded</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>What happens next?</strong><br>
    Your funds are securely held in escrow. Once the freelancer completes the work and you approve the deliverable, the payment will be released to them.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">View Contract</a>
</div>
@endsection
