@extends('layouts.app')
@section('title', 'Waiting List')

@section('content')
    <h1 class="page-title">Waiting List</h1>
    <p class="page-sub">Customers waiting on a date are auto-notified by WhatsApp when a matching booking is cancelled.</p>

    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <h2>Add to waiting list</h2>
        <form method="POST" action="{{ route('waiting-list.store') }}">
            @csrf
            <div class="grid grid-3">
                <div class="field">
                    <label for="customer_name">Name <span class="req">*</span></label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required>
                </div>
                <div class="field">
                    <label for="customer_phone">Mobile <span class="req">*</span></label>
                    <input id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}" required>
                </div>
                <div class="field">
                    <label for="desired_date">Date <span class="req">*</span></label>
                    <input id="desired_date" type="date" name="desired_date" value="{{ old('desired_date') }}" required>
                </div>
            </div>
            <div class="grid grid-3">
                <div class="field">
                    <label for="desired_time_window">Time window</label>
                    <input id="desired_time_window" name="desired_time_window" value="{{ old('desired_time_window') }}" placeholder="e.g. morning / 14:00–16:00">
                </div>
                <div class="field">
                    <label for="vehicle_type_id">Vehicle type</label>
                    <select id="vehicle_type_id" name="vehicle_type_id">
                        <option value="">Any</option>
                        @foreach($vehicleTypes as $vt)
                            <option value="{{ $vt->id }}" @selected(old('vehicle_type_id')==$vt->id)>{{ $vt->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary btn-block">Add to list</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Current list</h2>
        @if($entries->isEmpty())
            <p class="muted mb-0">No one is waiting.</p>
        @else
            <table>
                <thead><tr><th>Customer</th><th>Date</th><th>Window</th><th>Vehicle</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($entries as $entry)
                        <tr>
                            <td>{{ $entry->customer?->name }}<br><span class="muted mono">{{ $entry->customer?->phone }}</span></td>
                            <td>{{ $entry->desired_date->format('D d M Y') }}</td>
                            <td>{{ $entry->desired_time_window ?? 'Any' }}</td>
                            <td>{{ $entry->vehicleType?->name ?? 'Any' }}</td>
                            <td><span class="badge badge-{{ $entry->status === 'notified' ? 'complete' : ($entry->status === 'converted' ? 'accepted' : 'pending') }}">{{ ucfirst($entry->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:16px">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
