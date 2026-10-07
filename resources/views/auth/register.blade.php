<x-auth-layout title="Create account">

    <h1>Create your account</h1>
    <p class="sub">Register to apply for Security Guard at Frontier Security Services.</p>

    <form method="POST" action="{{ route('register.submit') }}">
        @csrf

        <div class="field {{ $errors->has('name') ? 'has-error' : '' }}">
            <label for="name">Full name</label>

            <input
                id="name"
                type="text"
                name="name"
                value="{{ old('name') }}"
                autocomplete="name"
                required
                autofocus
            >

            @error('name')
                <div class="err">{{ $message }}</div>
            @enderror
        </div>

        <div class="field {{ $errors->has('email') ? 'has-error' : '' }}">
            <label for="email">Email</label>

            <input
                id="email"
                type="email"
                name="email"
                value="{{ old('email') }}"
                autocomplete="email"
                required
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
                    autocomplete="new-password"
                    minlength="8"
                    required
                >

                <button type="button" class="pw-toggle" data-toggle-password>Show</button>
            </div>

            @error('password')
                <div class="err">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm password</label>

            <div class="pw">
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    minlength="8"
                    required
                >

                <button type="button" class="pw-toggle" data-toggle-password>Show</button>
            </div>
        </div>

        <button type="submit" class="btn">Create account</button>
    </form>

    <p class="alt">
        Already have an account?
        <a href="{{ route('login') }}">Log in</a>
    </p>

</x-auth-layout>