@php
    $custody = $record->custody;
    $outstanding = $custody?->lines->sum(
        fn ($line) => max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        )
    ) ?? 0;

    $version = $record->currentVersion;
    $scheduleDate = $version?->schedule_date ?: $version?->needed_from;
    $returnDate = $version?->return_date ?: $version?->return_due_at ?: $custody?->due_at;

    // The same classification drives Admin, SAO and both Borrower list filters.
    ['key' => $operationalStatusKey, 'label' => $operationalLabel] = $record->borrowerRecordStatus();
    $isCancelled = in_array($operationalStatusKey, ['CANCELLED', 'REJECTED'], true);
    $isCompleted = ! $isCancelled && ! \App\Support\RequestRecordStatus::isOngoing($record);
    $workflowGroup = $isCancelled ? 'cancelled' : ($isCompleted ? 'completed' : 'active');
@endphp

<a
    class="operational-record ui-pressable"
    href="{{ $custody ? route('custody.show', $custody) : route('requests.show', $record) }}"
    data-borrowings-record
    data-request-id="{{ $record->id }}"
    data-borrowings-group="{{ $workflowGroup }}"
    data-borrowings-status="{{ $operationalStatusKey }}"
>
    <span class="operational-record-primary">
        <strong>{{ $custody?->custody_no ?: $record->request_no }}</strong>
        <span>{{ $custody ? 'Request '.$record->request_no : ($version?->purpose_event ?: 'Borrowing request') }}</span>
        <small>
            Use {{ optional($scheduleDate)->format('d M Y') ?: 'Schedule pending' }}
            · Return by {{ optional($returnDate)->format('d M Y') ?: 'Not scheduled' }}
        </small>
    </span>

    <span class="operational-record-facts">
        @if($custody)
        <span><small>Pickup schedule</small><strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong></span>
        <span><small>Items released</small><strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not released' }}</strong></span>

        @if($isCancelled)
            <span>
                <small>Cancelled</small>
                <strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: 'Cancelled' }}</strong>
            </span>
        @elseif($isCompleted)
            <span>
                <small>{{ $operationalLabel }}</small>
                <strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: $operationalLabel }}</strong>
            </span>
        @else
            <span>
                <small>{{ $custody->status === 'OVERDUE' ? 'Items overdue' : 'Items on custody' }}</small>
                <strong>{{ $outstanding + 0 }} {{ $outstanding == 1 ? 'item' : 'items' }}</strong>
            </span>
        @endif
        @else
            <span><small>Submitted</small><strong>{{ ($version?->submitted_at ?: $record->created_at)?->format('d M Y, g:i A') ?: 'Not recorded' }}</strong></span>
            <span><small>Items</small><strong>{{ $version?->items->count() ?? 0 }} item types</strong></span>
        @endif
    </span>

    <span class="operational-record-action">
        <x-status-badge
            :status="$operationalStatusKey"
            :label="$operationalLabel"
        />
        <strong>{{ $custody ? 'View details' : 'View Request' }}</strong>
    </span>
</a>
