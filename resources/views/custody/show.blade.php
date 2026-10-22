@extends('layouts.app', [
    'title' => $custody->custody_no,
    'topbarTitle' => session('active_workspace') === 'BORROWER'
        ? 'My Borrowings'
        : (auth()->user()?->access_classification?->value === 'SPMU_HEAD'
            ? 'Request Transaction Oversight'
            : (($spmuMode ?? null) === 'return' ? 'Return' : 'Release')),
    'inlinePageNotices' => ($spmuMode ?? null) === 'return',
])

@section('content')
@php
    $workspace = strtoupper((string) session('active_workspace'));
    $user = auth()->user();
    $isBorrower = $workspace === 'BORROWER' && auth()->id() === $custody->borrower_user_id;
    $isSpmu = $workspace === 'SPMU';
    $isSpmuOfficer = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuOfficer;
    $isSpmuHead = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuHead;
    $spmuMode = $spmuMode ?? null;
    $showReleaseWorkflow = ! ($isSpmuOfficer && $spmuMode === 'return');
    $showReturnWorkflow = ! ($isSpmuOfficer && $spmuMode === 'release');
    $useReleaseProcessLayout = $isSpmuOfficer
        && $showReleaseWorkflow
        && $custody->status === 'PREPARING_RELEASE'
        && ! $custody->released_at;

    $useReturnProcessLayout = $isSpmuOfficer
        && $spmuMode === 'return'
        && (bool) $custody->released_at;

    $version = $custody->request?->currentVersion;
    $scheduleDateValue = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');
    $originalReturnDateValue = $custody->original_due_at
        ?: $version?->getAttribute('return_date')
        ?: $version?->getAttribute('return_due_at')
        ?: $custody->due_at;
    $returnDateValue = $custody->due_at ?: $originalReturnDateValue;

    $scheduleDate = $scheduleDateValue ? \Illuminate\Support\Carbon::parse($scheduleDateValue) : null;
    $originalReturnDate = $originalReturnDateValue ? \Illuminate\Support\Carbon::parse($originalReturnDateValue) : null;
    $returnDate = $returnDateValue ? \Illuminate\Support\Carbon::parse($returnDateValue) : null;
    $returnDateAdjusted = $originalReturnDate && $returnDate
        && ! $originalReturnDate->isSameDay($returnDate);

    $outstandingTotal = $custody->lines->sum(
        fn ($line) => max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        )
    );

    $outstandingLines = $custody->lines->filter(
        fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
    );
    $hasOutstandingLinen = $outstandingLines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );
    $hasOutstandingNonLinen = $outstandingLines->contains(
        fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $preparationComplete = (bool) $custody->prepared_at;
    $preparationIssueRecords = collect($preparationIssues ?? []);
    $hasOpenPreparationIssue = $preparationIssueRecords->where('is_resolved', false)->isNotEmpty();
    $hasPreparationExceptionPendingRelease = $custody->status === 'PREPARING_RELEASE'
        && ! $custody->released_at
        && ! $preparationComplete
        && $preparationIssueRecords->isNotEmpty();
    $pickupWindowStartsAt = $custody->scheduled_release_at;
    $pickupWindowEndsAt = $custody->pickup_expires_at;
    $pickupWindowPassed = (bool) $pickupWindowEndsAt
        && now()->gt($pickupWindowEndsAt);
    $pickupHeldForPreparationIssue = ! $custody->released_at
        && $hasPreparationExceptionPendingRelease
        && $pickupWindowPassed;
    $pickupMissed = ! $pickupHeldForPreparationIssue
        && ! $custody->released_at
        && (bool) $custody->pickup_scheduled_at
        && ((bool) $custody->pickup_expired_at || $pickupWindowPassed);
    $hasPickupSchedule = (bool) $pickupWindowStartsAt
        && (bool) $pickupWindowEndsAt
        && ! $pickupMissed;

    $pickupWindowUpcoming = $hasPickupSchedule
        && now()->lt($pickupWindowStartsAt);

    $pickupWindowOpen = $hasPickupSchedule
        && ! $pickupWindowUpcoming
        && ! $pickupWindowPassed;


    $hasOffCampusItem = $custody->lines->contains(
        fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
    );

    $hasLaundryItem = $custody->lines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $laundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;

    /*
     * Keep the summary table honest after return: release condition and return
     * finding are different facts. The old generic "Condition" column used
     * release_condition, which could show SERVICEABLE even when the recorded
     * return finding was DAMAGED.
     */
    $returnFindingsByCustodyLine = ($custody->returns ?? collect())
        ->flatMap(fn ($return) => $return->lines ?? collect())
        ->groupBy('custody_line_id')
        ->map(function ($returnLines): string {
            return $returnLines
                ->groupBy(fn ($line) => strtoupper((string) ($line->condition_code ?: 'FINE')))
                ->map(function ($lines, $condition): string {
                    $label = match ($condition) {
                        'FINE' => 'Good',
                        'DAMAGED' => 'Damaged',
                        'MISSING', 'LOST' => 'Missing',
                        'DESTROYED' => 'Destroyed',
                        'STOLEN' => 'Stolen',
                        default => str($condition)->replace('_', ' ')->lower()->title()->toString(),
                    };

                    return $label.': '.((float) $lines->sum('quantity_received') + 0);
                })
                ->values()
                ->implode('; ');
        });

    $workflowStatus = $custody->workflowStatus();
    $operationalStatusKey = $workflowStatus['key'];
    $obligationSummary = $custody->openObligationSummary();

    // SPMU still recording the accomplished Gate Pass is internal SPMU
    // processing, not a borrower accountability obligation - it must never
    // present as "Accountability Pending" or a dead-end "View Obligation"
    // link to My Obligations (which has no row for it).
    $gatePassOnlyPending = $custody->isGatePassOnlyPending();
    $accountabilityIndicator = (in_array((string) $custody->status, ['OBLIGATION_OPEN', 'INCIDENT_OPEN'], true) && ! $gatePassOnlyPending)
        ? ($obligationSummary ?? ['label' => 'Accountability Pending'])
        : null;
    $operationalLabel = $workflowStatus['label'];
    $transactionFullyComplete = $operationalStatusKey === 'COMPLETED';
    $transactionCancelled = $operationalStatusKey === 'CANCELLED';

    if ($isBorrower && $gatePassOnlyPending) {
        $operationalStatusKey = 'GATE_PASS_PROCESSING';
        $operationalLabel = \App\Support\StatusLabels::label($operationalStatusKey);
    }

    [$borrowerStateTitle, $borrowerStateCopy, $borrowerStateTone] = match (true) {
        $isBorrower && $gatePassOnlyPending => [
            'Physical Return Complete',
            'All issued items have been returned. SPMU is completing the Gate Pass record.',
            'info',
        ],
        $transactionCancelled => [
            'Borrowing cancelled',
            'This borrowing was cancelled before completion. No pickup or release action is required.',
            'neutral',
        ],
        $operationalStatusKey === 'COMPLETED' => [
            'Borrowing completed',
            'All issued items were returned and reconciled. No further action is required.',
            'success',
        ],
        $operationalStatusKey === 'BORROWER_CLEARED' => [
            'Borrower cleared',
            'Your return has been accepted and your obligation is cleared. Any remaining internal processing is handled by SPMU.',
            'success',
        ],
        $accountabilityIndicator !== null => [
            'Accountability processing',
            ($obligationSummary['copy'] ?? null) ?: $accountabilityIndicator['label'].' requires resolution. See My Obligations for the current action.',
            'warning',
        ],
        $custody->status === 'OBLIGATION_OPEN' => [
            $obligationSummary ? 'Return completed — '.$obligationSummary['title'] : 'Return completed with an open obligation',
            $obligationSummary
                ? $obligationSummary['copy'].' See My Obligations for the required action.'
                : 'An accountability or billing obligation still needs resolution. See My Obligations for the required action.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' && $hasOutstandingLinen && $hasOutstandingNonLinen => [
            'Return processing is in progress',
            'A return branch has been recorded, but both return channels still show outstanding property. Return every outstanding non-linen item to SPMU and every outstanding linen item, with the same printed Laundry Form, to the Laundry Area.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' && $hasOutstandingLinen => [
            'Linen return still required',
            'Return every outstanding linen item and the same printed Laundry Form to the Laundry Area. Laundry RECEIVED BY is the physical return date used for timeliness.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' && $hasOutstandingNonLinen => [
            'Non-linen return still required',
            'Return every outstanding non-linen item to SPMU in one complete AO return inspection.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' => [
            'Return processing is in progress',
            'All physical quantities have been recorded. SPMU is completing reconciliation and any linked accountability processing.',
            'info',
        ],
        $custody->status === 'OVERDUE' && $hasOutstandingLinen && $hasOutstandingNonLinen => [
            'This borrowing is overdue',
            'Return every outstanding non-linen item to SPMU and every outstanding linen item, with the same printed Laundry Form, to the Laundry Area immediately.',
            'danger',
        ],
        $custody->status === 'OVERDUE' && $hasOutstandingLinen => [
            'This borrowing is overdue',
            'Return every outstanding linen item and the same printed Laundry Form to the Laundry Area immediately. Laundry RECEIVED BY is the physical return date used for timeliness.',
            'danger',
        ],
        $custody->status === 'OVERDUE' => [
            'This borrowing is overdue',
            'Return every outstanding non-linen item to SPMU immediately for official return inspection.',
            'danger',
        ],
        (bool) $custody->released_at && $hasOutstandingLinen && $hasOutstandingNonLinen => [
            'Items are currently on your custody',
            'On or before the expected return date, return all non-linen items to SPMU and all linen items, with the same printed Laundry Form, to the Laundry Area.',
            'info',
        ],
        (bool) $custody->released_at && $hasOutstandingLinen => [
            'Linen is currently on your custody',
            'On or before the expected return date, return the linen and the same printed Laundry Form to the Laundry Area.',
            'info',
        ],
        (bool) $custody->released_at => [
            'Items are currently on your custody',
            'Return all outstanding non-linen items to SPMU on or before the expected return date.',
            'info',
        ],
        $pickupMissed => [
            'Pickup Missed — For SPMU Review',
            'Your scheduled pickup was not completed. SPMU is reviewing whether the items can still be released or whether the borrowing must be cancelled. No action is required from you at this time.',
            'warning',
        ],
        $preparationComplete && $pickupWindowOpen => [
            'Ready for physical release',
            'Your pickup window is open. Proceed to SPMU for the handover and Borrower Slip.',
            'info',
        ],
        $preparationComplete && $pickupWindowUpcoming => [
            'Pickup scheduled',
            'Physical release becomes available when your pickup window starts.',
            'info',
        ],
        $pickupWindowPassed => [
            'Pickup window ended',
            'Coordinate with SPMU for a new pickup schedule.',
            'warning',
        ],
        $hasPickupSchedule => [
            'Pickup scheduled - preparation in progress',
            'SPMU is preparing your approved items. No action is required from you.',
            'info',
        ],
        default => [
            'Waiting for pickup scheduling',
            'SPMU will schedule your pickup after approval.',
            'info',
        ],
    };
@endphp

@if($useReleaseProcessLayout)
    @include('custody.partials.release-process-styles')
    <div class="release-flow-page" data-release-process>
    @include('custody.partials.release-heading')
    @include('requests.partials.processing-time-estimate', ['borrowingRequest' => $custody->request])
@elseif(! $useReturnProcessLayout)
<section class="page-heading">
    <div>
        @if($isBorrower)
            <a class="borrower-custody-back" href="{{ route('custody.index') }}">
                My Borrowings
            </a>
        @else
            <p class="eyebrow">
                {{ $isSpmuOfficer && $spmuMode === 'release'
                    ? 'Release transaction'
                    : ($isSpmuOfficer && $spmuMode === 'return' ? 'Return transaction' : 'Pickup / custody transaction') }}
            </p>
        @endif
        <h1>{{ $custody->custody_no }}</h1>
        <p>
            Request {{ $custody->request?->request_no }}
            @if(!$isBorrower && $custody->borrower)
                · {{ $custody->borrower->full_name }}
            @endif
        </p>
    </div>
    <x-status-badge
        :status="$operationalStatusKey"
        :label="$isBorrower && $pickupMissed
            ? 'Pickup Missed'
            : $operationalLabel"
    />
</section>
@endif

@if($isBorrower)
    @php
        $borrowerItemCount = $custody->lines->count();
        $borrowerCustodyLabel = $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody';
        $borrowerReleased = (bool) $custody->released_at;

        $borrowerLaundryComplete = $hasLaundryItem
            && $laundryJob
            && $laundryJob->status === 'LAUNDRY_COMPLETED';

        $borrowerLaundryLabel = match (true) {
            ! $hasLaundryItem => null,
            $borrowerLaundryComplete => 'Completed',
            $laundryJob !== null => 'In Progress',
            default => 'Applicable',
        };

        $borrowerGatePassComplete = $hasOffCampusItem
            && $custody->gatePass
            && $custody->gatePass->status === 'VERIFIED';
        $borrowerGatePassStatus = $custody->gatePass?->workflowStatus();
        $borrowerGatePassLabel = match (true) {
            ! $hasOffCampusItem => null,
            $borrowerGatePassStatus !== null => $borrowerGatePassStatus['label'],
            default => 'Applicable',
        };

        /*
         * The one date that matters right now, appended to the status line so
         * the borrower does not have to read the summary grid to find it.
         */
        [$borrowerStateFactLabel, $borrowerStateFactValue] = match (true) {
            // Do not repeat a past expected-return date once physical return is complete.
            $gatePassOnlyPending => [null, null],
            $transactionCancelled && (bool) $custody->closed_at
                => ['Cancelled', $custody->closed_at->format('d M Y')],
            (bool) $custody->closed_at
                => ['Closed', $custody->closed_at->format('d M Y')],
            $custody->status === 'OVERDUE' && $returnDate
                => ['Was due', $returnDate->format('d M Y')],
            $borrowerReleased && $returnDate
                => [$returnDateAdjusted ? 'Effective return' : 'Expected return', $returnDate->format('d M Y')],
            $pickupWindowOpen && $pickupWindowEndsAt
                => ['Pickup window closes', $pickupWindowEndsAt->format('d M Y, g:i A')],
            $pickupWindowUpcoming && $pickupWindowStartsAt
                => ['Pickup opens', $pickupWindowStartsAt->format('d M Y, g:i A')],
            default => [null, null],
        };

        /*
         * Facts are assembled rather than hard-coded into cells so the grid
         * never carries a row that says nothing - "Completed: not completed
         * yet" while the status already reads "on your custody", for example.
         */
        $borrowerFacts = [];

        $borrowerFacts[] = [
            'Scheduled Use',
            $scheduleDate?->format('d M Y') ?: 'Not available',
            null,
        ];

        $borrowerReturnNote = null;
        if ($returnDateAdjusted) {
            $borrowerReturnNote = 'Adjusted from '.$originalReturnDate?->format('d M Y');

            if ($custody->due_adjustment_reason) {
                $borrowerReturnNote .= ' · '.$custody->due_adjustment_reason;
            }
        }

        $borrowerFacts[] = [
            $returnDateAdjusted ? 'Effective Return' : 'Expected Return',
            ($returnDateAdjusted ? $returnDate : $originalReturnDate)?->format('d M Y') ?: 'Not available',
            $borrowerReturnNote,
        ];

        $borrowerFacts[] = $borrowerReleased
            ? ['Released', $custody->released_at->format('d M Y, g:i A'), null]
            : ['Pickup', optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled', null];

        if ($borrowerReleased) {
            $borrowerFacts[] = [
                $borrowerCustodyLabel,
                $outstandingTotal + 0,
                $outstandingTotal > 0 ? 'Still to be returned' : null,
            ];
        }

        $borrowerFacts[] = [
            'Use Location',
            $hasOffCampusItem ? 'Includes off-campus use' : 'On-campus only',
            null,
        ];

        $borrowerFacts[] = [
            'Item Types',
            $borrowerItemCount.' '.($borrowerItemCount === 1 ? 'item' : 'items'),
            null,
        ];

        if ($custody->closed_at) {
            $borrowerFacts[] = [
                $transactionCancelled ? 'Cancelled' : 'Closed',
                $custody->closed_at->format('d M Y, g:i A'),
                null,
            ];
        }
            /* BORROWER TRANSACTION TIMELINE DATA START */

        /*
         * Borrower-facing custody history.
         *
         * Only events that actually exist for this borrowing are shown.
         * Request submission / approval history stays under My Requests.
         * Administrative Audit Trail remains separate.
         */
        $borrowerTimeline = collect();

        $addBorrowerTimelineEvent = function (
            string $title,
            mixed $occurredAt,
            ?string $detail = null,
            int $sortOrder = 100
        ) use ($borrowerTimeline): void {
            if (! $occurredAt) {
                return;
            }

            $occurredAt = $occurredAt instanceof \Illuminate\Support\Carbon
                ? $occurredAt
                : \Illuminate\Support\Carbon::parse($occurredAt);

            $borrowerTimeline->push([
                'title' => $title,
                'detail' => $detail,
                'occurred_at' => $occurredAt,
                'sort_order' => $sortOrder,
            ]);
        };

        /*
         |--------------------------------------------------------------------------
         | 1. Custody record
         |--------------------------------------------------------------------------
         */

        $addBorrowerTimelineEvent(
            'Borrowing record opened',
            $custody->created_at,
            'Custody '.$custody->custody_no.' was created for the approved request.',
            10
        );

        /*
         |--------------------------------------------------------------------------
         | 2. Pickup scheduling
         |--------------------------------------------------------------------------
         */

        if ($custody->pickup_scheduled_at) {
            $pickupDetail = $custody->scheduled_release_at
                ? 'Pickup scheduled for '.$custody->scheduled_release_at->format('d M Y, g:i A').'.'
                : 'The pickup schedule was recorded by SPMU.';

            $addBorrowerTimelineEvent(
                'Pickup scheduled',
                $custody->pickup_scheduled_at,
                $pickupDetail,
                20
            );
        }

        /*
         * When the pickup window expires before actual release, show the
         * operational event instead of pretending release took place.
         */
        if ($custody->pickup_expired_at && ! $custody->released_at) {
            $addBorrowerTimelineEvent(
                'Pickup window expired',
                $custody->pickup_expired_at,
                'The scheduled pickup window ended before the items were released.',
                25
            );
        }

        /*
         |--------------------------------------------------------------------------
         | 3. Item preparation and physical release
         |--------------------------------------------------------------------------
         */

        $addBorrowerTimelineEvent(
            'Items prepared',
            $custody->prepared_at,
            'The approved items were prepared for physical release.',
            30
        );

        $addBorrowerTimelineEvent(
            'Items released',
            $custody->released_at,
            'The issued property was handed over and placed under your custody.',
            40
        );

        /*
         |--------------------------------------------------------------------------
         | 4. Gate Pass - only when actually applicable/recorded
         |--------------------------------------------------------------------------
         */

        if ($custody->gatePass?->guard_signed_at) {
            $addBorrowerTimelineEvent(
                'Gate Pass recorded',
                $custody->gatePass->guard_signed_at,
                'The applicable off-campus exit was recorded on the Gate Pass.',
                45
            );
        }

        /*
         |--------------------------------------------------------------------------
         | 5. Early-return workflow - only when records exist
         |--------------------------------------------------------------------------
         */

        foreach (($custody->earlyReturnRequests ?? collect()) as $earlyReturn) {
            $earlyStatus = strtoupper((string) ($earlyReturn->status ?? ''));

            $earlyRequestedAt =
                $earlyReturn->requested_at
                ?? $earlyReturn->submitted_at
                ?? $earlyReturn->created_at;

            $addBorrowerTimelineEvent(
                'Early return requested',
                $earlyRequestedAt,
                'A request to return the borrowed items before the expected return schedule was recorded.',
                50
            );

            if (in_array($earlyStatus, ['APPROVED', 'CONFIRMED', 'ACCEPTED'], true)) {
                $earlyConfirmedAt =
                    $earlyReturn->confirmed_at
                    ?? $earlyReturn->approved_at
                    ?? $earlyReturn->decided_at
                    ?? null;

                $addBorrowerTimelineEvent(
                    'Early return confirmed',
                    $earlyConfirmedAt,
                    'SPMU confirmed that the return may proceed before the original expected return schedule.',
                    51
                );
            }

            if (in_array($earlyStatus, ['REJECTED', 'DECLINED'], true)) {
                $earlyDeclinedAt =
                    $earlyReturn->rejected_at
                    ?? $earlyReturn->declined_at
                    ?? $earlyReturn->decided_at
                    ?? null;

                $addBorrowerTimelineEvent(
                    'Early return request declined',
                    $earlyDeclinedAt,
                    'The original expected return schedule remained applicable.',
                    51
                );
            }
        }

        /*
         |--------------------------------------------------------------------------
         | 6. Returns
         |
         | Distinguish partial return from completion using the quantities
         | actually released and actually received.
         |--------------------------------------------------------------------------
         */

        $totalReleasedQuantity = (float) ($custody->lines ?? collect())
            ->sum(fn ($line) => (float) ($line->actual_released_quantity ?? 0));

        $cumulativeReturnedQuantity = 0.0;

        foreach (($custody->returns ?? collect())->sortBy('received_at') as $returnTransaction) {
            if (! $returnTransaction->received_at) {
                continue;
            }

            $returnQuantity = (float) ($returnTransaction->lines ?? collect())
                ->sum(fn ($line) => (float) ($line->quantity_received ?? 0));

            $cumulativeReturnedQuantity += $returnQuantity;

            $remainingQuantity = max(
                0,
                $totalReleasedQuantity - $cumulativeReturnedQuantity
            );

            if (
                $totalReleasedQuantity > 0
                && $cumulativeReturnedQuantity < $totalReleasedQuantity
            ) {
                $returnTitle = 'Partial return received';

                $returnDetail =
                    ($returnTransaction->return_no
                        ? 'Return '.$returnTransaction->return_no.' was recorded. '
                        : '')
                    .'Received quantity: '.($returnQuantity + 0)
                    .'. Remaining under custody: '.($remainingQuantity + 0).'.';
            } elseif ($totalReleasedQuantity > 0) {
                $returnTitle = 'All issued items returned';

                $returnDetail =
                    ($returnTransaction->return_no
                        ? 'Return '.$returnTransaction->return_no.' was recorded. '
                        : '')
                    .'The complete issued quantity has been physically returned.';
            } else {
                $returnTitle = 'Return received';

                $returnDetail =
                    $returnTransaction->return_no
                        ? 'Return '.$returnTransaction->return_no.' was recorded.'
                        : 'The physical return was recorded.';
            }

            $addBorrowerTimelineEvent(
                $returnTitle,
                $returnTransaction->received_at,
                $returnDetail,
                60
            );
        }

        /*
         |--------------------------------------------------------------------------
         | 7. Overdue return
         |--------------------------------------------------------------------------
         */

        $overdueCase = $custody->overdueCase;

        if ($overdueCase?->overdue_started_at) {
            $addBorrowerTimelineEvent(
                'Return became overdue',
                $overdueCase->overdue_started_at,
                'The borrowing passed its expected return schedule and entered overdue monitoring.',
                70
            );
        }

        if ($overdueCase?->ao_confirmed_at) {
            $addBorrowerTimelineEvent(
                'Late-return assessment confirmed',
                $overdueCase->ao_confirmed_at,
                'The SPMU Action Officer confirmed the recorded late-return assessment.',
                71
            );
        }

        if ($overdueCase?->resolved_at) {
            $addBorrowerTimelineEvent(
                'Overdue obligation resolved',
                $overdueCase->resolved_at,
                'The overdue-return obligation linked to this borrowing was resolved.',
                79
            );
        }

        /*
         |--------------------------------------------------------------------------
         | 8. Property accountability
         |
         | Damaged, missing, lost, stolen, destroyed, or another recorded
         | adverse finding appears only if an Incident actually exists.
         |--------------------------------------------------------------------------
         */

        foreach (($custody->incidents ?? collect())->sortBy('reported_at') as $incident) {
            $incidentType = strtoupper(trim((string) ($incident->incident_type ?? '')));

            $incidentTitle = match ($incidentType) {
                'DAMAGED', 'DAMAGE' =>
                    'Damaged item recorded',

                'MISSING' =>
                    'Missing item recorded',

                'LOST', 'LOSS' =>
                    'Lost item recorded',

                'STOLEN' =>
                    'Stolen item recorded',

                'DESTROYED' =>
                    'Destroyed item recorded',

                default =>
                    'Property accountability case opened',
            };

            $incidentReference = $incident->incident_no
                ? ' Case '.$incident->incident_no.'.'
                : '';

            $incidentDetail = match ($incidentType) {
                'DAMAGED', 'DAMAGE' =>
                    'A returned item was recorded with a damage finding and requires SPMU accountability review.'
                    .$incidentReference,

                'MISSING' =>
                    'An issued item was recorded as missing and requires SPMU accountability review.'
                    .$incidentReference,

                'LOST', 'LOSS' =>
                    'An issued item was recorded as lost and requires SPMU accountability review.'
                    .$incidentReference,

                'STOLEN' =>
                    'An issued item was recorded as stolen and requires SPMU accountability review.'
                    .$incidentReference,

                'DESTROYED' =>
                    'An issued item was recorded as destroyed and requires SPMU accountability review.'
                    .$incidentReference,

                default =>
                    'A property accountability finding was recorded for this borrowing.'
                    .$incidentReference,
            };

            $addBorrowerTimelineEvent(
                $incidentTitle,
                $incident->reported_at ?? $incident->created_at,
                $incidentDetail,
                80
            );

            /*
             * Head/Admin decision exists as a separate lifecycle event.
             */
            if ($incident->head_decided_at) {
                $addBorrowerTimelineEvent(
                    'SPMU accountability decision recorded',
                    $incident->head_decided_at,
                    'The SPMU Head/Admin recorded a decision for'
                    .($incident->incident_no ? ' case '.$incident->incident_no : ' the property case')
                    .'.',
                    81
                );
            }

            /*
             * Show the approved disposition only if one was actually recorded.
             */
            if ($incident->official_disposition_recorded_at) {
                $dispositionLabel = trim(
                    str_replace(
                        '_',
                        ' ',
                        (string) ($incident->official_disposition ?? 'required action')
                    )
                );

                $addBorrowerTimelineEvent(
                    'Property resolution requirement recorded',
                    $incident->official_disposition_recorded_at,
                    'SPMU recorded the required resolution action: '
                    .ucwords(strtolower($dispositionLabel))
                    .'.',
                    82
                );
            }

            /*
             * Action Officer physical/compliance verification.
             */
            if ($incident->compliance_verified_at) {
                $addBorrowerTimelineEvent(
                    'Property compliance verified',
                    $incident->compliance_verified_at,
                    'The SPMU Action Officer verified completion of the required property action.',
                    83
                );
            }

            /*
             * Final property case resolution.
             */
            if ($incident->resolved_at) {
                $addBorrowerTimelineEvent(
                    'Property accountability case resolved',
                    $incident->resolved_at,
                    'The property accountability case'
                    .($incident->incident_no ? ' '.$incident->incident_no : '')
                    .' was formally resolved.',
                    89
                );
            }
        }

        /*
         |--------------------------------------------------------------------------
         | 9. Laundry - linen transactions only
         |--------------------------------------------------------------------------
         */

        if ($laundryJob?->worker_received_at) {
            $addBorrowerTimelineEvent(
                'Linen received by Laundry',
                $laundryJob->worker_received_at,
                'The applicable linen and Laundry Form were physically received for processing.',
                90
            );
        }

        if ($laundryJob?->completed_at) {
            $addBorrowerTimelineEvent(
                'Laundry processing completed',
                $laundryJob->completed_at,
                'The completed Laundry Form and linen-return processing were reconciled.',
                91
            );
        }

        /*
         |--------------------------------------------------------------------------
         | 10. Final transaction outcome
         |--------------------------------------------------------------------------
         */

        if ($custody->closed_at) {
            if ($transactionCancelled) {
                $addBorrowerTimelineEvent(
                    'Borrowing cancelled',
                    $custody->closed_at,
                    $custody->released_at
                        ? 'The borrowing transaction was closed after its recorded custody activity.'
                        : 'The borrowing transaction was closed without physical release.',
                    1000
                );
            } else {
                $addBorrowerTimelineEvent(
                    'Borrowing completed',
                    $custody->closed_at,
                    'All applicable return, document, and accountability requirements were reconciled and the borrowing was closed.',
                    1000
                );
            }
        }

        /*
         |--------------------------------------------------------------------------
         | Chronological order
         |
         | sort_order only resolves events sharing the exact same timestamp.
         |--------------------------------------------------------------------------
         */

        $borrowerTimeline = $borrowerTimeline
            ->sort(function (array $left, array $right): int {
                $timeComparison =
                    $left['occurred_at']->timestamp
                    <=> $right['occurred_at']->timestamp;

                if ($timeComparison !== 0) {
                    return $timeComparison;
                }

                return ($left['sort_order'] ?? 100)
                    <=> ($right['sort_order'] ?? 100);
            })
            ->values();

/* BORROWER TRANSACTION TIMELINE DATA END */
        /* BORROWER CUSTODY DOCUMENT DATA START */

        /*
         * Transaction forms belong to the custody/borrowing lifecycle.
         * Keep My Requests focused on request-submission documents.
         */
        $borrowerSlipDocument = \App\Models\GeneratedDocument::query()
            ->where('subject_type', \App\Models\CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'FINAL')
            ->latest('id')
            ->first();

        /* BORROWER CUSTODY DOCUMENT DATA END */
@endphp

    @include('custody.partials.borrower-custody-styles')

    <section class="content-area">
        <div class="borrower-custody-stack">

            {{-- Current state --}}
            <div class="borrower-custody-status is-{{ $borrowerStateTone }}">
                <span class="borrower-custody-status-icon" aria-hidden="true">
                    <x-icon name="information" size="24" />
                </span>

                <div class="borrower-custody-status-copy">
                    <h2>{{ $borrowerStateTitle }}</h2>
                    <p>{{ $borrowerStateCopy }}</p>
                </div>

                @if($borrowerStateFactLabel)
                    <div class="borrower-custody-status-fact">
                        <small>{{ $borrowerStateFactLabel }}</small>
                        <strong>
                            <x-icon name="calendar" size="17" />
                            {{ $borrowerStateFactValue }}
                        </strong>
                    </div>
                @endif

                <div class="borrower-custody-status-actions">
                    @if($accountabilityIndicator)
                        <a
                            class="button secondary small ui-pressable borrower-custody-status-action"
                            href="{{ route('accountability.index') }}"
                        >
                            View Obligation
                        </a>
                    @elseif(! $pickupMissed)
                        <a
                            class="button secondary small ui-pressable borrower-custody-status-action"
                            href="{{ route('requests.show', $custody->request) }}"
                        >
                            View Request
                        </a>
                    @endif
                </div>
            </div>

            {{-- Transaction facts --}}
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Borrowing summary</p>
                        <h2>{{ $version?->purpose_event ?: 'Borrowing details' }}</h2>
                    </div>
                </div>

                <div class="borrower-summary-grid">
                    @foreach($borrowerFacts as [$factLabel, $factValue, $factNote])
                        <div class="borrower-summary-fact">
                            <small>{{ $factLabel }}</small>
                            <strong>{{ $factValue }}</strong>
                            @if($factNote)
                                <span>{{ $factNote }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>

            {{-- Items --}}
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Borrowed items</p>
                        <h2>{{ $borrowerReleased ? 'Issued and returned quantities' : ($transactionCancelled ? 'Approved items — cancelled' : 'Approved items for pickup') }}</h2>
                    </div>
                    <span class="borrower-section-note">
                        {{ $borrowerItemCount }} {{ $borrowerItemCount === 1 ? 'item' : 'items' }}
                    </span>
                </div>

                <div class="table-wrap">
                    <table class="borrower-items-table">
                        <thead>
                            @if($borrowerReleased)
                                <tr>
                                    <th>Item</th>
                                    <th>Issued</th>
                                    <th>Returned</th>
                                    <th>{{ $borrowerCustodyLabel }}</th>
                                </tr>
                            @else
                                <tr>
                                    <th>Item</th>
                                    <th>Approved</th>
                                    <th>Status</th>
                                </tr>
                            @endif
                        </thead>
                        <tbody>
                            @foreach($custody->lines as $line)
                                @php
                                    $lineOnCustody = max(
                                        0,
                                        (float) $line->actual_released_quantity - (float) $line->returned_quantity
                                    );
                                @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                        <small>{{ $line->requestItem->unit_snapshot }}</small>
                                    </td>
                                    @if($borrowerReleased)
                                        <td>{{ $line->actual_released_quantity + 0 }}</td>
                                        <td>{{ $line->returned_quantity + 0 }}</td>
                                        <td class="is-quantity"><strong>{{ $lineOnCustody + 0 }}</strong></td>
                                    @else
                                        <td>{{ $line->approved_quantity + 0 }}</td>
                                        <td class="is-muted">{{ $transactionCancelled ? 'Not issued — cancelled' : 'Not issued yet' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>


{{-- Processing that continues outside the borrower's hands --}}
            @if($borrowerSlipDocument || $hasLaundryItem || $hasOffCampusItem)
                <article class="card borrower-document-card">
                    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; gap:16px;">
    <div class="borrower-document-heading"
         style="display:inline-flex; align-items:center; justify-content:flex-start; gap:10px; min-width:0; text-align:left;">

        <span class="borrower-card-icon"
              aria-hidden="true"
              style="display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; margin:0;">
            <x-icon name="requests" size="21" />
        </span>

        <span class="borrower-document-left-title"
              style="display:inline-block; position:static; margin:0; font-size:20px; font-weight:700; line-height:1.25; text-align:left;">
            Transaction Documents
        </span>
    </div>
                        <div>
                            <p class="eyebrow">Document processing</p>
                            <h2>Applicable records</h2>
                        </div>
                    </div>

                    {{-- BORROWER SLIP DOCUMENT ROW START --}}
                    @if($borrowerSlipDocument)
                        <div class="borrower-processing-row">
                            <div class="borrower-processing-copy">
                                <strong>Borrower Slip</strong>
                                <small>
                                    Official record of the items issued under this borrowing transaction.
                                </small>
                            </div>

                            <span class="status-badge status-success">
                                Available
                            </span>

                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('documents.preview', $borrowerSlipDocument) }}"
                            >
                                Preview
                            </a>
                        </div>
                    @endif
                    {{-- BORROWER SLIP DOCUMENT ROW END --}}

@if($hasOffCampusItem)
                        <div class="borrower-processing-row">
                            <div class="borrower-processing-copy">
                                <strong>Gate Pass</strong>
                                <small>
                                    @if(($borrowerGatePassStatus['key'] ?? null) === 'VOID')
                                        Voided because the borrowing was cancelled.
                                    @elseif($borrowerGatePassComplete)
                                        Off-campus release recorded{{ $custody->gatePass?->guard_signed_at ? ' on '.$custody->gatePass->guard_signed_at->format('d M Y, g:i A') : '' }}.
                                    @elseif($borrowerReleased && $outstandingTotal <= 0)
                                        SPMU is completing the Gate Pass record.
                                    @else
                                        Required for off-campus release.
                                    @endif
                                </small>
                            </div>

                            <x-status-badge
                                :status="$borrowerGatePassStatus['key'] ?? ($borrowerGatePassComplete ? 'COMPLETED' : 'PREPARING_RELEASE')"
                                :label="$borrowerGatePassLabel"
                            />

                            @if($custody->gatePass?->accomplished_file_id)
                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route('files.preview', $custody->gatePass->accomplished_file_id, false) }}"
                                >
                                    Preview
                                </a>
                            @elseif($custody->gatePass?->passDocument && ($borrowerGatePassStatus['key'] ?? null) !== 'VOID')
                                {{-- The borrower is told to bring the generated Gate Pass, so it must be reachable before pickup. --}}
                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route('documents.preview', $custody->gatePass->passDocument) }}"
                                >
                                    Preview
                                </a>
                            @endif
                        </div>
                    @endif

@if($hasLaundryItem)
                        <div class="borrower-processing-row">
                            <div class="borrower-processing-copy">
                                <strong>Laundry Form</strong>
                                <small>
                                    {{ $borrowerLaundryComplete
                                        ? 'Complete. No further action is required.'
                                        : 'Handled internally by SPMU after the physical return.' }}
                                </small>
                            </div>

                            <x-status-badge
                                :status="$borrowerLaundryComplete ? 'COMPLETED' : 'RETURN_PROCESSING'"
                                :label="$borrowerLaundryLabel"
                            />

                            @if($laundryJob?->latestEvidence?->file)
                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route('files.preview', $laundryJob->latestEvidence->file, false) }}"
                                >
                                    Preview
                                </a>
                            @endif
                        </div>
                    @endif
                </article>
            @endif

            {{-- BORROWER TRANSACTION TIMELINE UI START --}}
            @if($borrowerTimeline->isNotEmpty())
                <details
                    class="card borrower-activity-card borrower-activity-disclosure"
                    @if(! $custody->closed_at) open @endif
                >
                    <summary
                        class="card-header borrower-activity-summary"
                        aria-label="Transaction timeline, {{ $borrowerTimeline->count() }} {{ $borrowerTimeline->count() === 1 ? 'event' : 'events' }}"
                    >
                        <div class="borrower-activity-summary-copy">
                            <p class="eyebrow">Borrowing activity</p>
                            <h2>Transaction timeline</h2>
                        </div>

                        <div class="borrower-activity-summary-side">
                            <span class="borrower-section-note">
                                {{ $borrowerTimeline->count() }}
                                {{ $borrowerTimeline->count() === 1 ? 'event' : 'events' }}
                            </span>

                            <span
                                class="borrower-activity-chevron"
                                aria-hidden="true"
                            >
                                <x-icon name="chevron-down" size="18" />
                            </span>
                        </div>
                    </summary>

                    <div class="borrower-activity-list" role="list">
                        @foreach($borrowerTimeline as $activity)
                            <div class="borrower-activity-row" role="listitem">
                                <span
                                    class="borrower-activity-marker"
                                    aria-hidden="true"
                                ></span>

                                <div class="borrower-activity-copy">
                                    <strong>
                                        {{ $activity['title'] }}
                                    </strong>

                                    @if($activity['detail'])
                                        <small>
                                            {{ $activity['detail'] }}
                                        </small>
                                    @endif
                                </div>

                                <time
                                    class="borrower-activity-time"
                                    datetime="{{ $activity['occurred_at']->toIso8601String() }}"
                                >
                                    {{ $activity['occurred_at']->format('d M Y, g:i A') }}
                                </time>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
            {{-- BORROWER TRANSACTION TIMELINE UI END --}}





        </div>
    </section>
@else
<style>
.workflow-focus-target { scroll-margin-top: 96px; }

[data-preparation-result].is-unchecked {
    color: var(--text-muted, #64748b);
}

[data-preparation-result].is-match {
    color: var(--success, #16794b);
}

[data-preparation-result].is-mismatch {
    color: var(--danger, #b42318);
}

.preparation-match-message {
    margin: 12px 0 0;
}
</style>
@if($showReleaseWorkflow)
@if($useReleaseProcessLayout)
    @include('custody.partials.release-process')
@else
    <x-request-progress-tracker :request="$custody->request" :show-current-status="false" />

<section class="content-grid two">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Approved request summary</p>
                <h2>Borrowing schedule</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Purpose / Event</dt>
            <dd>{{ $version?->purpose_event ?: '—' }}</dd>

            <dt>Schedule Date</dt>
            <dd>{{ $scheduleDate?->format('d F Y') ?: 'Not available' }}</dd>

            <dt>Expected Return Date</dt>
            <dd>{{ $returnDate?->format('d F Y') ?: 'Not available' }}</dd>
        </dl>
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Operational context</p>
                <h2>{{ $custody->released_at ? 'Release & return requirements' : 'Before physical release' }}</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Use Location</dt>
            <dd>{{ $hasOffCampusItem ? 'Off-campus item included' : 'On-campus only' }}</dd>

            <dt>Gate Pass</dt>
            <dd>{{ $hasOffCampusItem ? 'Required before off-campus exit' : 'Not required' }}</dd>

            <dt>Laundry Form</dt>
            <dd>{{ $hasLaundryItem ? 'Required for applicable linen' : 'Not required' }}</dd>

        </dl>
    </article>
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Property record</p>
                <h2>{{ $custody->released_at ? 'Issued and returned quantities' : 'Approved and issued quantities' }}</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Approved Quantity</th>
                        <th>Issued</th>
                        <th>Returned</th>
                        <th>Outstanding</th>
                        <th>Release Condition</th>
                        <th>Return Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($custody->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->approved_quantity + 0 }}</td>
                            <td>{{ $line->actual_released_quantity + 0 }}</td>
                            <td>{{ $line->returned_quantity + 0 }}</td>
                            <td>
                                {{ max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity) + 0 }}
                            </td>
                            <td>{{ $line->release_condition ? str($line->release_condition)->replace('_', ' ')->lower()->title() : '—' }}</td>
                            <td>{{ $returnFindingsByCustodyLine->get($line->id) ?: ((float) $line->returned_quantity > 0 ? 'Recorded' : '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>

@if($isSpmuHead && collect($preparationIssues ?? [])->isNotEmpty() && ! $custody->released_at)
<section class="content-area" id="preparation-issues">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Item preparation</p>
                <h2>Inventory Discrepancy Review</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Reported Discrepancy</th>
                        <th>Physical Observation</th>
                        <th>Reported By</th>
                        <th>Status / Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($preparationIssues as $issue)
                        <tr>
                            <td>
                                <strong>{{ $issue['item_name'] }}</strong>
                                <small>{{ $issue['approved_quantity'] + 0 }} {{ $issue['unit'] }} approved</small>
                            </td>
                            <td>
                                <strong>{{ $issue['issue_label'] }}</strong>
                                @if(!empty($issue['condition_observed']))
                                    <small>{{ $issue['condition_observed'] }}</small>
                                @endif
                                @if(!empty($issue['details']))
                                    <small>{{ $issue['details'] }}</small>
                                @endif
                            </td>
                            <td>
                                @if($issue['observed_usable_quantity'] !== null)
                                    {{ $issue['observed_usable_quantity'] + 0 }} of {{ $issue['approved_quantity'] + 0 }} {{ $issue['unit'] }} physically ready
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                {{ $issue['reported_by'] }}
                                <small>{{ $issue['reported_at']?->format('d M Y, g:i A') }}</small>
                            </td>
                            <td>
                                @if($issue['is_resolved'])
                                    @if($issue['resolution_outcome'] === 'UNABLE_TO_FULFILL_APPROVED_REQUEST')
                                        <strong>Unable to Fulfill — Request Cancelled</strong>
                                        @if(!empty($issue['resolution_notes']))
                                            <small>{{ $issue['resolution_notes'] }}</small>
                                        @endif
                                        <small>No borrower missed-pickup record was created.</small>
                                    @else
                                        <strong>Inventory Reviewed — AO Recheck Required</strong>
                                        @if(!empty($issue['resolution_notes']))
                                            <small>{{ $issue['resolution_notes'] }}</small>
                                        @endif

                                        @if($pickupWindowPassed && ! $preparationComplete && $custody->status === 'PREPARING_RELEASE')
                                            <form
                                                method="post"
                                                action="{{ route('custody.resolve-preparation-issue', [$custody, $issue['event']]) }}"
                                                class="form-grid"
                                            >
                                                @csrf
                                                <input type="hidden" name="resolution_type" value="UNABLE_TO_FULFILL_APPROVED_REQUEST">
                                                <label>
                                                    Reason
                                                    <textarea
                                                        name="resolution_notes"
                                                        maxlength="1000"
                                                        required
                                                        placeholder="State why the complete approved quantity can no longer be released under the approved pickup schedule."
                                                    ></textarea>
                                                </label>
                                                <label class="checkbox-line">
                                                    <input type="checkbox" name="confirm_unable_to_fulfill" value="1" required>
                                                    <span>I confirm that the approved release can no longer proceed and partial release is not allowed.</span>
                                                </label>
                                                <button class="button danger ui-pressable" type="submit">
                                                    Unable to Fulfill Approved Request
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                    @if($issue['resolved_at'])
                                        <small>{{ $issue['resolved_at']->format('d M Y, g:i A') }}</small>
                                    @endif
                                @else
                                    @if($issue['inventory_item_id'])
                                        <a
                                            class="button secondary small ui-pressable"
                                            href="{{ route('inventory.show', $issue['inventory_item_id']) }}"
                                        >
                                            Review Inventory
                                        </a>
                                    @endif

                                    @if($pickupWindowPassed)
                                        <form
                                            method="post"
                                            action="{{ route('custody.resolve-preparation-issue', [$custody, $issue['event']]) }}"
                                            class="form-grid"
                                        >
                                            @csrf
                                            <input type="hidden" name="resolution_type" value="UNABLE_TO_FULFILL_APPROVED_REQUEST">
                                            <label>
                                                Reason
                                                <textarea
                                                    name="resolution_notes"
                                                    maxlength="1000"
                                                    required
                                                    placeholder="State why SPMU cannot provide the complete approved quantity."
                                                ></textarea>
                                            </label>
                                            <label class="checkbox-line">
                                                <input type="checkbox" name="confirm_unable_to_fulfill" value="1" required>
                                                <span>I confirm that the complete approved quantity cannot be provided and partial release is not allowed.</span>
                                            </label>
                                            <button class="button danger ui-pressable" type="submit">
                                                Unable to Fulfill Approved Request
                                            </button>
                                        </form>
                                    @else
                                        <form
                                            method="post"
                                            action="{{ route('custody.resolve-preparation-issue', [$custody, $issue['event']]) }}"
                                            class="form-grid"
                                            data-preparation-resolution-form
                                        >
                                            @csrf
                                            <label>
                                                Resolution
                                                <select name="resolution_type" required data-preparation-resolution-type>
                                                    <option value="INVENTORY_REVIEW_COMPLETE">Resolved — AO Recheck Required</option>
                                                    <option value="UNABLE_TO_FULFILL_APPROVED_REQUEST">Unable to Fulfill Approved Request</option>
                                                </select>
                                            </label>

                                            <label data-preparation-resolution-notes>
                                                <span data-preparation-resolution-notes-label>Remarks (Optional)</span>
                                                <textarea
                                                    name="resolution_notes"
                                                    maxlength="1000"
                                                    placeholder="Add a short note only if needed."
                                                    data-preparation-resolution-notes-input
                                                ></textarea>
                                            </label>

                                            <label class="checkbox-line" data-preparation-unfulfillable-confirmation hidden>
                                                <input type="checkbox" name="confirm_unable_to_fulfill" value="1" disabled>
                                                <span>I confirm that the complete approved quantity cannot be provided and partial release is not allowed.</span>
                                            </label>

                                            <button class="button primary ui-pressable" type="submit" data-preparation-resolution-submit>
                                                Confirm Inventory Reviewed
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
<script>
(() => {
    document.querySelectorAll('[data-preparation-resolution-form]').forEach((form) => {
        const type = form.querySelector('[data-preparation-resolution-type]');
        const notes = form.querySelector('[data-preparation-resolution-notes]');
        const notesLabel = form.querySelector('[data-preparation-resolution-notes-label]');
        const notesInput = form.querySelector('[data-preparation-resolution-notes-input]');
        const confirmation = form.querySelector('[data-preparation-unfulfillable-confirmation]');
        const confirmationInput = confirmation?.querySelector('input');
        const submit = form.querySelector('[data-preparation-resolution-submit]');

        const sync = () => {
            const unable = type?.value === 'UNABLE_TO_FULFILL_APPROVED_REQUEST';
            if (notesLabel) notesLabel.textContent = unable ? 'Reason' : 'Remarks (Optional)';
            if (notesInput) {
                notesInput.required = unable;
                notesInput.placeholder = unable
                    ? 'State why SPMU cannot provide the complete approved quantity.'
                    : 'Add a short note only if needed.';
            }
            if (confirmation) confirmation.hidden = !unable;
            if (confirmationInput) {
                confirmationInput.disabled = !unable;
                confirmationInput.required = unable;
            }
            if (submit) {
                submit.textContent = unable ? 'Unable to Fulfill Approved Request' : 'Confirm Inventory Reviewed';
                submit.classList.toggle('danger', unable);
                submit.classList.toggle('primary', !unable);
            }
        };

        type?.addEventListener('change', sync);
        sync();
    });
})();
</script>
@endif

@endif {{-- useReleaseProcessLayout --}}
@endif {{-- showReleaseWorkflow --}}

@if($showReturnWorkflow)
<div id="return-workflow" class="workflow-focus-target return-flow-page">

@if($isSpmuOfficer && $custody->released_at)
    @include('custody.partials.return-workspace')
@elseif($isSpmuOfficer)
    <section class="content-area">
        <div class="callout info">
            <strong>This transaction has not been physically released yet.</strong>
            <p>Complete the Release workflow before recording a return.</p>
        </div>
    </section>
@endif

</div>
@endif {{-- showReturnWorkflow --}}

@endif
@if($useReleaseProcessLayout)
    </div>
@endif
@endsection
