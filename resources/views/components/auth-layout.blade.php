@props(['title' => 'Frontier HRIS'])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title }}</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f5f7;
            color: #222;
            line-height: 1.5;
        }

        .wrap {
            width: 100%;
            max-width: 420px;
        }

        .logo {
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .logo small {
            display: block;
            margin-top: 2px;
            font-size: 13px;
            font-weight: 400;
            color: #888;
        }

        .panel {
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        }

        .panel h1 { margin: 0 0 4px; font-size: 22px; }
        .panel .sub { margin: 0 0 20px; color: #777; font-size: 14px; }

        .notice {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .field { margin-bottom: 16px; }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .field input {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
        }

        .field input:focus {
            outline: 2px solid #2e2e2e;
            border-color: transparent;
        }

        .field.has-error input { border-color: #c0392b; }

        .err {
            margin-top: 5px;
            color: #c0392b;
            font-size: 13px;
        }

        .pw { position: relative; }
        .pw input { padding-right: 64px; }

        .pw-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #2f7de1;
            font-size: 13px;
            cursor: pointer;
            padding: 4px 6px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            font-size: 14px;
            color: #555;
            cursor: pointer;
        }

        .btn {
            width: 100%;
            padding: 12px 18px;
            border: 0;
            border-radius: 10px;
            background: #2e2e2e;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:hover { opacity: 0.9; }

        .alt {
            margin: 18px 0 0;
            text-align: center;
            font-size: 14px;
            color: #666;
        }

        .alt a { color: #2f7de1; text-decoration: none; font-weight: 700; }
        .alt a:hover { text-decoration: underline; }

        .foot {
            margin: 16px 0 0;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>

<body>

    <div class="wrap">

        <div class="logo">
            Frontier HRIS
            <small>Applicant Portal</small>
        </div>

        <div class="panel">
            {{ $slot }}
        </div>

        <p class="foot">Frontier Security Services, Inc.</p>

    </div>

    <script>
        // Show / hide password
        document.querySelectorAll('[data-toggle-password]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = button.parentElement.querySelector('input');
                const show = input.type === 'password';

                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
            });
        });
    </script>

</body>
</html>