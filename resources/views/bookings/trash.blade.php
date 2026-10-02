@extends('layouts.app')
@section('title', 'Deleted bookings')

@section('content')
    <div class="list-head">
        <div>
            <h1 class="page-title" style="margin:0">Deleted bookings</h1>
            <p class="page-sub" style="margin:2px 0 0">{{ $bookings->total() }} in the trash · restore one, or delete it for good.</p>
        </div>
        <a href="{{ route('bookings.index') }}" class="btn btn-ghost" style="padding:9px 16px">← Bookings</a>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card">
        <div class="table-scroll">
            <table class="table-modern table-cards">
                <thead><tr><th>Deleted</th><th>Journey</th><th>Customer</th><th>Ref</th><th></th></tr></thead>
                <tbody>
                    @forelse($bookings as $b)
                        <tr>
                            <td data-label="Deleted" style="font-size:13px">{{ $b->deleted_at?->format('d M Y, H:i') }}</td>
                            <td data-label="Journey" style="font-size:13px">
                                {{ $b->pickup_at?->format('D d M, H:i') }}<br>
                                <span class="muted">{{ \Illuminate\Support\Str::limit($b->pickup_address, 26) }} → {{ \Illuminate\Support\Str::limit($b->destination_address, 26) }}</span>
                            </td>
                            <td data-label="Customer">{{ $b->displayName() ?: $b->customer?->name ?: '—' }}</td>
                            <td data-label="Ref" class="mono">{{ $b->reference }}</td>
                            <td data-label="" class="right">
                                <form method="POST" action="{{ route('bookings.restore', $b->id) }}" style="display:inline">
                                    @csrf
                                    <button class="btn btn-light" style="padding:6px 12px;font-size:13px">↩ Restore</button>
                                </form>
                                <form method="POST" action="{{ route('bookings.force-destroy', $b->id) }}" style="display:inline"
                                      onsubmit="return confirm('Permanently delete {{ $b->reference }}? This cannot be undone.')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;color:#b32020">Delete forever</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted" style="text-align:center;padding:22px">Nothing in the trash.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($bookings->hasPages())<div style="margin-top:12px">{{ $bookings->links() }}</div>@endif
    </div>
@endsection
