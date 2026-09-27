@extends('layouts.app')
@section('title', 'Driver rotation')

@section('content')
    <h1 class="page-title">✈ Airport order — executive bookings &amp; rotation driver</h1>
    <p class="page-sub"><strong>Click an airport below</strong> to see its <strong>executive</strong> jobs in the order they came through, with the rotation driver each was given. Spot a job on the wrong driver? Change it inline. (Executive only — that's what Abdi&nbsp;↔&nbsp;Maj rotate on.)</p>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('admin.route-order._panel', [
        'panelRoute' => 'rotation.index',
        'scope' => $orderScope,
        'tabs' => $orderTabs,
        'selected' => $orderSelected,
        'vehicleTabs' => $orderVehicleTabs,
        'selectedVehicle' => $orderSelectedVehicle,
        'rows' => $orderRows,
        'drivers' => $orderDrivers,
    ])
@endsection
