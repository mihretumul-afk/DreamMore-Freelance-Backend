@extends('emails.layouts.payment')

@section('content')
<h2>Withdrawal Completed ✅</h2>

<p>Hi {{ $userName }},</p>

<p>Your withdrawal has been processed and the funds have been sent to your account.</p>

<div class="amount-box">
    <div class="label">Amount Disbursed</div>
    <div class="value">{{ $currency }} {{ number_format($netAmount, 2) }}</div>
    <div class="sub">Sent to {{ $methodName ?? 'your payment method' }}</div>
</div>

<table class="detail-table">
    <tr><td>Reference</td><td>{{ $reference }}</td></tr>
    <tr><td>Gross Amount</td><td>{{ $currency }} {{ number_format($amount, 2) }}</td></tr>
    <tr><td>Processing Fee</td><td>-{{ $currency }} {{ number_format($fee, 2) }}</td></tr>
    <tr><td>Net Amount Disbursed</td><td style="color: #16a34a; font-weight: 600;">{{ $currency }} {{ number_format($netAmount, 2) }}</td></tr>
    <tr><td>Withdrawal Method</td><td>{{ $methodName ?? 'Payment method' }}</td></tr>
    <tr><td>Status</td><td style="color: #16a34a; font-weight: 600;">Completed</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    Please allow 1-3 business days for the funds to appear in your account, depending on your bank or mobile money provider.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn success">View Payment Center</a>
</div>
@endsection
