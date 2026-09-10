@extends('emails.layouts.notification')

@section('content')
<h2>Milestone Submitted 📨</h2>

<p>Hi {{ $userName }},</p>

<p>The freelancer has submitted work for your review.</p>

<div class="info-box">
    <div class="label">Awaiting Your Review</div>
    <div class="title">{{ $milestoneTitle }}</div>
    <div class="desc">Review the deliverables and approve the milestone to release the payment, or request revisions if needed.</div>
</div>

<table class="detail-table">
    <tr><td>Milestone</td><td>{{ $milestoneTitle }}</td></tr>
    <tr><td>Contract</td><td>{{ $contractTitle }}</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div class="note">
    Funds remain securely held in escrow until you approve the milestone.
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">Review Work</a>
</div>
@endsection
