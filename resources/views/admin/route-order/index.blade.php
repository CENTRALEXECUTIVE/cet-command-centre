@extends('layouts.app')
@section('title', 'Route order')

@section('content')
    <h1 class="page-title">Route order</h1>
    <p class="page-sub">Pick a route to see its bookings in order, with the driver each one is assigned to — so you can check jobs are going out in the right order.</p>

    @include('admin.route-order._panel', ['panelRoute' => 'route-order.index'])
@endsection
