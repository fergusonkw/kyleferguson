@extends('admin-v2.layouts.base', ['title' => '503 — Service Unavailable'])

@section('content')
<div class="min-h-screen flex items-center justify-center bg-default-50 px-4">
    <div class="max-w-md w-full text-center">
        <a href="{{ route('admin.home') }}" class="inline-block mb-8 text-2xl font-bold">{{ config('app.name') }}</a>
        <img src="/images/svg/maintenance.svg" alt="Maintenance" class="mx-auto mb-6 max-h-48">
        <h2 class="text-2xl font-semibold text-default-800 mb-3">Down for Maintenance</h2>
        <p class="text-default-500 mb-8">We're performing scheduled maintenance and will be back shortly. Thanks for your patience.</p>
        <a href="{{ route('admin.home') }}" class="btn btn-primary">Try Again</a>
    </div>
</div>
@endsection
