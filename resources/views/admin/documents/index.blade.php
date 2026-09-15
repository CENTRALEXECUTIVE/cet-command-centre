@extends('layouts.app')
@section('title', 'Driver Documents')

@section('content')
    <h1 class="page-title">Driver Documents</h1>
    <p class="page-sub">Verify uploads, chase what's missing or expiring, and upload documents drivers send in.</p>

    <a href="{{ route('drivers.create') }}" class="btn btn-primary" style="margin-bottom:14px">+ Add driver</a>

    @if(session('status'))
        <div class="card" style="border-left:4px solid #1f7a44;background:rgba(31,122,68,.08);margin-bottom:16px">{{ session('status') }}</div>
    @endif

    <div class="card">
        <table class="table">
            <thead>
                <tr><th>Driver</th><th>Vehicle</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($rows as $d)
                @php $s = $d['summary']; @endphp
                <tr>
                    <td>{{ $d['name'] }}@if(! $d['directory'])<span class="muted" style="font-size:11px"> · director</span>@endif</td>
                    <td class="muted">{{ $d['reg'] ?: '—' }}</td>
                    <td style="display:flex;gap:6px;flex-wrap:wrap">
                        @if(! $d['user'])
                            <span class="badge" style="background:#888;color:#fff">No login — sync directory</span>
                        @else
                            @if($s['expired']) <span class="badge" style="background:#b32020;color:#fff">{{ $s['expired'] }} expired</span> @endif
                            @if($s['pending']) <span class="badge" style="background:#2a6bb0;color:#fff">{{ $s['pending'] }} to review</span> @endif
                            @if($s['missing']) <span class="badge" style="background:#888;color:#fff">{{ $s['missing'] }} missing</span> @endif
                            @if($s['expiring']) <span class="badge" style="background:#FBBA2A;color:#111">{{ $s['expiring'] }} expiring</span> @endif
                            @if($s['rejected']) <span class="badge" style="background:#b32020;color:#fff">{{ $s['rejected'] }} rejected</span> @endif
                            @if(!$s['expired'] && !$s['pending'] && !$s['missing'] && !$s['expiring'] && !$s['rejected'])
                                <span class="badge" style="background:#1f7a44;color:#fff">All valid</span>
                            @endif
                        @endif
                    </td>
                    <td style="text-align:right">
                        @if($d['user'])
                            <a href="{{ route('driver-documents.show', $d['user']) }}">Open →</a>
                        @else
                            <a href="{{ route('cover-drivers.index') }}" class="muted">Directory →</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No drivers in the directory yet. <a href="{{ route('cover-drivers.index') }}">Add drivers →</a></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
