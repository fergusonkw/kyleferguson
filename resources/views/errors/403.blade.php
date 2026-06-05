@extends('admin-v2.layouts.base', ['title' => '403 — Forbidden'])

@section('content')
<div class="min-h-screen flex items-center justify-center bg-default-50 px-4">
    <div class="max-w-md w-full text-center">
        <a href="{{ route('admin.home') }}" class="inline-block mb-8 text-2xl font-bold">{{ config('app.name') }}</a>
        <h1 class="text-8xl font-bold text-primary mb-2">403</h1>
        <h2 class="text-2xl font-semibold text-default-800 mb-3">Access Forbidden</h2>
        <p class="text-default-500 mb-8">You don't have permission to access this resource.</p>
        <a href="{{ route('admin.home') }}" class="btn btn-primary">Go to Dashboard</a>
    </div>
</div>
@endsection
