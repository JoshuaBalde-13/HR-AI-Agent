<x-hr-layout
    title="HR Compliance"
    active="compliance"
>

    <div class="page-header">

        <div>
            <h1>Compliance</h1>
            <p>Employee document expiry tracking</p>
        </div>

        @if ($expired->count() > 0)
            <span class="chip">
                {{ $expired->count() }} expired
            </span>
        @else
            <span class="chip ok">
                All documents valid
            </span>
        @endif

    </div>


    <div class="stats">

        <div class="stat {{ $expired->count() > 0 ? 'danger' : 'good' }}">
            <div class="label">Expired</div>
            <div class="value">{{ $expired->count() }}</div>
            <div class="hint">Needs immediate action</div>
        </div>

        <div class="stat {{ $expiringSoon->count() > 0 ? 'warn' : 'good' }}">
            <div class="label">Expiring Within 30 Days</div>
            <div class="value">{{ $expiringSoon->count() }}</div>
            <div class="hint">Renewal reminder</div>
        </div>

    </div>


    <section class="card">

        <div class="card-header">
            <h2>Expired Documents ({{ $expired->count() }})</h2>
        </div>

        @if ($expired->isEmpty())

            <p class="empty">No expired documents.</p>

        @else

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Document</th>
                            <th>Expired On</th>
                            <th>Overdue</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($expired as $document)
                            <tr>
                                <td>{{ $document->employee_name }}</td>
                                <td>{{ $document->document_type }}</td>
                                <td>{{ $document->expiry_date->format('M d, Y') }}</td>
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


    <section class="card">

        <div class="card-header">
            <h2>Expiring Within 30 Days ({{ $expiringSoon->count() }})</h2>
        </div>

        @if ($expiringSoon->isEmpty())

            <p class="empty">No documents expiring soon.</p>

        @else

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Document</th>
                            <th>Expires On</th>
                            <th>Days Left</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($expiringSoon as $document)
                            <tr>
                                <td>{{ $document->employee_name }}</td>
                                <td>{{ $document->document_type }}</td>
                                <td>{{ $document->expiry_date->format('M d, Y') }}</td>
                                <td>
                                    <span class="badge soon">
                                        {{ (int) today()->diffInDays($document->expiry_date, true) }} day(s)
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @endif

    </section>

</x-hr-layout>