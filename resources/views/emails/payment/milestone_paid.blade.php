@extends('emails.layouts.payment')

@section('content')
<h2>Payment Received 💰</h2>

<p>Hi {{ $userName }},</p>

<p>Great news! A payment has been released to your account for completing a milestone.</p>

<div class="amount-box">
    <div class="label">Amount Received</div>
    <div class="value">{{ $currency }} {{ number_format($netAmount, 2) }}</div>
    <div class="sub">After platform fees of {{ $currency }} {{ number_format($fee, 2) }}</div>
</div>

<table class="detail-table">
    <tr><td>Transaction Reference</td><td>{{ $reference }}</td></tr>
    <tr><td>Milestone</td><td>{{ $milestoneTitle }}</td></tr>
    <tr><td>Contract</td><td>{{ $contractTitle }}</td></tr>
    <tr><td>Gross Amount</td><td>{{ $currency }} {{ number_format($amount, 2) }}</td></tr>
    <tr><td>Platform Fee</td><td>-{{ $currency }} {{ number_format($fee, 2) }}</td></tr>
    <tr><td>Net Amount</td><td style="color: #16a34a; font-weight: 600;">{{ $currency }} {{ number_format($netAmount, 2) }}</td></tr>
    <tr><td>Status</td><td style="color: #16a34a; font-weight: 600;">Completed</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>Your balance has been updated.</strong><br>
    The funds are now available in your account. You can request a withdrawal at any time from your Payment Center.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn success">View Payment Center</a>
</div>
@endsection
