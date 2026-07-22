@extends('layouts.app')
@section('title', 'Job ' . $booking->reference)

@section('content')
    @include('driver._job')
@endsection
