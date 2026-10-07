<x-hr-layout
    title="HR Applicants"
    active="applicants"
>

    <div class="page-header">

        <div>
            <h1>Applicants</h1>
            <p>Review submitted job applications</p>
        </div>

        @if ($counts['pending'] > 0)
            <span class="chip">
                {{ $counts['pending'] }} waiting for action
            </span>
        @else
            <span class="chip ok">
                Nothing waiting
            </span>
        @endif

    </div>


    <div class="tabs">

        <a
            href="{{ route('hr.applicants', ['status' => 'pending']) }}"
            class="{{ $filter === 'pending' ? 'active' : '' }}"
        >
            Pending ({{ $counts['pending'] }})
        </a>

        <a
            href="{{ route('hr.applicants', ['status' => 'interview']) }}"
            class="{{ $filter === 'interview' ? 'active' : '' }}"
        >
            Interviews ({{ $counts['interview'] }})
        </a>

        <a
            href="{{ route('hr.applicants', ['status' => 'approved']) }}"
            class="{{ $filter === 'approved' ? 'active' : '' }}"
        >
            Approved ({{ $counts['approved'] }})
        </a>

        <a
            href="{{ route('hr.applicants', ['status' => 'rejected']) }}"
            class="{{ $filter === 'rejected' ? 'active' : '' }}"
        >
            Rejected ({{ $counts['rejected'] }})
        </a>

        <a
            href="{{ route('hr.applicants', ['status' => 'all']) }}"
            class="{{ $filter === 'all' ? 'active' : '' }}"
        >
            All ({{ $counts['all'] }})
        </a>

    </div>


    <section class="card">

        @if ($applications->isEmpty())

            <p class="empty">No applications in this list.</p>

        @else

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th>Interview</th>
                            <th>Submitted</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($applications as $application)
                            <tr>
                                <td>{{ $application->applicant->name ?? 'Unknown' }}</td>

                                <td>{{ $application->job_title }}</td>

                                <td>
                                    <span class="badge {{ $application->status }}">
                                        {{ $application->statusLabel() }}
                                    </span>
                                </td>

                                <td class="muted">
                                    {{ $application->interview_at?->format('M j, g:i A') ?? '—' }}
                                </td>

                                <td class="muted">
                                    {{ $application->submitted_at?->diffForHumans() }}
                                </td>

                                <td>
                                    <a
                                        href="{{ route('hr.applicants.show', $application) }}"
                                        class="btn small"
                                    >
                                        {{ $application->isPending() ? 'Review' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

    </section>

</x-hr-layout>