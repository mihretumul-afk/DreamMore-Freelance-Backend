@extends('emails.layouts.payment')

@section('content')
<h2>Payment Failed ❌</h2>

<p>Hi {{ $userName }},</p>

<p>Your payment could not be completed. Your account has <strong>not</strong> been charged.</p>

<div class="amount-box danger">
    <div class="label">Payment Amount</div>
    <div class="value">{{ $currency }} {{ number_format($amount, 2) }}</div>
    <div class="sub">No charge was made</div>
</div>

<table class="detail-table">
    <tr><td>Transaction Reference</td><td>{{ $reference }}</td></tr>
    <tr><td>Milestone</td><td>{{ $milestoneTitle }}</td></tr>
    <tr><td>Contract</td><td>{{ $contractTitle }}</td></tr>
    <tr><td>Amount</td><td>{{ $currency }} {{ number_format($amount, 2) }}</td></tr>
    <tr><td>Status</td><td style="color: #dc2626; font-weight: 600;">Failed</td></tr>
    @if(!empty($reason))
    <tr><td>Reason</td><td>{{ $reason }}</td></tr>
    @endif
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>What to do:</strong><br>
    Please check your payment method details and try again. If the problem persists, try a different payment method or contact our support team.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $retryUrl }}" class="btn">Try Again</a>
    <a href="mailto:support@dreammore.app" class="btn danger" style="margin-left: 8px;">Contact Support</a>
</div>
@endsection
