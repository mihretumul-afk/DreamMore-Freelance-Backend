@extends('emails.layouts.payment')

@section('content')
<h2>Withdrawal Failed ❌</h2>

<p>Hi {{ $userName }},</p>

<p>Unfortunately, your withdrawal request could not be processed.</p>

<div class="amount-box danger">
    <div class="label">Withdrawal Amount</div>
    <div class="value">{{ $currency }} {{ number_format($amount, 2) }}</div>
    <div class="sub">The reserved amount has been returned to your available balance</div>
</div>

<table class="detail-table">
    <tr><td>Reference</td><td>{{ $reference }}</td></tr>
    <tr><td>Amount</td><td>{{ $currency }} {{ number_format($amount, 2) }}</td></tr>
    <tr><td>Status</td><td style="color: #dc2626; font-weight: 600;">Failed</td></tr>
    @if(!empty($reason))
    <tr><td>Reason</td><td>{{ $reason }}</td></tr>
    @endif
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>Your funds are safe.</strong><br>
    The {{ $currency }} {{ number_format($amount, 2) }} has been returned to your available balance. Please check your payment method details and try again, or contact support if the issue persists.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">Try Again</a>
    <a href="mailto:support@dreammore.app" class="btn danger" style="margin-left: 8px;">Contact Support</a>
</div>
@endsection
