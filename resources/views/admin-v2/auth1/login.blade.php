@extends('admin-v2.layouts.base', ['title' => 'Admin Login'])

@section('content')
<div class="flex min-h-screen items-center p-12.5">
    <div class="container">
        <div class="flex justify-center px-2.5">
            <div class="2xl:w-4/10 md:w-1/2 sm:w-2/3 w-full">

                <div class="mb-3 flex flex-col items-center justify-center text-center">
                    <a class="auth-logo text-2xl font-bold" href="{{ route('login') }}">{{ config('app.name') }}</a>
                    <h4 class="font-bold mt-5 mb-2">{{ config('app.name') }} Admin Portal</h4>
                    <p class="text-default-400 mx-auto w-full lg:w-3/4 mb-4">Sign in to access the administrative dashboard.</p>
                </div>

                <div class="card p-7.5 rounded-2xl">

                    @if ($errors->any())
                        <div class="alert alert-danger mb-5">
                            <ul class="mb-0 list-none p-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (session('status'))
                        <div class="alert alert-success mb-5">
                            {{ session('status') }}
                        </div>
                    @endif

                    <form action="{{ route('login') }}" method="POST">
                        @csrf

                        <div class="mb-5">
                            <label class="form-label" for="email">
                                Email address <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input class="form-input @error('email') is-invalid @enderror"
                                    id="email"
                                    name="email"
                                    type="email"
                                    value="{{ old('email') }}"
                                    placeholder="you@example.com"
                                    required
                                    autofocus>
                            </div>
                        </div>

                        <div class="mb-5">
                            <label class="form-label" for="password">
                                Password <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input class="form-input @error('password') is-invalid @enderror"
                                    id="password"
                                    name="password"
                                    type="password"
                                    placeholder="••••••••"
                                    required>
                            </div>
                        </div>

                        <div class="mb-5 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <input class="form-checkbox form-checkbox-light size-4.25"
                                    id="remember"
                                    name="remember"
                                    type="checkbox"
                                    {{ old('remember') ? 'checked' : '' }}>
                                <label class="form-check-label" for="remember">Keep me signed in</label>
                            </div>
                            <a class="text-default-400 underline underline-offset-4"
                                href="{{ route('password.request') }}">Forgot Password?</a>
                        </div>

                        <div>
                            <button type="submit"
                                class="btn bg-primary w-full py-3 font-semibold text-white hover:bg-primary-hover">
                                Sign In
                            </button>
                        </div>
                    </form>
                </div>

                <p class="text-default-400 mt-7.5 text-center">
                    &copy; {{ date('Y') }} {{ config('app.name') }}
                </p>

            </div>
        </div>
    </div>
</div>
@endsection
