@php
    // Presentation only: the custody page continues to authorize and gate every action.
    $hasOperationalObligation = (bool) $obligationSummary;

    // Gate Pass-only processing is operational, not an accountability case.
    $isGatePassOnlyPending = $custody->isGatePassOnlyPending();
    $hasAccountabilityObligation = $hasOperationalObligation && ! $isGatePassOnlyPending;

    /*
     * Kapag may active accountability case ang transaction, diretso sa
     * mismong case ng borrower imbes na sa generic Accountability page.
     */
    $accountabilityUrl = route('accountability.index');

    if ($hasAccountabilityObligation && $borrowingRequest->custody && $borrowingRequest->borrower) {
        $openIncident = $borrowingRequest->custody->incidents
            ->where('status', '!=', 'RESOLVED')
            ->sortByDesc('reported_at')
            ->first();

        $openOverdueCase = $borrowingRequest->custody->overdueCase;

        $focus = null;

        if ($openIncident) {
            $focus = 'incident-'.$openIncident->id;
        } elseif ($openOverdueCase && $openOverdueCase->status !== 'RESOLVED') {
            $focus = 'overdue-'.$openOverdueCase->id;
        }

        if ($focus) {
            $accountabilityUrl = route('accountability.borrower', [
                'borrower' => $borrowingRequest->borrower,
                'tab' => 'cases',
                'focus' => $focus,
            ]);
        }
    }

    $nextActionLabel = match (true) {
        $requestIsCancelled => 'No further action required',
        $requestIsCompleted => 'Custody record',
        $detailStatus === 'READY_FOR_RELEASE' => 'Proceed to physical release',
        $detailStatus === 'PREPARING_RELEASE' && (bool) $custody->pickup_expired_at => 'Reschedule the pickup window',
        $detailStatus === 'PREPARING_RELEASE' && ! $hasPickupSchedule => 'Set the pickup schedule',
        $detailStatus === 'PREPARING_RELEASE' => 'Confirm item preparation',
        $hasOperationalObligation => $obligationSummary['title'],
        $detailStatus === 'OVERDUE' => 'Process the overdue return',
        (bool) $custody->released_at => 'Continue return processing',
        default => 'Review the custody record',
    };
@endphp

<section class="page-heading request-operational-heading" aria-labelledby="operational-request-title">
    <div class="request-operational-identity">
        <div class="request-operational-title-row">
            <h1 id="operational-request-title">{{ $borrowingRequest->request_no }}</h1>
            @php
                $previousControlNumbers = $borrowingRequest->previousControlNumbers();
            @endphp
            @if($previousControlNumbers)
                <p class="meta">Previous Control No.: {{ implode(', ', $previousControlNumbers) }}</p>
            @endif
            <x-status-badge :status="$recordBadge['key']" :label="$recordBadge['label']" data-record-status />
        </div>
        <p>{{ $v->purpose_event }}</p>
    </div>

    <div class="request-operational-next-action">
        @if($requestIsCompleted)
            <p><span>Related transaction:</span> {{ $nextActionLabel }}</p>
            <a class="button secondary ui-pressable request-custody-link" href="{{ route('custody.show', $borrowingRequest->custody) }}">
                View Custody Record
            </a>
        @else
            <p><span>Next action:</span> {{ $nextActionLabel }}</p>
            @if($hasAccountabilityObligation)
                <a class="button primary ui-pressable request-custody-link" href="{{ $accountabilityUrl }}">
                    Open Accountability
                </a>
            @else
                <a class="button primary ui-pressable request-custody-link" href="{{ route('custody.show', $borrowingRequest->custody) }}">
                    Open Custody Record
                </a>
            @endif
        @endif
    </div>
</section>
