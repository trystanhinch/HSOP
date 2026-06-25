@extends('emails.layout')

@section('content')
    <h2 style="color: #1e293b; margin-top: 0;">{{ $heading ?? 'Notification from HSOP' }}</h2>
    <p style="color: #334155; line-height: 1.6;">{!! nl2br(e($body ?? '')) !!}</p>
    @if(!empty($actionUrl) && !empty($actionLabel))
        <div style="text-align: center; margin: 28px 0;">
            <a href="{{ $actionUrl }}" style="background: #3B82F6; color: white; padding: 12px 28px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600;">
                {{ $actionLabel }}
            </a>
        </div>
    @endif
@endsection
