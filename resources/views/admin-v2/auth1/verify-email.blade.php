@extends('admin-v2.layouts.base', ['title' => 'Verify Your Email'])

@section('content')
<div class="flex min-h-screen items-center p-12.5">
    <div class="container">
        <div class="flex justify-center px-2.5">
            <div class="2xl:w-4/10 md:w-1/2 sm:w-2/3 w-full">

                <div class="mb-3 flex flex-col items-center justify-center text-center">
                    <a class="auth-logo text-2xl font-bold" href="{{ route('login') }}">{{ config('app.name') }}</a>
                    <h4 class="font-bold mt-5 mb-2">Verify Your Email Address</h4>
                    <p class="text-default-400 mx-auto w-full lg:w-3/4 mb-4">
                        We've sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
                        Click it to activate your account.
                    </p>
                </div>

                <div class="card p-7.5 rounded-2xl">

                    @if (session('status'))
                        <div class="alert alert-success mb-5">
                            {{ session('status') }}
                        </div>
                    @endif

                    <p class="text-default-500 mb-5">
                        Didn't get the email? Check your spam folder, or request a new link below.
                    </p>

                    <form action="{{ route('verification.send') }}" method="POST">
                        @csrf
                        <button type="submit"
                            class="btn bg-primary w-full py-3 font-semibold text-white hover:bg-primary-hover">
                            Resend Verification Email
                        </button>
                    </form>

                    <form action="{{ route('logout') }}" method="POST" class="mt-4">
                        @csrf
                        <button type="submit"
                            class="btn btn-light w-full py-3 font-semibold">
                            Sign Out
                        </button>
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
