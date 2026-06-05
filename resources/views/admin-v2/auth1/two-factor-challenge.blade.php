@extends('admin-v2.layouts.base', ['title' => 'Two-Factor Authentication'])

@section('content')
<div class="flex min-h-screen items-center p-12.5">
    <div class="container">
        <div class="flex justify-center px-2.5">
            <div class="2xl:w-4/10 md:w-1/2 sm:w-2/3 w-full">

                <div class="mb-3 flex flex-col items-center justify-center text-center">
                    <a class="auth-logo text-2xl font-bold" href="{{ route('login') }}">{{ config('app.name') }}</a>
                    <h4 class="font-bold mt-5 mb-2">Two-Factor Authentication</h4>
                    <p class="text-default-400 mx-auto w-full lg:w-3/4 mb-4">
                        Enter the 6-digit code from your authenticator app, or use a recovery code.
                    </p>
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

                    <form action="{{ route('two-factor.login.store') }}" method="POST">
                        @csrf

                        <div class="mb-5">
                            <label class="form-label" for="code">Authentication Code</label>
                            <div class="input-group">
                                <input class="form-input"
                                    id="code"
                                    name="code"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    placeholder="123456"
                                    autofocus>
                            </div>
                        </div>

                        <div class="mb-5">
                            <label class="form-label" for="recovery_code">Or a recovery code</label>
                            <div class="input-group">
                                <input class="form-input"
                                    id="recovery_code"
                                    name="recovery_code"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="XXXX-XXXX">
                            </div>
                        </div>

                        <div>
                            <button type="submit"
                                class="btn bg-primary w-full py-3 font-semibold text-white hover:bg-primary-hover">
                                Verify
                            </button>
                        </div>
                    </form>

                    <p class="text-default-400 mt-5 text-center">
                        <a class="underline underline-offset-4" href="{{ route('login') }}">Back to sign in</a>
                    </p>
                </div>

                <p class="text-default-400 mt-7.5 text-center">
                    &copy; {{ date('Y') }} {{ config('app.name') }}
                </p>

            </div>
        </div>
    </div>
</div>
@endsection
