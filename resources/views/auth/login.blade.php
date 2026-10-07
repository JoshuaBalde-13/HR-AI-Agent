
<x-auth-layout title="Log in">

    <h1>Log in</h1>
    <p class="sub">Welcome back. Log in to track your application.</p>

    @if (session('status'))
        <div class="notice">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.submit') }}">
        @csrf

        <div class="field {{ $errors->has('email') ? 'has-error' : '' }}">
            <label for="email">Email</label>

            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                autofocus
            >

            @error('email')
                <div class="err">{{ $message }}</div>
            @enderror
        </div>

        <div class="field {{ $errors->has('password') ? 'has-error' : '' }}">
            <label for="password">Password</label>

            <div class="pw">
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

                <button type="button" class="pw-toggle" data-toggle-password>Show</button>
            </div>

            @error('password')
                <div class="err">{{ $message }}</div>
            @enderror
        </div>

        <label class="remember">
            <input type="checkbox" name="remember" value="1">
            Remember me
        </label>

        <button type="submit" class="btn">Log in</button>
    </form>

    <p class="alt">
        New applicant?
        <a href="{{ route('register') }}">Create an account</a>
    </p>

</x-auth-layout>