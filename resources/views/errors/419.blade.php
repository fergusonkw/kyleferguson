@extends('admin-v2.layouts.base', ['title' => '419 — Page Expired'])

@section('content')
<div class="min-h-screen flex items-center justify-center bg-default-50 px-4">
    <div class="max-w-md w-full text-center">
        <a href="javascript:history.back()" class="inline-block mb-8 text-2xl font-bold">{{ config('app.name') }}</a>
        <h1 class="text-8xl font-bold text-warning mb-2">419</h1>
        <h2 class="text-2xl font-semibold text-default-800 mb-3">Session Expired</h2>
        <p class="text-default-500 mb-8">Your session has expired for security reasons. Please refresh and try again.</p>
        <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
    </div>
</div>
@endsection
