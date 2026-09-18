@extends('layouts.app')
@section('title', 'Route order')

@section('content')
    <h1 class="page-title">Route order</h1>
    <p class="page-sub">Pick a route to see its bookings in order, with the driver each one is assigned to — so you can check jobs are going out in the right order.</p>

    {{-- Scope --}}
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
        @foreach(['upcoming' => 'Upcoming', 'today' => 'Today', 'past' => 'Past 60d', 'all' => 'All (60d)'] as $key => $label)
            <a href="{{ route('route-order.index', ['scope' => $key, 'route' => $selected]) }}"
               class="btn {{ $scope === $key ? 'btn-primary' : 'btn-ghost' }}" style="padding:6px 14px;font-size:13px">{{ $label }}</a>
        @endforeach
    </div>

    {{-- Route tabs --}}
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
        @foreach($tabs as $t)
            <a href="{{ route('route-order.index', ['scope' => $scope, 'route' => $t['tag']]) }}"
               class="btn {{ $selected === $t['tag'] ? 'btn-dark' : 'btn-ghost' }}" style="padding:7px 14px;font-size:13px">
                {{ $t['tag'] === 'Free Roam' ? '🧭' : ($t['tag'] === 'Other' ? '📍' : '✈') }} {{ $t['tag'] }}
                <span class="muted" style="margin-left:4px">{{ $t['count'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="card">
        <h2 style="margin:0 0 4px">{{ $selected ?? '—' }} — {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('job', $rows->count()) }}</h2>
        @if($rows->isEmpty())
            <p class="muted mb-0">No bookings on this route in this period.</p>
        @else
            <table>
                <thead><tr><th>#</th><th>When</th><th>Ref</th><th>Route</th><th>Driver</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($rows as $i => $b)
                    <tr>
                        <td class="muted">{{ $i + 1 }}</td>
                        <td style="white-space:nowrap">{{ $b->pickup_at?->format('D d M, H:i') }}</td>
                        <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a>
                            <div class="muted" style="font-size:12px">{{ \Illuminate\Support\Str::limit($b->displayName(), 20) }}</div></td>
                        <td style="font-size:13px">{{ \Illuminate\Support\Str::limit($b->displayPickupAddress(), 16) }} → {{ \Illuminate\Support\Str::limit($b->displayDropoffAddress(), 16) }}</td>
                        <td>{{ $b->assignedDriverLabel() }}</td>
                        <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
