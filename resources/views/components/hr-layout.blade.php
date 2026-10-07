@props([
    'title' => 'HRIS',
    'active' => 'dashboard',
    'portal' => 'HR',
    'employeeName' => null,
    'live' => true,
])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>{{ $title }}</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f5f7;
            color: #222;
            line-height: 1.5;
        }

        a {
            color: inherit;
        }

        /* ---------- Layout ---------- */

        .shell {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            flex-shrink: 0;
            background: #2e2e2e;
            color: #fff;
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
            padding: 0 12px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            margin-bottom: 16px;
        }

        .brand small {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            font-weight: 400;
            color: #aaa;
        }

        .nav a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            margin-bottom: 4px;
            border-radius: 8px;
            color: #cfcfcf;
            text-decoration: none;
            font-size: 14px;
        }

        .nav a:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .nav a.active {
            background: #fff;
            color: #2e2e2e;
            font-weight: 700;
        }

        .nav-count {
            min-width: 22px;
            padding: 1px 7px;
            border-radius: 999px;
            background: #e5322d;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 12px;
            font-size: 12px;
            color: #888;
        }

        .content {
            flex: 1;
            min-width: 0;
            padding: 32px 36px 110px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 26px;
        }

        .page-header p {
            margin: 4px 0 0;
            color: #777;
            font-size: 14px;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 12px;
            font-size: 14px;
            color: #2f7de1;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .chip {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: #fdecea;
            color: #c0392b;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .chip.ok {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .flash {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .errors {
            background: #fdecea;
            color: #c0392b;
            border: 1px solid #f5c6c2;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        /* ---------- Stat cards ---------- */

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat {
            background: #fff;
            border-radius: 14px;
            padding: 18px 20px;
            border: 1px solid #e6e6e6;
            border-left: 5px solid #2e2e2e;
        }

        .stat .label {
            font-size: 13px;
            color: #777;
        }

        .stat .value {
            font-size: 34px;
            font-weight: 700;
            line-height: 1.2;
            margin-top: 4px;
        }

        .stat .hint {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
        }

        .stat.danger {
            border-left-color: #e5322d;
        }

        .stat.danger .value {
            color: #c0392b;
        }

        .stat.warn {
            border-left-color: #e08e0b;
        }

        .stat.info {
            border-left-color: #2f7de1;
        }

        .stat.good {
            border-left-color: #2e9e5b;
        }

        /* ---------- Panels ---------- */

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .card {
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 16px;
        }

        .card-header a {
            font-size: 13px;
            color: #2f7de1;
            text-decoration: none;
        }

        .card-header a:hover {
            text-decoration: underline;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th {
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #888;
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
        }

        td {
            padding: 12px 10px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        /* ---------- Badges ---------- */

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge.submitted {
            background: #e3f0ff;
            color: #1b5fb8;
        }

        .badge.under_review {
            background: #fff4e0;
            color: #b9770e;
        }

        .badge.approved,
        .badge.valid {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge.rejected,
        .badge.expired {
            background: #fdecea;
            color: #c0392b;
        }

        .badge.soon {
            background: #fff4e0;
            color: #b9770e;
        }

        .badge.draft {
            background: #eee;
            color: #666;
        }

        .badge.interview_scheduled {
            background: #efe7fb;
            color: #6a3fb5;
        }

        .badge.interview_completed {
            background: #e0f4f4;
            color: #12727a;
        }

        .muted {
            color: #888;
        }

        .empty {
            color: #888;
            font-size: 14px;
            padding: 12px 0;
        }

        /* ---------- Tabs ---------- */

        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .tabs a {
            padding: 8px 14px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #e0e0e0;
            color: #555;
            text-decoration: none;
            font-size: 13px;
        }

        .tabs a:hover {
            border-color: #bbb;
        }

        .tabs a.active {
            background: #2e2e2e;
            border-color: #2e2e2e;
            color: #fff;
            font-weight: 700;
        }

        /* ---------- Detail + forms ---------- */

        .details {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 10px 16px;
            margin: 0;
            font-size: 14px;
        }

        .details dt {
            color: #888;
        }

        .details dd {
            margin: 0;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        textarea {
            width: 100%;
            min-height: 90px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
        }

        .btn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .renew {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .renew input[type="date"] {
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 13px;
        }

        .btn {
            display: inline-block;
            padding: 8px 16px;
            border: 0;
            border-radius: 8px;
            background: #2e2e2e;
            color: #fff;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .btn.good {
            background: #2e9e5b;
        }

        .btn.danger {
            background: #c0392b;
        }

        .btn.ghost {
            background: #fff;
            color: #2e2e2e;
            border: 1px solid #ccc;
        }

        .btn.small {
            padding: 5px 12px;
        }

        /* ---------- Mobile ---------- */

        @media (max-width: 900px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .shell {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                flex-direction: row;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                overflow-x: auto;
            }

            .brand {
                border: 0;
                margin: 0;
                padding: 0 8px 0 0;
                white-space: nowrap;
            }

            .brand small,
            .sidebar-footer {
                display: none;
            }

            .nav {
                display: flex;
                gap: 6px;
            }

            .nav a {
                margin: 0;
                white-space: nowrap;
                gap: 8px;
            }

            .content {
                padding: 20px 16px 110px;
            }

            .renew {
                flex-direction: column;
                align-items: stretch;
            }

            .details {
                grid-template-columns: 1fr;
                gap: 2px;
            }

            .details dd {
                margin-bottom: 10px;
            }
        }
    </style>
</head>

<body>

    @php
        $pendingApplications = $portal === 'HR'
            ? \App\Models\Application::pending()->count()
            : 0;
    @endphp

    <div class="shell">

        <aside class="sidebar">

            <div class="brand">
                Frontier HRIS
                <small>{{ $portal }} Portal</small>
            </div>

            <nav class="nav">

                @if ($portal === 'HR')

                    <a
                        href="{{ route('hr.dashboard') }}"
                        class="{{ $active === 'dashboard' ? 'active' : '' }}"
                    >
                        Dashboard
                    </a>

                    <a
                        href="{{ route('hr.applicants') }}"
                        class="{{ $active === 'applicants' ? 'active' : '' }}"
                    >
                        Applicants

                        @if ($pendingApplications > 0)
                            <span class="nav-count">
                                {{ $pendingApplications }}
                            </span>
                        @endif
                    </a>

                    <a
                        href="{{ route('hr.compliance') }}"
                        class="{{ $active === 'compliance' ? 'active' : '' }}"
                    >
                        Compliance
                    </a>

                @else

                    <a
                        href="{{ route('employee.dashboard') }}"
                        class="{{ $active === 'dashboard' ? 'active' : '' }}"
                    >
                        My Documents
                    </a>

                @endif

            </nav>

            <div class="sidebar-footer">
                @if ($employeeName)
                    {{ $employeeName }}<br>
                @endif
                Frontier Security Services Inc.
            </div>

        </aside>

        {{-- data-live: this area is refreshed automatically on HR pages --}}
        <main class="content" data-live>
            {{ $slot }}
        </main>

    </div>

    <x-robot
        :role="$portal"
        :page="$active"
        :employee-name="$employeeName"
    />


    @if ($portal === 'HR' && $live)
        <script>
            (() => {
                const REFRESH_MS = 5000;

                async function refreshContent() {
                    // Don't waste requests while the tab is in the background
                    if (document.hidden) return;

                    try {
                        const response = await fetch(window.location.href, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            cache: 'no-store',
                        });

                        if (!response.ok) return;

                        const html = await response.text();

                        const doc = new DOMParser().parseFromString(
                            html,
                            'text/html'
                        );

                        const fresh = doc.querySelector('[data-live]');
                        const current = document.querySelector('[data-live]');

                        // Only touch the page when something actually changed
                        if (
                            fresh &&
                            current &&
                            fresh.innerHTML !== current.innerHTML
                        ) {
                            current.innerHTML = fresh.innerHTML;
                        }

                    } catch (error) {
                        console.error('Live refresh failed.', error);
                    }
                }

                setInterval(refreshContent, REFRESH_MS);

                // Catch up immediately when you return to this tab
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) refreshContent();
                });
            })();
        </script>
    @endif

</body>
</html>