@extends('emails.layouts.notification')

@section('content')
<h2>New Message 💬</h2>

<p>Hi {{ $userName }},</p>

<p><strong>{{ $senderName }}</strong> sent you a message while you were away:</p>

<div class="note">
    "{{ $messagePreview }}"
</div>

<div style="text-align: center; margin-top: 24px;">
    <a href="{{ $dashboardUrl }}" class="btn">Reply Now</a>
</div>

<p style="font-size: 12px; color: #9ca3af; margin-top: 20px;">
    You received this email because you were offline when the message arrived. If you're active on Dream More, you'll see new messages instantly in-app.
</p>
@endsection
