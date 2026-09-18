{{-- Shared route-order panel. Expects: $scope, $tabs, $selected, $vehicleTabs,
     $selectedVehicle, $rows, and $panelRoute (the route name to link back to). --}}
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
        <table>
            <thead><tr><th>#</th><th>Came in</th><th>Pickup</th><th>Ref</th><th>Vehicle</th><th>Driver</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($rows as $i => $b)
                <tr>
                    <td class="muted">{{ $i + 1 }}</td>
                    <td style="white-space:nowrap;font-size:13px">{{ $b->created_at?->format('D d M, H:i') }}</td>
                    <td style="white-space:nowrap;font-size:13px">{{ $b->pickup_at?->format('D d M, H:i') }}</td>
                    <td><a href="{{ route('bookings.show', $b) }}" class="mono">{{ $b->reference }}</a>
                        <div class="muted" style="font-size:12px">{{ \Illuminate\Support\Str::limit($b->displayName(), 18) }}</div></td>
                    <td class="muted" style="font-size:13px">{{ $b->vehicleType?->name ?: $b->displayVehicleType() }}</td>
                    <td><strong>{{ $b->assignedDriverLabel() }}</strong></td>
                    <td><span class="badge badge-{{ $b->status->value }}">{{ $b->status->label() }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
