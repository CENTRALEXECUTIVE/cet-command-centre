{{-- Shared route-order panel. Expects: $scope, $tabs, $selected, $vehicleTabs,
     $selectedVehicle, $rows, and $panelRoute (the route name to link back to). --}}
<style>
    /* On phones the jobs table would need side-scrolling, so each row becomes a
       self-contained card — every field on screen at once, no horizontal scroll. */
    @media (max-width: 700px) {
        .ro-table thead { display: none; }
        .ro-table, .ro-table tbody, .ro-table tr, .ro-table td { display: block; width: 100%; }
        .ro-table tr { border: 1px solid var(--line); border-radius: 12px; margin-bottom: 10px; padding: 10px 12px; }
        .ro-table td { border: 0; padding: 3px 0; display: flex; gap: 8px; align-items: baseline; }
        .ro-table td::before { content: attr(data-label); flex: 0 0 88px; font-size: 12px; font-weight: 700; color: var(--muted); }
        .ro-table td[data-label="Driver"] { display: block; }
        .ro-table td[data-label="Driver"] form { margin-top: 4px; }
        .ro-table td[data-label="Driver"]::before { display: block; margin-bottom: 2px; }
    }
</style>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
    @foreach(['upcoming' => 'Upcoming', 'today' => 'Today', 'past' => 'Past 60d', 'all' => 'All (60d)'] as $key => $label)
        <a href="{{ route($panelRoute, ['scope' => $key, 'route' => $selected, 'vehicle' => $selectedVehicle]) }}"
           class="btn {{ $scope === $key ? 'btn-primary' : 'btn-ghost' }}" style="padding:6px 14px;font-size:13px">{{ $label }}</a>
    @endforeach
</div>

<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
    @foreach($tabs as $t)
        <a href="{{ route($panelRoute, ['scope' => $scope, 'route' => $t['tag'], 'vehicle' => 'All']) }}"
           class="btn {{ $selected === $t['tag'] ? 'btn-dark' : 'btn-ghost' }}" style="padding:7px 14px;font-size:13px">
            {{ $t['tag'] === 'Free Roam' ? '🧭' : ($t['tag'] === 'Other' ? '📍' : '✈') }} {{ $t['tag'] }}
            <span class="muted" style="margin-left:4px">{{ $t['count'] }}</span>
        </a>
    @endforeach
</div>

{{-- Vehicle-type filter — the Abdi/Maj rotation only applies to Executive. --}}
@if($vehicleTabs->count() > 1)
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
        <span class="muted" style="font-size:12px">Vehicle:</span>
        @foreach($vehicleTabs as $v)
            <a href="{{ route($panelRoute, ['scope' => $scope, 'route' => $selected, 'vehicle' => $v['tag']]) }}"
               class="btn {{ $selectedVehicle === $v['tag'] ? 'btn-primary' : 'btn-ghost' }}" style="padding:5px 12px;font-size:12px">
                {{ $v['tag'] }} <span class="muted" style="margin-left:3px">{{ $v['count'] }}</span>
            </a>
        @endforeach
    </div>
@endif

<div class="card">
    <h2 style="margin:0 0 4px">{{ $selected ?? '—' }}@if($selectedVehicle !== 'All') · {{ $selectedVehicle }}@endif — {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('job', $rows->count()) }}</h2>
    @if($rows->isEmpty())
        <p class="muted mb-0">No bookings on this route in this period.</p>
    @else
        <p class="hint" style="margin:-4px 0 10px">Newest first — the order jobs came through, and the driver the rotation gave each.</p>
        <table class="ro-table">
            <thead><tr><th>#</th><th>Came in</th><th>Pickup</th><th>Ref</th><th>Vehicle</th><th>Driver</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($rows as $i => $b)
                <tr>
                    <td class="muted" data-label="#">{{ $i + 1 }}</td>
                    <td data-label="Came in" style="white-space:nowrap;font-size:13px">{{ $b->created_at?->format('D d M, H:i') }}</td>
                    <td data-label="Pickup" style="white-space:nowrap;font-size:13px">{{ $b->pickup_at?->format('D d M, H:i') }}</td>
                    <td data-label="Ref"><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a>
                        <span class="muted" style="font-size:12px">{{ \Illuminate\Support\Str::limit($b->displayName(), 18) }}</span></td>
                    <td class="muted" data-label="Vehicle" style="font-size:13px">{{ $b->vehicleType?->name ?: $b->displayVehicleType() }}</td>
                    <td data-label="Driver">
                        <strong>{{ $b->assignedDriverLabel() }}</strong>
                        @if(($drivers ?? collect())->isNotEmpty() && ! $b->status->isTerminal())
                            <form method="POST" action="{{ route('despatch.reassign', $b) }}" style="margin:2px 0 0">
                                @csrf
                                <select name="driver_id" onchange="this.form.submit()" style="font-size:12px;max-width:150px;padding:3px 6px">
                                    <option value="" disabled selected>Change…</option>
                                    @foreach($drivers as $d)
                                        <option value="{{ $d->id }}" @selected($b->driver_id === $d->id)>{{ $d->driverProfile?->callsign ?: $d->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                    </td>
                    <td data-label="Status"><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
