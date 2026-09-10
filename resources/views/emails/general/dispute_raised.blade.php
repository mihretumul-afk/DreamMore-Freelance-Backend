@extends('emails.layouts.notification')

@section('content')
<h2>Dispute Raised ⚠️</h2>

<p>Hi {{ $userName }},</p>

<p>A dispute has been raised on a contract you're involved in. An administrator will review it shortly.</p>

<div class="reason-box">
    <strong>Reason given:</strong><br>
    {{ $reason }}
</div>

<table class="detail-table">
    <tr><td>Contract</td><td>{{ $contractTitle }}</td></tr>
    <tr><td>Milestone</td><td>{{ $milestoneTitle }}</td></tr>
    <tr><td>Your Role</td><td>{{ ucfirst($role) }}</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    <strong>What happens next?</strong><br>
    A Dream More administrator will review the dispute and both parties' evidence. Funds held in escrow remain protected until the dispute is resolved. You may be contacted for additional information.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn danger">View Dispute</a>
</div>
@endsection
