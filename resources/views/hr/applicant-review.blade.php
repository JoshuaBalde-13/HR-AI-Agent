<x-hr-layout
    title="Review Application"
    active="applicants"
    :live="false"
>

    @php
        $status = $application->status;

        $rank = [
            'submitted' => 1,
            'under_review' => 2,
            'interview_scheduled' => 3,
            'interview_completed' => 4,
            'approved' => 5,
            'rejected' => 5,
        ][$status] ?? 0;

        $stages = [
            1 => 'Submitted',
            2 => 'Under review',
            3 => 'Interview scheduled',
            4 => 'Interview completed',
            5 => $status === 'approved' ? 'Approved'
                : ($status === 'rejected' ? 'Rejected' : 'Decision'),
        ];
    @endphp

    <style>
        .flow {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .flow .f {
            flex: 1;
            min-width: 120px;
            padding: 10px 8px;
            text-align: center;
            border-radius: 10px;
            background: #eee;
            color: #888;
            font-size: 12px;
        }

        .flow .f.done { background: #d9efdf; color: #2e7d32; }
        .flow .f.current { background: #2e2e2e; color: #fff; font-weight: 700; }

        .hint {
            margin: 0 0 14px;
            font-size: 13px;
            color: #777;
        }

        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .field-row input {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 14px;
        }

        .sched-box {
            background: #f3effb;
            border-left: 4px solid #6a3fb5;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 14px;
        }

        .sched-box .when { font-size: 18px; font-weight: 700; }
        .sched-box .where { font-size: 14px; color: #555; margin-top: 2px; }

        details.resched {
            margin-top: 16px;
            font-size: 14px;
        }

        details.resched summary {
            cursor: pointer;
            color: #2f7de1;
        }

        details.resched .inner { margin-top: 12px; }

        @media (max-width: 720px) {
            .field-row { grid-template-columns: 1fr; }
        }
    </style>

    <a href="{{ route('hr.applicants') }}" class="back-link">
        ← Back to Applicants
    </a>

    <div class="page-header">

        <div>
            <h1>{{ $application->applicant->name ?? 'Unknown applicant' }}</h1>
            <p>Application for {{ $application->job_title }}</p>
        </div>

        <span class="badge {{ $status }}">
            {{ $application->statusLabel() }}
        </span>

    </div>


    {{-- Progress --}}
    <div class="flow">
        @foreach ($stages as $n => $label)
            <div class="f {{ $rank >= 5 || $rank > $n ? 'done' : ($rank === $n ? 'current' : '') }}">
                {{ $label }}
            </div>
        @endforeach
    </div>


    @if (session('success'))
        <div class="flash">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif


    <section class="card">

        <div class="card-header">
            <h2>Application Details</h2>
        </div>

        <dl class="details">

            <dt>Position</dt>
            <dd>{{ $application->job_title }}</dd>

            <dt>Phone</dt>
            <dd>{{ $application->phone ?? '—' }}</dd>

            <dt>Address</dt>
            <dd>{{ $application->address ?? '—' }}</dd>

            <dt>Documents</dt>
            <dd>
                Applicant confirmed all 4 required documents
                (Government ID, NBI Clearance, Medical Certificate,
                Security Training Certificate)
            </dd>

            <dt>Submitted</dt>
            <dd>
                {{ $application->submitted_at?->format('M d, Y g:i A') }}
                ({{ $application->submitted_at?->diffForHumans() }})
            </dd>

            @if ($application->interview_at)
                <dt>Interview</dt>
                <dd>
                    {{ $application->interview_at->format('D, M j, Y g:i A') }}
                    @if ($application->interview_location)
                        · {{ $application->interview_location }}
                    @endif
                </dd>
            @endif

            @if ($application->interview_completed_at)
                <dt>Interview done</dt>
                <dd>{{ $application->interview_completed_at->format('M d, Y g:i A') }}</dd>
            @endif

            @if ($application->reviewed_at)
                <dt>Decided</dt>
                <dd>{{ $application->reviewed_at->format('M d, Y g:i A') }}</dd>
            @endif

            @if ($application->remarks)
                <dt>HR remarks</dt>
                <dd>{{ $application->remarks }}</dd>
            @endif

        </dl>

    </section>


    {{-- ============ Stage 1: start the review ============ --}}
    @if ($status === 'submitted')

        <section class="card">

            <div class="card-header">
                <h2>Next step: start the review</h2>
            </div>

            <p class="hint">
                Look through the application details first. After this you can
                schedule the interview.
            </p>

            <form
                method="POST"
                action="{{ route('hr.applicants.review', $application) }}"
            >
                @csrf
                <input type="hidden" name="action" value="start_review">

                <button type="submit" class="btn">Start review</button>
            </form>

        </section>


    {{-- ============ Stage 2: schedule the interview ============ --}}
    @elseif ($status === 'under_review')

        <section class="card">

            <div class="card-header">
                <h2>Next step: schedule the interview</h2>
            </div>

            <p class="hint">
                The applicant will see this schedule on their dashboard.
                You can approve or reject only after the interview is completed.
            </p>

            <form
                method="POST"
                action="{{ route('hr.applicants.review', $application) }}"
            >
                @csrf
                <input type="hidden" name="action" value="schedule_interview">

                <div class="field-row">

                    <div>
                        <label class="form-label" for="interview_at">
                            Date and time
                        </label>

                        <input
                            id="interview_at"
                            type="datetime-local"
                            name="interview_at"
                            min="{{ now()->format('Y-m-d\TH:i') }}"
                            value="{{ old('interview_at') }}"
                        >
                    </div>

                    <div>
                        <label class="form-label" for="interview_location">
                            Location
                            <span class="muted">(optional)</span>
                        </label>

                        <input
                            id="interview_location"
                            type="text"
                            name="interview_location"
                            maxlength="255"
                            placeholder="e.g. HR Office, 2nd Floor"
                            value="{{ old('interview_location') }}"
                        >
                    </div>

                </div>

                <div class="btn-row">
                    <button type="submit" class="btn">Schedule interview</button>
                </div>

            </form>

        </section>


    {{-- ============ Stage 3: waiting for the interview ============ --}}
    @elseif ($status === 'interview_scheduled')

        <section class="card">

            <div class="card-header">
                <h2>Interview scheduled</h2>
            </div>

            <div class="sched-box">
                <div class="when">
                    {{ $application->interview_at?->format('l, F j, Y · g:i A') }}
                </div>

                @if ($application->interview_location)
                    <div class="where">{{ $application->interview_location }}</div>
                @endif
            </div>

            <p class="hint">
                After the interview, press the button below. Only then can you
                approve or reject the applicant.
            </p>

            <form
                method="POST"
                action="{{ route('hr.applicants.review', $application) }}"
                onsubmit="return confirm('Mark this interview as completed?');"
            >
                @csrf
                <input type="hidden" name="action" value="complete_interview">

                <button type="submit" class="btn good">Interview completed</button>
            </form>

            <details class="resched">
                <summary>Reschedule interview</summary>

                <div class="inner">
                    <form
                        method="POST"
                        action="{{ route('hr.applicants.review', $application) }}"
                    >
                        @csrf
                        <input type="hidden" name="action" value="schedule_interview">

                        <div class="field-row">

                            <div>
                                <label class="form-label" for="re_interview_at">
                                    New date and time
                                </label>

                                <input
                                    id="re_interview_at"
                                    type="datetime-local"
                                    name="interview_at"
                                    min="{{ now()->format('Y-m-d\TH:i') }}"
                                    value="{{ old('interview_at', $application->interview_at?->format('Y-m-d\TH:i')) }}"
                                >
                            </div>

                            <div>
                                <label class="form-label" for="re_interview_location">
                                    Location
                                </label>

                                <input
                                    id="re_interview_location"
                                    type="text"
                                    name="interview_location"
                                    maxlength="255"
                                    value="{{ old('interview_location', $application->interview_location) }}"
                                >
                            </div>

                        </div>

                        <div class="btn-row">
                            <button type="submit" class="btn ghost">Save new schedule</button>
                        </div>
                    </form>
                </div>
            </details>

        </section>


    {{-- ============ Stage 4: decision ============ --}}
    @elseif ($status === 'interview_completed')

        <section class="card">

            <div class="card-header">
                <h2>Final decision</h2>
            </div>

            <p class="hint">
                The interview is done. Approve or reject this applicant.
            </p>

            <form
                method="POST"
                action="{{ route('hr.applicants.review', $application) }}"
            >
                @csrf

                <label class="form-label" for="remarks">
                    Remarks
                    <span class="muted">(required when rejecting)</span>
                </label>

                <textarea
                    id="remarks"
                    name="remarks"
                    maxlength="500"
                >{{ old('remarks') }}</textarea>

                <div class="btn-row">

                    <button
                        type="submit"
                        name="action"
                        value="approve"
                        class="btn good"
                    >
                        Approve
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="reject"
                        class="btn danger"
                    >
                        Reject
                    </button>

                </div>

            </form>

        </section>

    @endif

</x-hr-layout>