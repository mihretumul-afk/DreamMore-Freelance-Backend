@extends('emails.layouts.notification')

@section('content')
<h2>New Proposal Received 📩</h2>

<p>Hi {{ $userName }},</p>

<p><strong>{{ $freelancerName }}</strong> has submitted a proposal on your job.</p>

<div class="info-box">
    <div class="label">Job</div>
    <div class="title">{{ $jobTitle }}</div>
    <div class="desc">Review the proposal, compare offers, and shortlist your favorite freelancer.</div>
</div>

<table class="detail-table">
    <tr><td>Freelancer</td><td>{{ $freelancerName }}</td></tr>
    <tr><td>Job</td><td>{{ $jobTitle }}</td></tr>
    <tr><td>Date</td><td>{{ $date }}</td></tr>
</table>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">Review Proposals</a>
</div>
@endsection
