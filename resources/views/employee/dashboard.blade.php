<x-hr-layout
    title="Employee Dashboard"
    active="dashboard"
    portal="Employee"
    :employee-name="$employeeName"
>

    <div class="page-header">

        <div>
            <h1>My Documents</h1>
            <p>{{ $employeeName }}</p>
        </div>

        @if ($expiredCount > 0)
            <span class="chip">
                {{ $expiredCount }} expired
            </span>
        @elseif ($expiringCount > 0)
            <span class="chip">
                {{ $expiringCount }} expiring soon
            </span>
        @else
            <span class="chip ok">
                All documents valid
            </span>
        @endif

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


    <div class="stats">

        <div class="stat {{ $expiredCount > 0 ? 'danger' : 'good' }}">
            <div class="label">Expired</div>
            <div class="value">{{ $expiredCount }}</div>
            <div class="hint">Please renew these</div>
        </div>

        <div class="stat {{ $expiringCount > 0 ? 'warn' : 'good' }}">
            <div class="label">Expiring Within 30 Days</div>
            <div class="value">{{ $expiringCount }}</div>
            <div class="hint">Renew before they expire</div>
        </div>

        <div class="stat info">
            <div class="label">Total Documents</div>
            <div class="value">{{ $documents->count() }}</div>
            <div class="hint">On file</div>
        </div>

    </div>


    <section class="card">

        <div class="card-header">
            <h2>Required Documents</h2>
        </div>

        @if ($documents->isEmpty())

            <p class="empty">No documents on file yet.</p>

        @else

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Update</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($documents as $document)

                            @php
                                $isExpired = $document->expiry_date->lt(today());
                                $isSoon = !$isExpired
                                    && $document->expiry_date->lte(today()->addDays(30));
                                $days = (int) today()->diffInDays($document->expiry_date, true);
                            @endphp

                            <tr>
                                <td>{{ $document->document_type }}</td>

                                <td>{{ $document->expiry_date->format('M d, Y') }}</td>

                                <td>
                                    @if ($isExpired)
                                        <span class="badge expired">
                                            Expired {{ $days }} day(s) ago
                                        </span>
                                    @elseif ($isSoon)
                                        <span class="badge soon">
                                            {{ $days }} day(s) left
                                        </span>
                                    @else
                                        <span class="badge valid">Valid</span>
                                    @endif
                                </td>

                                <td>
                                    <form
                                        method="POST"
                                        action="{{ route('employee.documents.renew', $document) }}"
                                        class="renew"
                                    >
                                        @csrf

                                        <input
                                            type="date"
                                            name="new_expiry"
                                            min="{{ today()->addDay()->toDateString() }}"
                                            required
                                        >

                                        <button type="submit" class="btn">
                                            Renew
                                        </button>
                                    </form>
                                </td>
                            </tr>

                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

    </section>

</x-hr-layout>