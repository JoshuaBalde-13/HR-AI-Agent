<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Applicant Dashboard</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f5f7;
            color: #222;
            line-height: 1.5;
        }

        /* ---------- Top bar ---------- */

        .topbar {
            background: #2e2e2e;
            color: #fff;
        }

        .topbar-inner {
            max-width: 760px;
            margin: 0 auto;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand { font-weight: 700; font-size: 17px; }
        .who { font-size: 14px; color: #bbb; }

        .who-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logout {
            background: transparent;
            border: 1px solid #777;
            color: #ddd;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
        }

        .logout:hover { background: #444; }

        /* ---------- Page ---------- */

        .page {
            max-width: 760px;
            margin: 0 auto;
            padding: 28px 20px 140px;
        }

        .hero h1 { margin: 0; font-size: 26px; }
        .hero p { margin: 4px 0 20px; color: #777; font-size: 14px; }

        .card {
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 16px;
        }

        .card h2 { margin: 0 0 4px; font-size: 18px; }
        .card .sub { margin: 0 0 16px; color: #777; font-size: 14px; }

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

        /* ---------- Badges + live dot ---------- */

        .badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }

        .badge.submitted { background: #e3f0ff; color: #1b5fb8; }
        .badge.under_review { background: #fff4e0; color: #b9770e; }
        .badge.approved { background: #e8f5e9; color: #2e7d32; }
        .badge.rejected { background: #fdecea; color: #c0392b; }
        .badge.interview_scheduled { background: #efe7fb; color: #6a3fb5; }
        .badge.interview_completed { background: #e0f4f4; color: #12727a; }

        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .live {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #777;
        }

        .live::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2e9e5b;
            animation: pulse 1.6s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(46, 158, 91, 0.5); }
            70% { box-shadow: 0 0 0 8px rgba(46, 158, 91, 0); }
            100% { box-shadow: 0 0 0 0 rgba(46, 158, 91, 0); }
        }

        /* ---------- Interview card ---------- */

        .card.interview {
            border-left: 5px solid #6a3fb5;
            background: #faf7ff;
        }

        .card.interview .big { font-size: 20px; font-weight: 700; }
        .card.interview .place { margin: 6px 0 0; font-size: 14px; color: #555; }

        /* ---------- Stepper (draft) ---------- */

        .stepper {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
        }

        .stepper .s {
            flex: 1;
            padding: 10px 8px;
            text-align: center;
            border-radius: 10px;
            background: #eee;
            color: #888;
            font-size: 13px;
        }

        .stepper .s.done { background: #d9efdf; color: #2e7d32; }
        .stepper .s.current { background: #2e2e2e; color: #fff; font-weight: 700; }

        /* ---------- Forms ---------- */

        .field { margin-bottom: 16px; }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .field input[type="text"] {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #ccc;
            border-radius: 10px;
            font-size: 14px;
        }

        .field input[type="text"]:focus {
            outline: 2px solid #2e2e2e;
            border-color: transparent;
        }

        .check {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
        }

        .check:hover { border-color: #999; }
        .check input { width: 18px; height: 18px; }

        .btn {
            display: inline-block;
            padding: 11px 22px;
            border: 0;
            border-radius: 10px;
            background: #2e2e2e;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn:hover { opacity: 0.9; }
        .btn.good { background: #2e9e5b; }

        /* Pulses when the robot says it's time to click */
        .btn.nudge { animation: nudge 1.4s ease-in-out infinite; }

        @keyframes nudge {
            0%, 100% { box-shadow: 0 0 0 0 rgba(46, 46, 46, 0.45); }
            50%      { box-shadow: 0 0 0 9px rgba(46, 46, 46, 0); }
        }

        /* ---------- Summary + steps list ---------- */

        .summary {
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 10px 16px;
            margin: 0;
            font-size: 14px;
        }

        .summary dt { color: #888; }
        .summary dd { margin: 0; }

        .how {
            margin: 0 0 18px;
            padding-left: 20px;
            font-size: 14px;
            color: #555;
        }

        .how li { margin-bottom: 6px; }

        /* ---------- Timeline ---------- */

        .timeline { margin: 18px 0 4px; }

        .tl {
            position: relative;
            display: flex;
            gap: 14px;
            padding-bottom: 20px;
        }

        .tl:last-child { padding-bottom: 0; }

        .tl::before {
            content: "";
            position: absolute;
            left: 13px;
            top: 28px;
            bottom: 0;
            width: 2px;
            background: #e2e2e2;
        }

        .tl:last-child::before { display: none; }

        .dot {
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            border-radius: 50%;
            background: #eee;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
        }

        .tl.done .dot { background: #2e9e5b; color: #fff; }
        .tl.current .dot { background: #e08e0b; color: #fff; }
        .tl.approved .dot { background: #2e9e5b; color: #fff; }
        .tl.rejected .dot { background: #c0392b; color: #fff; }

        .tl .name { font-weight: 700; font-size: 14px; }
        .tl .when { font-size: 13px; color: #888; }

        /* ---------- Remarks ---------- */

        .remarks {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 10px;
            border-left: 4px solid #bbb;
            background: #f7f7f7;
            font-size: 14px;
        }

        .remarks.approved { border-left-color: #2e9e5b; background: #f1faf3; }
        .remarks.rejected { border-left-color: #c0392b; background: #fdf3f2; }
        .remarks strong { display: block; margin-bottom: 2px; }

        @media (max-width: 560px) {
            .summary { grid-template-columns: 1fr; gap: 2px; }
            .summary dd { margin-bottom: 10px; }
            .stepper .s { font-size: 12px; padding: 8px 4px; }
        }
    </style>
</head>

<body>

    @php
        $status = $application->status ?? null;
        $isDraft = $application && $status === 'draft';
        $decided = in_array($status, ['approved', 'rejected'], true);
        $isLive = $application && in_array($status, \App\Models\Application::PENDING, true);

        $rank = [
            'submitted' => 1,
            'under_review' => 2,
            'interview_scheduled' => 3,
            'interview_completed' => 4,
            'approved' => 5,
            'rejected' => 5,
        ][$status] ?? 0;
    @endphp

    <header class="topbar">
        <div class="topbar-inner">
            <span class="brand">Frontier HRIS</span>

            <div class="who-wrap">
                <span class="who">{{ $applicant->name }}</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout">Log out</button>
                </form>
            </div>
        </div>
    </header>

    {{-- data-live: refreshed automatically while HR has not decided yet --}}
    <main class="page" data-live data-status="{{ $status ?? 'none' }}">

        <div class="hero">
            <h1>Welcome, {{ $applicant->name }}</h1>
            <p>Track your job application here</p>
        </div>

        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif


        @if (!$application)

            {{-- ============ No application yet ============ --}}
            <section class="card">
                <h2>Security Guard</h2>
                <p class="sub">Frontier Security Services, Inc.</p>

                <ol class="how">
                    <li>Fill in your personal information</li>
                    <li>Confirm your 4 required documents</li>
                    <li>Review and submit to HR</li>
                </ol>

                <form method="POST" action="{{ route('applicant.start', $applicant) }}">
                    @csrf
                    <button type="submit" class="btn">Start Application</button>
                </form>
            </section>


        @elseif ($isDraft)

            {{-- ============ Draft: 3-step form ============ --}}
            <div class="stepper">
                <div class="s {{ $application->step > 1 ? 'done' : 'current' }}">1. Personal Info</div>
                <div class="s {{ $application->step > 2 ? 'done' : ($application->step === 2 ? 'current' : '') }}">2. Documents</div>
                <div class="s {{ $application->step === 3 ? 'current' : '' }}">3. Review</div>
            </div>

            @if ($errors->any())
                <div class="errors">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <section class="card">

                @if ($application->step === 1)

                    <h2>Personal Information</h2>
                    <p class="sub">So HR can contact you.</p>

                    <form method="POST" action="{{ route('applicant.step', $applicant) }}">
                        @csrf

                        <div class="field">
                            <label for="phone">Phone number</label>
                            <input id="phone" type="text" name="phone"
                                   value="{{ old('phone', $application->phone) }}">
                        </div>

                        <div class="field">
                            <label for="address">Address</label>
                            <input id="address" type="text" name="address"
                                   value="{{ old('address', $application->address) }}">
                        </div>

                        <button type="submit" class="btn">Continue</button>
                    </form>


                @elseif ($application->step === 2)

                    <h2>Required Documents</h2>
                    <p class="sub">Confirm that you have all of these ready.</p>

                    <form method="POST" action="{{ route('applicant.step', $applicant) }}">
                        @csrf

                        <label class="check">
                            <input type="checkbox" name="documents[]" value="gov_id">
                            Government ID
                        </label>

                        <label class="check">
                            <input type="checkbox" name="documents[]" value="nbi">
                            NBI Clearance
                        </label>

                        <label class="check">
                            <input type="checkbox" name="documents[]" value="medical">
                            Medical Certificate
                        </label>

                        <label class="check">
                            <input type="checkbox" name="documents[]" value="training">
                            Security Training Certificate
                        </label>

                        <button type="submit" class="btn">Continue</button>
                    </form>


                @elseif ($application->step === 3)

                    <h2>Review and Submit</h2>
                    <p class="sub">Make sure everything is correct.</p>

                    <dl class="summary">
                        <dt>Position</dt>
                        <dd>{{ $application->job_title }}</dd>

                        <dt>Phone</dt>
                        <dd>{{ $application->phone }}</dd>

                        <dt>Address</dt>
                        <dd>{{ $application->address }}</dd>

                        <dt>Documents</dt>
                        <dd>All 4 confirmed</dd>
                    </dl>

                    <form method="POST" action="{{ route('applicant.submit', $applicant) }}"
                          style="margin-top: 20px;">
                        @csrf
                        <button type="submit" class="btn good">Submit Application</button>
                    </form>

                @endif

            </section>


        @else

            {{-- ============ Submitted: status + timeline ============ --}}

            @if ($status === 'interview_scheduled' && $application->interview_at)
                <section class="card interview">
                    <h2>Your interview</h2>

                    <div class="big">{{ $application->interview_at->format('l, F j, Y') }}</div>
                    <div class="big">{{ $application->interview_at->format('g:i A') }}</div>

                    @if ($application->interview_location)
                        <p class="place">{{ $application->interview_location }}</p>
                    @endif

                    <p class="place">Please arrive a few minutes early.</p>
                </section>
            @endif

            <section class="card">

                <div class="status-row">
                    <h2>{{ $application->job_title }}</h2>

                    <span class="badge {{ $status }}">{{ $application->statusLabel() }}</span>
                </div>

                @if ($isLive)
                    <span class="live">Live – updates automatically</span>
                @endif

                <div class="timeline">

                    {{-- 1. Submitted --}}
                    <div class="tl done">
                        <div class="dot">✓</div>
                        <div>
                            <div class="name">Submitted</div>
                            <div class="when">
                                {{ $application->submitted_at?->format('M d, Y g:i A') }}
                            </div>
                        </div>
                    </div>

                    {{-- 2. Under review --}}
                    <div class="tl {{ $rank === 2 ? 'current' : ($rank > 2 ? 'done' : '') }}">
                        <div class="dot">{{ $rank > 2 ? '✓' : '2' }}</div>
                        <div>
                            <div class="name">Under review</div>
                            <div class="when">
                                {{ $rank >= 2 ? 'HR has looked at your application' : 'Waiting for HR to start' }}
                            </div>
                        </div>
                    </div>

                    {{-- 3. Interview --}}
                    <div class="tl {{ $rank === 3 ? 'current' : ($rank > 3 ? 'done' : '') }}">
                        <div class="dot">{{ $rank > 3 ? '✓' : '3' }}</div>
                        <div>
                            <div class="name">Interview</div>
                            <div class="when">
                                @if ($rank === 3 && $application->interview_at)
                                    {{ $application->interview_at->format('M d, Y g:i A') }}
                                    @if ($application->interview_location)
                                        · {{ $application->interview_location }}
                                    @endif
                                @elseif ($rank > 3)
                                    {{ $application->interview_completed_at
                                        ? 'Completed ' . $application->interview_completed_at->format('M d, Y g:i A')
                                        : 'No interview recorded' }}
                                @else
                                    Not scheduled yet
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- 4. Decision --}}
                    <div class="tl {{ $status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : ($rank === 4 ? 'current' : '')) }}">
                        <div class="dot">
                            {{ $status === 'approved' ? '✓' : ($status === 'rejected' ? '✕' : '4') }}
                        </div>
                        <div>
                            <div class="name">
                                @if ($status === 'approved') Approved
                                @elseif ($status === 'rejected') Not approved
                                @else Decision
                                @endif
                            </div>
                            <div class="when">
                                @if ($application->reviewed_at)
                                    {{ $application->reviewed_at->format('M d, Y g:i A') }}
                                @elseif ($rank === 4)
                                    Interview done – HR is deciding
                                @else
                                    Pending
                                @endif
                            </div>
                        </div>
                    </div>

                </div>

                @if ($application->remarks)
                    <div class="remarks {{ $status }}">
                        <strong>HR remarks</strong>
                        {{ $application->remarks }}
                    </div>
                @endif

            </section>

            <section class="card">
                <h2>Your details</h2>

                <dl class="summary" style="margin-top: 12px;">
                    <dt>Phone</dt>
                    <dd>{{ $application->phone ?? '—' }}</dd>

                    <dt>Address</dt>
                    <dd>{{ $application->address ?? '—' }}</dd>
                </dl>
            </section>

        @endif

    </main>


    <x-robot
        role="Applicant"
        page="dashboard"
        :applicant-id="$applicant->id"
    />


    {{-- Live refresh: only while HR has not decided yet --}}
    <script>
        (() => {
            const REFRESH_MS = 5000;
            const LIVE = @json(\App\Models\Application::PENDING);

            const initial = document.querySelector('[data-live]');

            // Draft steps never refresh, so typing is never wiped.
            if (!initial || !LIVE.includes(initial.dataset.status)) return;

            let timer = null;

            async function refreshContent() {
                if (document.hidden) return;

                try {
                    const response = await fetch(window.location.href, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });

                    if (!response.ok) return;

                    const doc = new DOMParser().parseFromString(
                        await response.text(),
                        'text/html'
                    );

                    const fresh = doc.querySelector('[data-live]');
                    const current = document.querySelector('[data-live]');

                    if (!fresh || !current) return;

                    if (fresh.innerHTML !== current.innerHTML) {
                        current.innerHTML = fresh.innerHTML;
                    }

                    current.dataset.status = fresh.dataset.status;

                    // HR decided: nothing left to wait for.
                    if (!LIVE.includes(fresh.dataset.status)) {
                        clearInterval(timer);
                    }

                } catch (error) {
                    console.error('Live refresh failed.', error);
                }
            }

            timer = setInterval(refreshContent, REFRESH_MS);

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) refreshContent();
            });
        })();
    </script>


    {{-- Real-time guide: the robot reacts while the applicant fills the form --}}
    @if ($isDraft)
    <script>
        (() => {
            const robot = window.HrisRobot;
            if (!robot) return;

            // The page, not the server, decides what the robot says here.
            robot.guide(true);

            const step = {{ (int) $application->step }};
            const button = document.querySelector('main form .btn');
            const row = (icon, text, level = 'low') => ({ icon, text, level });

            const debounce = (fn, ms = 350) => {
                let t;
                return (...args) => {
                    clearTimeout(t);
                    t = setTimeout(() => fn(...args), ms);
                };
            };

            /* ---------------- Step 1: phone + address ---------------- */

            function guideStep1() {
                const phone = document.getElementById('phone');
                const address = document.getElementById('address');
                const title = 'Step 1 of 3 – Personal Info';

                // Assumption: a valid mobile number has at least 10 digits.
                const phoneOk = () => phone.value.replace(/\D/g, '').length >= 10;
                const addressOk = () => address.value.trim().length >= 10;

                function evaluate() {
                    let items;
                    let level = 'medium';
                    let ready = false;

                    if (!phone.value.trim()) {
                        items = [
                            row('', 'Next: enter your phone number', 'high'),
                            row('', 'After that, your complete address'),
                        ];
                    } else if (!phoneOk()) {
                        items = [
                            row('', 'Keep typing – a mobile number has 11 digits (09XXXXXXXXX)', 'medium'),
                        ];
                    } else if (!address.value.trim()) {
                        items = [
                            row('', 'Phone number looks good'),
                            row('', 'Next: enter your complete address', 'high'),
                        ];
                    } else if (!addressOk()) {
                        items = [
                            row('', 'Phone number looks good'),
                            row('', 'Keep going – include street, barangay and city', 'medium'),
                        ];
                    } else {
                        level = 'high';
                        ready = true;
                        items = [
                            row('', 'Phone number looks good'),
                            row('', 'Address looks good'),
                            row('', 'All set! Click Continue to move on to Step 2', 'high'),
                        ];
                    }

                    button?.classList.toggle('nudge', ready);
                    robot.say({ title, level, items });
                }

                const later = debounce(evaluate);
                phone.addEventListener('input', later);
                address.addEventListener('input', later);
                phone.addEventListener('focus', evaluate);
                address.addEventListener('focus', evaluate);

                return evaluate;
            }

            /* ---------------- Step 2: documents ---------------- */

            function guideStep2() {
                const boxes = [...document.querySelectorAll('input[name="documents[]"]')];
                const nameOf = (box) => box.parentElement.textContent.trim();

                function evaluate() {
                    const done = boxes.filter((b) => b.checked);
                    const left = boxes.filter((b) => !b.checked);
                    const title = `Step 2 of 3 – Documents (${done.length}/${boxes.length})`;

                    let items;
                    let level = 'medium';

                    if (!left.length) {
                        level = 'high';
                        items = [
                            row('', `All ${boxes.length} documents confirmed`),
                            row('', 'Great! Click Continue to move on to Review', 'high'),
                        ];
                    } else {
                        items = [
                            ...done.map((b) => row('', `${nameOf(b)} confirmed`)),
                            row('', `Next: confirm your ${nameOf(left[0])}`, 'high'),
                            ...left.slice(1).map((b) => row('', nameOf(b))),
                        ];
                    }

                    button?.classList.toggle('nudge', !left.length);
                    robot.say({ title, level, items });
                }

                boxes.forEach((b) => b.addEventListener('change', evaluate));

                return evaluate;
            }

            /* ---------------- Step 3: review ---------------- */

            function guideStep3() {
                return () => {
                    button?.classList.add('nudge');

                    robot.say({
                        title: 'Step 3 of 3 – Review',
                        level: 'high',
                        items: [
                            row('', 'Check that your phone and address are correct', 'medium'),
                            row('', 'Then click Submit Application to send it to HR', 'high'),
                        ],
                    });
                };
            }

            /* ---------------- Start ---------------- */

            const evaluate = step === 1 ? guideStep1()
                           : step === 2 ? guideStep2()
                           : guideStep3();

            // If the server rejected the form, say why before anything else.
            const errors = [...document.querySelectorAll('.errors div')]
                .map((el) => el.textContent.trim())
                .filter(Boolean);

            if (errors.length) {
                robot.say({
                    title: 'Please fix this first',
                    level: 'critical',
                    items: errors.map((text) => row('', text, 'critical')),
                });
            } else {
                evaluate();
            }
        })();
    </script>
    @endif

</body>
</html>