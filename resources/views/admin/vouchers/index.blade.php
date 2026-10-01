@extends('layouts.app')
@section('title', 'Discount codes')

@section('content')
    <div class="list-head" style="align-items:center">
        <div class="form-hero" style="flex:1;margin-bottom:0">
            <div class="form-hero-glow"></div>
            <div class="fh-eyebrow">Fleet &amp; admin · sales</div>
            <div class="fh-title">Discount codes</div>
            <div class="fh-sub">Create codes customers can enter at checkout — a % or £ off, with an optional usage cap and date window.</div>
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="card" style="border-left:4px solid #b32020;background:rgba(179,32,32,.08)"><ul style="margin:0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    {{-- New code --}}
    <div class="card">
        <h2 style="margin:0 0 10px">New code</h2>
        <form method="POST" action="{{ route('vouchers.store') }}">
            @csrf
            <div class="grid grid-2" style="gap:12px">
                <div class="field"><label>Code</label><input name="code" value="{{ old('code') }}" placeholder="e.g. WELCOME10" style="text-transform:uppercase" required></div>
                <div class="field"><label>Type</label>
                    <select name="type">
                        <option value="percent" @selected(old('type')==='percent')>% off</option>
                        <option value="fixed" @selected(old('type')==='fixed')>£ off</option>
                    </select>
                </div>
                <div class="field"><label>Value</label><input name="value" type="number" step="0.01" min="0" value="{{ old('value') }}" placeholder="10" required></div>
                <div class="field"><label>Max uses <span class="muted" style="font-weight:400;font-size:12px">— blank = unlimited</span></label><input name="max_uses" type="number" min="1" value="{{ old('max_uses') }}" placeholder="unlimited"></div>
                <div class="field"><label>Valid from <span class="muted" style="font-weight:400;font-size:12px">— optional</span></label><input name="valid_from" type="date" value="{{ old('valid_from') }}"></div>
                <div class="field"><label>Valid to <span class="muted" style="font-weight:400;font-size:12px">— optional</span></label><input name="valid_to" type="date" value="{{ old('valid_to') }}"></div>
            </div>
            <div class="field"><label>Comment <span class="muted" style="font-weight:400;font-size:12px">— optional, for the office</span></label><input name="comment" value="{{ old('comment') }}" placeholder="e.g. Autumn promo"></div>
            <button class="btn btn-primary" style="padding:9px 18px">Create code</button>
        </form>
    </div>

    {{-- Existing codes --}}
    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>Code</th><th>Discount</th><th>Used</th><th>Window</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse($vouchers as $v)
                        <tr style="{{ $v->is_active ? '' : 'opacity:.55' }}">
                            <td data-label="Code" class="mono"><strong>{{ $v->code }}</strong>@if($v->comment)<div class="muted" style="font-size:12px">{{ $v->comment }}</div>@endif</td>
                            <td data-label="Discount">{{ $v->label() }}</td>
                            <td data-label="Used">{{ $v->used_count }}{{ $v->max_uses ? ' / '.$v->max_uses : '' }}</td>
                            <td data-label="Window" style="font-size:13px">
                                {{ $v->valid_from?->format('d M Y') ?: '—' }} → {{ $v->valid_to?->format('d M Y') ?: '—' }}
                            </td>
                            <td data-label="Status">
                                @if($v->isRedeemable())<span class="badge" style="background:#1f8b4c;color:#fff">Live</span>
                                @elseif(!$v->is_active)<span class="badge">Off</span>
                                @else<span class="badge" style="background:#b8860b;color:#fff">Not usable</span>@endif
                            </td>
                            <td data-label="" class="right"><button type="button" class="btn btn-light vc-edit" data-id="{{ $v->id }}" style="padding:6px 12px;font-size:13px">Edit</button></td>
                        </tr>
                        <tr id="vc-edit-{{ $v->id }}" hidden><td colspan="6">
                            <form method="POST" action="{{ route('vouchers.update', $v) }}" style="padding:6px 0">
                                @csrf @method('PUT')
                                <div class="grid grid-2" style="gap:10px">
                                    <div class="field"><label>Code</label><input name="code" value="{{ $v->code }}" style="text-transform:uppercase" required></div>
                                    <div class="field"><label>Type</label><select name="type"><option value="percent" @selected($v->type==='percent')>% off</option><option value="fixed" @selected($v->type==='fixed')>£ off</option></select></div>
                                    <div class="field"><label>Value</label><input name="value" type="number" step="0.01" min="0" value="{{ $v->value }}" required></div>
                                    <div class="field"><label>Max uses</label><input name="max_uses" type="number" min="1" value="{{ $v->max_uses }}" placeholder="unlimited"></div>
                                    <div class="field"><label>Valid from</label><input name="valid_from" type="date" value="{{ $v->valid_from?->format('Y-m-d') }}"></div>
                                    <div class="field"><label>Valid to</label><input name="valid_to" type="date" value="{{ $v->valid_to?->format('Y-m-d') }}"></div>
                                </div>
                                <div class="field"><label>Comment</label><input name="comment" value="{{ $v->comment }}"></div>
                                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" style="width:auto" {{ $v->is_active ? 'checked' : '' }}> Active</label>
                                    <button class="btn btn-primary" style="padding:8px 16px;font-size:14px">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('vouchers.destroy', $v) }}" onsubmit="return confirm('Delete {{ $v->code }}?')" style="margin-top:6px">
                                @csrf @method('DELETE')
                                <button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;color:var(--red)">Delete code</button>
                            </form>
                        </td></tr>
                    @empty
                        <tr><td colspan="6" class="muted" style="text-align:center;padding:20px">No discount codes yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.querySelectorAll('.vc-edit').forEach(function (b) {
            b.addEventListener('click', function () {
                var r = document.getElementById('vc-edit-' + b.dataset.id);
                if (r) r.hidden = !r.hidden;
            });
        });
    </script>
@endsection
