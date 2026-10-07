<x-hr-layout
    title="HR Dashboard"
    active="dashboard"
>

    <div class="page-header">

        <div>
            <h1>HR Dashboard</h1>
            <p>{{ now()->format('l, F j, Y') }}</p>
        </div>

        @if ($unreadAlerts > 0)
            <span class="chip">
                {{ $unreadAlerts }} unread alert(s)
            </span>
        @else
            <span class="chip ok">
                All alerts read
            </span>
        @endif

    </div>


    {{-- Stat cards --}}
    <div class="stats">

        <div class="stat info">
            <div class="label">Submitted Applications</div>
            <div class="value">{{ $submittedCount }}</div>
            <div class="hint">{{ $todayCount }} submitted today</div>
        </div>

        <div class="stat {{ $pendingReviewCount > 0 ? 'warn' : 'good' }}">
            <div class="label">Pending Review</div>
            <div class="value">{{ $pendingReviewCount }}</div>
            <div class="hint">
                <a href="{{ route('hr.applicants') }}">Review now</a>
            </div>
        </div>

        <div class="stat">
            <div class="label">In Progress</div>
            <div class="value">{{ $inProgressCount }}</div>
            <div class="hint">Applicants still filling out</div>
        </div>

        <div class="stat {{ $expiredCount > 0 ? 'danger' : 'good' }}">
            <div class="label">Expired Documents</div>
            <div class="value">{{ $expiredCount }}</div>
            <div class="hint">
                {{ $expiringSoonCount }} expiring within 30 days
            </div>
        </div>

    </div>


    <div class="grid-2">

        {{-- Recent applications --}}
        <section class="card">

            <div class="card-header">
                <h2>Recent Applications</h2>

                <a href="{{ route('hr.applicants') }}">
                    View all
                </a>
            </div>

            @if ($recentApplications->isEmpty())

                <p class="empty">No submitted applications yet.</p>

            @else

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($recentApplications as $application)
                                <tr>
                                    <td>
                                        {{ $application->applicant->name ?? 'Unknown' }}
                                    </td>

                                    <td>
                                        <span class="badge {{ $application->status }}">
                                            {{ $application->statusLabel() }}
                                        </span>
                                    </td>

                                    <td class="muted">
                                        {{ $application->submitted_at?->diffForHumans() }}
                                    </td>

                                    <td>
                                        <a
                                            href="{{ route('hr.applicants.show', $application) }}"
                                            class="btn small"
                                        >
                                            Open
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            @endif

        </section>


        {{-- Document alerts --}}
        <section class="card">

            <div class="card-header">
                <h2>Expired Documents</h2>

                <a href="{{ route('hr.compliance') }}">
                    View all
                </a>
            </div>

            @if ($expiredDocuments->isEmpty())

                <p class="empty">No expired documents. </p>

            @else

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Document</th>
                                <th>Overdue</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($expiredDocuments as $document)
                                <tr>
                                    <td>{{ $document->employee_name }}</td>
                                    <td>{{ $document->document_type }}</td>
                                    <td>
                                        <span class="badge expired">
                                            {{ (int) $document->expiry_date->diffInDays(today(), true) }} day(s)
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            @endif

        </section>

    </div>

</x-hr-layout>