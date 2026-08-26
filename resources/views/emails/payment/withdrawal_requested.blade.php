@extends('emails.layouts.payment')

@section('content')
<h2>Withdrawal Requested 📤</h2>

<p>Hi {{ $userName }},</p>

<p>We've received your withdrawal request. It will be reviewed and processed by our team.</p>

<div class="amount-box warning">
    <div class="label">Withdrawal Amount</div>
    <div class="value">{{ $currency }} {{ number_format($amount, 2) }}</div>
    <div class="sub">Processing fee: {{ $currency }} {{ number_format($fee, 2) }} · You'll receive: {{ $currency }} {{ number_format($netAmount, 2) }}</div>
</div>

<table class="detail-table">
    <tr><td>Reference</td><td>{{ $reference }}</td></tr>
    <tr><td>Amount Requested</td><td>{{ $currency }} {{ number_format($amount, 2) }}</td></tr>
    <tr><td>Processing Fee</td><td>{{ $currency }} {{ number_format($fee, 2) }}</td></tr>
    <tr><td>Net Amount</td><td style="font-weight: 600;">{{ $currency }} {{ number_format($netAmount, 2) }}</td></tr>
    <tr><td>Withdrawal Method</td><td>{{ $methodName ?? 'Default payment method' }}</td></tr>
    <tr><td>Status</td><td style="color: #d97706; font-weight: 600;">Requested</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>What happens next?</strong><br>
    Your withdrawal request is being reviewed. You'll receive an email once it's processed. This typically takes 1-3 business days.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">View Withdrawals</a>
</div>
@endsection
