@extends('emails.layouts.notification')

@section('content')
<h2>Milestone Funded ✅</h2>

<p>Hi {{ $userName }},</p>

<p>The employer has funded the escrow for a milestone. You can start working now!</p>

<div class="info-box">
    <div class="label">Funds Secured In Escrow</div>
    <div class="title">ETB {{ number_format($amount, 2) }}</div>
    <div class="desc">The money is already secured — you'll be paid when the employer approves your work.</div>
</div>

<table class="detail-table">
    <tr><td>Milestone</td><td>{{ $milestoneTitle }}</td></tr>
    <tr><td>Contract</td><td>{{ $contractTitle }}</td></tr>
    <tr><td>Escrow Amount</td><td>ETB {{ number_format($amount, 2) }}</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>What happens next?</strong><br>
    1. Complete the work and submit the milestone.<br>
    2. The employer reviews and approves it.<br>
    3. The funds are released to your wallet automatically.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">Open Contract</a>
</div>
@endsection
