@extends('emails.layouts.payment')

@section('content')
<h2>Refund Processed 💸</h2>

<p>Hi {{ $userName }},</p>

<p>Your refund request has been approved and processed.</p>

<div class="amount-box">
    <div class="label">Refund Amount</div>
    <div class="value">{{ $currency }} {{ number_format($amount, 2) }}</div>
    <div class="sub">Refunded to your original payment method</div>
</div>

<table class="detail-table">
    <tr><td>Original Payment</td><td>{{ $originalReference }}</td></tr>
    <tr><td>Refund Amount</td><td style="color: #16a34a; font-weight: 600;">{{ $currency }} {{ number_format($amount, 2) }}</td></tr>
    @if(!empty($reason))
    <tr><td>Reason</td><td>{{ $reason }}</td></tr>
    @endif
    <tr><td>Status</td><td style="color: #16a34a; font-weight: 600;">Completed</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    Please allow 3-5 business days for the refund to appear on your statement, depending on your bank or payment provider.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn success">View Payment Center</a>
</div>
@endsection
