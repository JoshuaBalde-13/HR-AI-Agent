<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Staff Login | Frontier HRIS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f2f5f9;
            color: #172b4d;
            font-family: Arial, Helvetica, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 32px;
            border: 1px solid #e0e6ef;
            border-radius: 16px;
            background: white;
            box-shadow: 0 12px 35px rgba(23, 43, 77, 0.08);
        }

        .brand {
            margin-bottom: 26px;
            text-align: center;
        }

        .brand h1 {
            margin: 0;
            font-size: 23px;
        }

        .brand p {
            margin: 6px 0 0;
            color: #66758a;
            font-size: 14px;
        }

        h2 {
            margin: 0 0 6px;
            font-size: 21px;
        }

        .subtext {
            margin: 0 0 24px;
            color: #66758a;
            font-size: 14px;
            line-height: 1.5;
        }

        .field {
            margin-bottom: 17px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 700;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #cdd5df;
            border-radius: 8px;
            font: inherit;
        }

        input:focus {
            outline: 2px solid #365f91;
            outline-offset: 1px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 5px 0 22px;
            color: #4d5d73;
            font-size: 14px;
        }

        .submit-button {
            width: 100%;
            padding: 13px;
            border: 0;
            border-radius: 8px;
            background: #25476a;
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .submit-button:hover {
            background: #1c3856;
        }

        .error-box {
            margin-bottom: 18px;
            padding: 12px;
            border: 1px solid #f0c5c2;
            border-radius: 8px;
            background: #fff1f0;
            color: #a12d27;
            font-size: 14px;
        }

        .status-box {
            margin-bottom: 18px;
            padding: 12px;
            border: 1px solid #b9ddc3;
            border-radius: 8px;
            background: #edf9f0;
            color: #256b3b;
            font-size: 14px;
        }

        .footer {
            margin: 22px 0 0;
            text-align: center;
            color: #66758a;
            font-size: 13px;
        }

        .footer a {
            color: #25476a;
            font-weight: 700;
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <main class="login-card">
        <header class="brand">
            <h1>Frontier HRIS</h1>
            <p>Staff Portal</p>
        </header>

        <h2>Welcome back</h2>
        <p class="subtext">
            Sign in using your staff account to access the HRIS.
        </p>

        @if (session('status'))
            <div class="status-box">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="error-box" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('staff.login.submit') }}">
            @csrf

            <div class="field">
                <label for="email">Staff email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <label class="remember">
                <input type="checkbox" name="remember" value="1">
                Remember me
            </label>

            <button class="submit-button" type="submit">
                Log in to staff portal
            </button>
        </form>

        <p class="footer">
            Applicant?
            <a href="{{ route('login') }}">Go to Applicant Login</a>
        </p>
    </main>
</body>
</html>