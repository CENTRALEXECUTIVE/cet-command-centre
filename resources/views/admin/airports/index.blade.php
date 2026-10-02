@extends('layouts.app')
@section('title', 'Airports')

@section('content')
<style>
    .air-table{ width:100%; border-collapse:collapse }
    .air-table th{ text-align:left; font-size:12px; color:var(--muted); font-weight:700; padding:8px 10px; text-transform:uppercase; letter-spacing:.04em }
    .air-table td{ padding:8px 10px; border-top:1px solid var(--line); vertical-align:middle }
    .air-table input[type=text]{ padding:8px 10px; border:1px solid var(--line); border-radius:9px; background:var(--panel,#fff); font-size:14px; width:100% }
    .air-table .code{ max-width:90px; text-transform:uppercase; font-weight:700 }
    .air-check{ display:flex; align-items:center; gap:6px; font-size:13px; white-space:nowrap }
    .air-actions{ display:flex; gap:6px; flex-wrap:wrap }
    @media(max-width:720px){
        .air-table thead{ display:none }
        .air-table, .air-table tbody, .air-table tr, .air-table td{ display:block; width:100% }
        .air-table tr{ border:1px solid var(--line); border-radius:12px; margin-bottom:10px; padding:8px 10px }
        .air-table td{ border:0; padding:5px 0 }
    }
</style>

<h1 class="page-title">✈️ Airports</h1>
<p class="page-sub">The airports the booking form offers and the driver rotation runs on. Add one, rename it, turn it on or off, or set which is the <strong>Free Roam</strong> pool (non-airport work).</p>

@if(session('error'))<div class="alert alert-error">{{ session('error') }}</div>@endif

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">Add an airport</h2>
    <form method="POST" action="{{ route('airports.store') }}">
        @csrf
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
            <div class="field" style="margin:0"><label>Code</label><input type="text" name="code" class="code" placeholder="MAN" maxlength="16" required style="width:110px;text-transform:uppercase"></div>
            <div class="field" style="margin:0; flex:1; min-width:180px"><label>Name</label><input type="text" name="name" placeholder="Manchester Airport" required style="width:100%"></div>
            <label class="air-check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
            <label class="air-check"><input type="checkbox" name="is_general_pool" value="1"> Free Roam pool</label>
            <button class="btn btn-primary" style="padding:10px 18px">Add</button>
        </div>
    </form>
</div>

<div class="card">
    <table class="air-table">
        <thead><tr><th>Code</th><th>Name</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($airports as $a)
            <tr>
                <td data-label="Code"><input form="air-{{ $a->id }}" type="text" name="code" value="{{ $a->code }}" class="code" maxlength="16"></td>
                <td data-label="Name"><input form="air-{{ $a->id }}" type="text" name="name" value="{{ $a->name }}"></td>
                <td data-label="Status">
                    <div style="display:flex;gap:14px;flex-wrap:wrap">
                        <label class="air-check"><input form="air-{{ $a->id }}" type="checkbox" name="is_active" value="1" @checked($a->is_active)> Active</label>
                        <label class="air-check"><input form="air-{{ $a->id }}" type="checkbox" name="is_general_pool" value="1" @checked($a->is_general_pool)> Free Roam</label>
                    </div>
                </td>
                <td data-label="">
                    {{-- The update form lives here but its fields sit in other cells,
                         linked by the form="air-{id}" attribute (valid HTML; a form
                         may not span table cells). --}}
                    <form id="air-{{ $a->id }}" method="POST" action="{{ route('airports.update', $a) }}">@csrf @method('PUT')</form>
                    <div class="air-actions">
                        <button form="air-{{ $a->id }}" class="btn btn-primary" style="padding:7px 14px;font-size:13px">Save</button>
                        <form method="POST" action="{{ route('airports.destroy', $a) }}" onsubmit="return confirm('Delete {{ $a->name }}? (Only possible if no bookings use it.)')" style="margin:0">
                            @csrf @method('DELETE')
                            <button class="btn btn-ghost" style="padding:7px 12px;font-size:13px;color:#b32020">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No airports yet — add one above.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
