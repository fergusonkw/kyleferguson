@extends('admin-v2.layouts.base', ['title' => '500 — Server Error'])

@section('content')
<div class="min-h-screen flex items-center justify-center bg-default-50 px-4">
    <div class="max-w-md w-full text-center">
        <a href="{{ route('admin.home') }}" class="inline-block mb-8 text-2xl font-bold">{{ config('app.name') }}</a>
        <h1 class="text-8xl font-bold text-danger mb-2">500</h1>
        <h2 class="text-2xl font-semibold text-default-800 mb-3">Something Went Wrong</h2>
        <p class="text-default-500 mb-8">An unexpected error occurred. Our team has been notified — please try again shortly.</p>
        <a href="{{ route('admin.home') }}" class="btn btn-primary">Return to Dashboard</a>
    </div>
</div>
@endsection
