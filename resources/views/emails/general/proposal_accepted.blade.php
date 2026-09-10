@extends('emails.layouts.notification')

@section('content')
<h2>Proposal Accepted 🎉</h2>

<p>Hi {{ $userName }},</p>

<p>Great news! Your proposal for <strong>{{ $jobTitle }}</strong> has been accepted.</p>

<div class="info-box">
    <div class="label">Next Step</div>
    <div class="title">A contract offer has been created</div>
    <div class="desc">Review the contract terms and accept to start working. The employer will fund the first milestone once the contract is active.</div>
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">Review Contract</a>
</div>
@endsection
