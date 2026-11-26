@props(['request', 'showCurrentStatus' => true, 'compact' => false, 'releaseView' => false])

@php
    $status = $request->status instanceof App\Enums\RequestStatus
        ? $request->status
        : App\Enums\RequestStatus::tryFrom((string) $request->status);

    $statusValue = $status?->value ?: strtoupper((string) $request->status);

    $custody = $request->custody;

    $custodyStatus = $custody
        ? (
            $custody->status instanceof \BackedEnum
                ? $custody->status->value
                : strtoupper((string) $custody->status)
        )
        : null;

    $laundry = $custody?->laundryJob;

    $laundryStatus = $laundry
        ? (
            $laundry->status instanceof \BackedEnum
                ? $laundry->status->value
                : strtoupper((string) $laundry->status)
        )
        : null;

    $returns = $custody?->returns ?? collect();
    $history = $request->statusHistory ?? collect();
    $approvalSteps = $request->currentVersion?->approvalSteps ?? collect();

    $verificationStep = $approvalSteps
        ->where('sequence_no', 1)
        ->first();

    $headDecisionStep = $approvalSteps
        ->where('sequence_no', 2)
        ->first();

    /*
     * Only Gate Pass / off-campus requests pass through Action Officer
     * verification before the SPMU Head / Admin decision. Ordinary on-campus
     * requests, including linen and non-linen requests, go directly to the
     * SPMU Head / Admin review after borrower submission.
     */
    $requiresActionOfficerVerification = (bool) ($request->currentVersion?->off_campus);

    $waitingForActionOfficer =
        $requiresActionOfficerVerification
        && $statusValue === 'UNDER_SPMU'
        && (
            ! $verificationStep
            || in_array($verificationStep->decision, ['PENDING', 'RECEIVED'], true)
        );

    $waitingForHead =
        $statusValue === 'UNDER_SPMU'
        && $headDecisionStep
        && in_array($headDecisionStep->decision, ['PENDING', 'RECEIVED'], true)
        && (
            ! $requiresActionOfficerVerification
            || $verificationStep?->decision === 'VERIFIED'
        );

    $historyAt = static function (array $statuses) use ($history) {
        return $history
            ->whereIn('to_status', $statuses)
            ->sortBy('changed_at')
            ->first()?->changed_at;
    };

    $isCancelled = $statusValue === 'CANCELLED' || $custodyStatus === 'CANCELLED';
    $cancellation = $isCancelled ? $request->latestCancellation : null;
    $cancellationAt = $cancellation?->cancelled_at
        ?: $historyAt(['CANCELLED'])
        ?: $custody?->closed_at;
    $cancellationReason = trim((string) ($cancellation?->reason ?? ''));
    $cancellationActorName = $cancellation?->requestedBy?->full_name;

    $formatDateTime = static fn ($value) =>
        $value
            ? $value->format($compact ? 'd M, g:i A' : 'd M Y, g:i A')
            : null;

    /*
    |--------------------------------------------------------------------------
    | Important dates
    |--------------------------------------------------------------------------
    */

    $submittedAt = $historyAt([
        'SUBMITTED',
        'UNDER_SPMU',
    ]) ?: $request->currentVersion?->submitted_at;

    $reviewStartedAt =
        $historyAt(['UNDER_SPMU'])
        ?: $submittedAt;

    $approvedAt = $request->final_approved_at;

    $pickupScheduledAt = $custody?->pickup_scheduled_at;

    $releasedAt = $custody?->released_at;

    $firstReturn = $returns
        ->sortBy(
            fn ($return) =>
                $return->received_at
                ?: $return->created_at
        )
        ->first();

    $returnStartedAt =
        $firstReturn?->received_at
        ?: $laundry?->worker_received_at;

    /*
    |--------------------------------------------------------------------------
    | Major workflow state
    |--------------------------------------------------------------------------
    */

    $isApproved =
        (bool) $approvedAt
        || in_array(
            $statusValue,
            [
                'FINAL_APPROVED_AWAITING_DOWNLOAD',
                'APPROVED_READY_FOR_RELEASE',
            ],
            true
        )
        || (bool) $custody;

    $hasActivePickupSchedule = (bool) (
        $custody?->scheduled_release_at
        && $custody?->pickup_scheduled_at
        && ! $custody?->pickup_expired_at
    );

    $preparationComplete =
        (bool) $custody?->prepared_at;

    $isReleased =
        (bool) $releasedAt;

    $outstandingLines = $custody?->lines?->filter(
        fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
    ) ?? collect();
    $hasOutstandingLinen = $outstandingLines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );
    $hasOutstandingNonLinen = $outstandingLines->contains(
        fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    // A closed custody row can still have outstanding documents or obligations.
    $isClosed = ! $isCancelled
        && ! in_array($statusValue, ['REJECTED', 'EXPIRED'], true)
        && $custody?->workflowStatus()['key'] === 'COMPLETED';
    $completedAt = $isClosed ? $custody?->closed_at : null;

    /*
     * Keep accountability requirements within the standard Return Processing
     * point. Physical return alone does not certify transaction completion.
     */
    $activeAccountability = $custody?->activeAccountabilityIndicator();
    $obligationSummary = $activeAccountability ? $custody?->openObligationSummary() : null;
    $physicalReturnComplete = $isReleased
        && (bool) $returnStartedAt
        && $outstandingLines->isEmpty();
    $showAccountabilityStage = $physicalReturnComplete
        && $activeAccountability !== null
        && ! $isClosed;

    $pickupExpired =
        (bool) $custody?->pickup_expired_at
        && ! $releasedAt
        && ! $isCancelled;

    $terminal = $isCancelled || in_array(
        $statusValue,
        ['REJECTED', 'EXPIRED'],
        true
    );

    $returnedForRevision =
        $statusValue === 'RETURNED_FOR_REVISION';

    /*
    |--------------------------------------------------------------------------
    | Current major milestone
    |--------------------------------------------------------------------------
    |
    | Normal on-campus request:
    | Submitted -> SPMU Head / Admin Review -> Pickup -> Release
    |
    | Gate Pass / off-campus request:
    | Submitted -> Action Officer Verification ->
    | SPMU Head / Admin Review -> Pickup -> Release
    |
    */

    $actionOfficerStepIndex = 3;
    $headReviewStepIndex = 4;
    $pickupStepIndex = 5;
    $releaseStepIndex = 6;
    $returnStepIndex = 7;
    $completedStepIndex = 8;

    $reviewStepIndex = match (true) {
        $requiresActionOfficerVerification
            && $verificationStep?->decision === 'RETURNED_FOR_REVISION'
            => $actionOfficerStepIndex,

        $waitingForActionOfficer
            => $actionOfficerStepIndex,

        default
            => $headReviewStepIndex,
    };

    $currentIndex = match (true) {
        $isClosed => $completedStepIndex,

        $showAccountabilityStage => $returnStepIndex,

        $custodyStatus === 'CLOSED' => $returnStepIndex,

        $isReleased => $returnStepIndex,

        $hasActivePickupSchedule => $releaseStepIndex,

        $isApproved => $pickupStepIndex,

        in_array(
            $statusValue,
            [
                'SUBMITTED',
                'UNDER_SPMU',
                'UNDER_GSU',
                'UNDER_VPAF',
                'RETURNED_FOR_REVISION',
                'REJECTED',
            ],
            true
        ) => $reviewStepIndex,

        $statusValue === 'SIGNED' => 2,

        default => 1,
    };

    if ($pickupExpired) {
        $currentIndex = $pickupStepIndex;
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic Return / Laundry description
    |--------------------------------------------------------------------------
    */

    $returnDescription =
        'SPMU will inspect the items upon return.';

    /*
     * Completed transaction:
     *
     * Never show waiting/in-progress wording.
     */
    if ($isClosed) {
        $returnDescription = $laundry
            ? 'SPMU has checked the returned items and the completed Laundry Form.'
            : 'SPMU has completed the return inspection.';
    }

    /*
     * Released but not yet completed.
     */
    elseif ($isReleased) {

        /*
         * Mixed / linen borrowing.
         */
        if ($laundry) {
            $returnDescription = match ($laundryStatus) {

                'FOR_LAUNDRY'
                    => $laundry->hasVerifiedAccomplishedForm()
                        ? ($hasOutstandingNonLinen
                            ? 'The Laundry Form has been recorded. Return all outstanding non-linen items together to SPMU.'
                            : 'SPMU is recording the linen return using the completed Laundry Form.')
                        : ($hasOutstandingNonLinen
                            ? 'Return all outstanding non-linen items together to SPMU and all outstanding linen with the printed Laundry Form to the Laundry Area.'
                            : 'Return all outstanding linen together with the printed Laundry Form to the Laundry Area.'),

                'TURNED_OVER_TO_LAUNDRY'
                    => 'SPMU is reviewing the linen return record.',

                'LAUNDRY_COMPLETED'
                    => 'The completed Laundry Form has been recorded. Serviceable linen is available.',

                'IN_PROCESS', 'READY_FOR_SPMU_RETURN', 'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'
                    => 'SPMU is reviewing the existing Laundry Form and linen return record.',

                default
                    => 'The linen return is being processed.',
            };
        }

        /*
         * Ordinary non-linen return.
         */
        elseif ($firstReturn) {
            $returnDescription = match ($custodyStatus) {

                'RETURN_PROCESSING'
                    => 'SPMU is checking the returned items and completing the return record.',

                'OBLIGATION_OPEN'
                    => 'The items have been inspected. Outstanding requirements must still be settled.',

                'OVERDUE'
                    => 'The return is overdue and remains under SPMU review.',

                default
                    => 'SPMU is completing the inspection of the returned items.',
            };
        }

        /*
         * Still on custody.
         */
        elseif ($custody?->due_at) {
            $returnDescription = now()->greaterThan($custody->due_at)
                ? ($hasOutstandingLinen && $hasOutstandingNonLinen
                    ? 'The return was due on '.$custody->due_at->format('d M Y, g:i A').'. Return all non-linen items to SPMU and all linen with the Laundry Form to the Laundry Area immediately.'
                    : ($hasOutstandingLinen
                        ? 'The linen return was due on '.$custody->due_at->format('d M Y, g:i A').'. Return all linen with the Laundry Form to the Laundry Area immediately.'
                        : 'The return was due on '.$custody->due_at->format('d M Y, g:i A').'. Return all outstanding items to SPMU immediately.'))
                : ($hasOutstandingLinen && $hasOutstandingNonLinen
                    ? 'Return all non-linen items to SPMU and all linen with the Laundry Form to the Laundry Area by '.$custody->due_at->format('d M Y, g:i A').'.'
                    : ($hasOutstandingLinen
                        ? 'Return all linen with the Laundry Form to the Laundry Area by '.$custody->due_at->format('d M Y, g:i A').'.'
                        : 'Return all outstanding items to SPMU by '.$custody->due_at->format('d M Y, g:i A').'.'));
        }

        else {
            $returnDescription = $hasOutstandingLinen
                ? 'Return all linen with the Laundry Form to the Laundry Area and all non-linen items to SPMU.'
                : 'Return all outstanding items to SPMU.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tracker steps
    |--------------------------------------------------------------------------
    */

    $steps = [
        2 => [
            'label' => 'Submitted',
            'icon' => 'upload',
            'time' => $submittedAt,
            'description' =>
                $submittedAt
                    ? 'Borrowing request submitted to SPMU.'
                    : 'Waiting for borrower submission.',
        ],
    ];

    $steps[$actionOfficerStepIndex] = [
        'label' => 'Action Officer Verification',
        'icon' => 'eye',
        'not_required' => ! $requiresActionOfficerVerification,
        // Use only this review step's own event, never the submission time.
        'time' => $requiresActionOfficerVerification
            ? ($verificationStep?->decided_at ?: $verificationStep?->received_at)
            : null,
        'description' => match (true) {
            ! $requiresActionOfficerVerification
                => 'Not required for on-campus borrowing. The request goes directly to the SPMU Head / Admin.',
            $verificationStep?->decision === 'RETURNED_FOR_REVISION'
                => 'The Action Officer has requested corrections to the off-campus borrowing request.',

            $verificationStep?->decision === 'VERIFIED'
                => 'Request verified by the SPMU Action Officer.',

            $waitingForActionOfficer
                => 'The Action Officer will verify the off-campus borrowing request and its supporting documents.',

            default
                => 'Off-campus borrowing requests require verification by the Action Officer.',
        },
    ];
    $steps[$headReviewStepIndex] = [
        'label' => 'SPMU Head Review',
        'icon' => 'success',
        // No fallback to $reviewStartedAt here: until the Head/Admin has
        // actually received or decided on this step, it has not happened
        // yet, so this must stay Pending rather than showing the (earlier)
        // submission time as if it were this step's time.
        'time' => $headDecisionStep?->decided_at
            ?: $approvedAt
            ?: $headDecisionStep?->received_at,
        'description' => match (true) {
            $headDecisionStep?->decision === 'RETURNED_FOR_REVISION'
                => 'Request returned for revision by the SPMU Head / Admin.',

            $statusValue === 'REJECTED'
                => 'Request rejected by the SPMU Head / Admin.',

            $isApproved
                => 'Request approved by the SPMU Head / Admin.',

            $waitingForHead && $requiresActionOfficerVerification
                => 'The verified off-campus request is awaiting final review by the SPMU Head.',

            $waitingForHead
                => 'The request is awaiting review by the SPMU Head.',

            $waitingForActionOfficer
                => 'The SPMU Head will review the request after verification by the Action Officer.',

            default
                => 'The SPMU Head will review the request for approval.',
        },
    ];

    $steps[$pickupStepIndex] = [
        'label' => 'Pickup Scheduled',
        'icon' => 'calendar',
        'time' => $pickupScheduledAt,
        'description' => match (true) {
            (bool) $pickupScheduledAt && (bool) $custody?->scheduled_release_at
                => 'Pickup was scheduled for '
                    .$custody->scheduled_release_at->format('d M Y, g:i A')
                    .($custody?->pickup_expires_at
                        ? ' · claim until '.$custody->pickup_expires_at->format('g:i A')
                        : '')
                    .'.',

            (bool) $pickupScheduledAt
                => 'SPMU has confirmed the pickup schedule.',

            $pickupExpired
                => 'The pickup schedule was missed. SPMU will review the request.',

            $isApproved
                => 'SPMU is arranging the pickup schedule.',

            default
                => 'SPMU will arrange pickup after approval.',
        },
    ];

    $steps[$releaseStepIndex] = [
        'label' => 'Release Processing',
        'icon' => 'custody',
        'time' => $releasedAt,
        'description' =>
            $isReleased
                ? 'Requested items released to the borrower.'
                : match (true) {
                    ! $hasActivePickupSchedule
                        => 'The pickup schedule must be confirmed before the items are released.',
                    ! $preparationComplete
                        => 'SPMU is preparing the approved items for pickup.',
                    default
                        => 'The items are ready for the scheduled pickup.',
                },
    ];

    $steps[$returnStepIndex] = [
        'label' => 'Return Processing',
        'icon' => 'custody',
        'time' => $returnStartedAt,
        'description' => $physicalReturnComplete
            ? 'Physical return inspection recorded.'
            : $returnDescription,
    ];

    if ($showAccountabilityStage) {
        $steps[$returnStepIndex]['description'] = 'Physical return inspection recorded. '.match ($obligationSummary['key'] ?? null) {
                'BILLING_ISSUED', 'RSLDDP_PAYMENT_REQUIRED'
                    => 'Settle the issued Billing Statement through the CSPC Cashier.',
                'PAYMENT_VERIFICATION'
                    => 'SPMU is verifying the CSPC Cashier receipt.',
                'BILLING_OPEN', 'BILLING_PENDING'
                    => 'The financial obligation must be settled or formally waived before completion.',
                'FOR_BILLING'
                    => 'The SPMU Head has required billing. SPMU must issue the Billing Statement.',
                'COMPLIANCE_REQUIRED'
                    => 'SPMU must verify the required repair, replacement, or other corrective action.',
                'COMPLIANCE_RSLDDP_PENDING'
                    => 'Present proof of the completed corrective action to the SPMU Action Officer.',
                'F2F_PROCESSING', 'RSLDDP_AWAITING_UPLOAD', 'RSLDDP_DISPOSITION_PENDING'
                    => 'Visit SPMU to complete the required documents and discuss the necessary corrective action.',
                'RSLDDP_FOR_ACCOUNTING_PROCESSING'
                    => 'The Accounting Office is processing the documents for the Billing Statement.',
                'RSLDDP_COMPLIANCE_VERIFICATION'
                    => 'SPMU must verify payment or completion of the required corrective action.',
                'RSLDDP_FOR_RESOLUTION'
                    => 'The SPMU Head will complete the final review of the case.',
                'LATE_RETURN'
                    => 'SPMU is reviewing the late return and any related requirements.',
                'GATE_PASS_PENDING'
                    => 'SPMU must record the accomplished Gate Pass.',
                'LINEN_PENDING'
                    => 'SPMU is reviewing the Laundry Form and linen return records.',
                default
                    => 'Outstanding accountability requirements must be settled before completion.',
            };
    }

    $steps[$completedStepIndex] = [
        'label' => 'Completed',
        'icon' => 'success',
        'time' => $completedAt,
        'description' =>
            $isCancelled
                ? 'The request was cancelled before completion.'
                : ($isClosed
                    ? 'Transaction completed.'
                    : 'The transaction will be completed after all return requirements are met.'),
    ];

    // Terminal outcomes annotate the actual standard phase; never truncate
    // the rail or rename a phase to Cancelled / Rejected.
    $stoppedLabel = $isCancelled ? 'Cancelled' : ($statusValue === 'REJECTED' ? 'Rejected' : 'Expired');
    $stoppedAt = null;
    if ($isCancelled) {
        $cancelledFromStatus = $history->where('to_status', 'CANCELLED')->sortByDesc('changed_at')->first()?->from_status;
        $currentIndex = match (true) {
            $isReleased => $returnStepIndex,
            (bool) $custody?->pickup_expired_at => $pickupStepIndex,
            $hasActivePickupSchedule => $releaseStepIndex,
            $isApproved => $pickupStepIndex,
            ! $submittedAt && in_array($cancelledFromStatus, ['DRAFT', 'SIGNED'], true) => 2,
            $requiresActionOfficerVerification
                && ! in_array($verificationStep?->decision, ['VERIFIED', 'APPROVED'], true) => $actionOfficerStepIndex,
            default => $headReviewStepIndex,
        };
        $stoppedAt = $cancellationAt;
        $cancellationDescription = $cancellationActorName
            ? 'Cancelled by '.$cancellationActorName.'. '
            : 'This borrowing was cancelled. ';
        $cancellationDescription .= $cancellationReason !== ''
            ? 'Reason: '.$cancellationReason
            : 'No cancellation reason was recorded.';

        $steps[$currentIndex]['description'] = $cancellationDescription;
    }

    /*
     * Read the rejecting decision so a legacy Officer rejection is displayed
     * at verification instead of being attributed to the Head.
     */
    if ($statusValue === 'REJECTED') {
        $rejectionStep = $approvalSteps->where('decision', 'REJECTED')->sortByDesc('decided_at')->first();
        $currentIndex = $requiresActionOfficerVerification && $rejectionStep?->sequence_no === 1
            ? $actionOfficerStepIndex : $headReviewStepIndex;
        $stoppedAt = $rejectionStep?->decided_at ?: $historyAt(['REJECTED']);
        $steps[$currentIndex]['description'] = 'Request rejected at this stage.'
            .($rejectionStep?->remarks ? ' Reason: '.$rejectionStep->remarks : '');
    } elseif ($statusValue === 'EXPIRED' && ! $isCancelled) {
        $stoppedAt = $historyAt(['EXPIRED']);
    }

    ksort($steps);

    /*
     * Compact/release views may hide the tracker heading, but they never
     * rename milestones or swap milestone icons. The same request must show
     * the same tracker to Borrower, Action Officer, and Head/Admin.
     */

    /*
    |--------------------------------------------------------------------------
    | Tracker heading
    |--------------------------------------------------------------------------
    */

    $trackerEyebrow =
        $requiresActionOfficerVerification
            ? 'Off-Campus Borrowing'
            : 'On-Campus Borrowing';

    $trackerTitle = match (true) {
        $isClosed => 'Borrowing Progress',
        (bool) $custody => 'Borrowing Progress',
        default => 'Request Progress',
    };

    $trackerIntro =
        $terminal
            ? 'The tracker shows where the transaction stopped and the stages it did not reach.'
            : ($returnedForRevision
                ? 'The request was reviewed and returned for correction.'
                : ($isClosed
            ? 'View the completed stages of this borrowing transaction.'
            : ((bool) $custody
                ? 'View the progress of item pickup and return.'
                : 'Track the review and approval of this request.')));

    /*
    |--------------------------------------------------------------------------
    | Current status label
    |--------------------------------------------------------------------------
    */

    $currentLabel = match (true) {

        $statusValue === 'REJECTED'
            => 'Request Rejected',

        $isCancelled
            => 'Request Cancelled',

        $statusValue === 'EXPIRED'
            => 'Request Expired',

        $returnedForRevision
            => 'Borrower Revision Required',

        $pickupExpired
            => 'Pickup Missed',

        $isClosed
            => 'Completed',

        $showAccountabilityStage
            => $obligationSummary['label'] ?? $activeAccountability['label'] ?? 'Accountability Processing',

        $isReleased
            && $laundryStatus === 'FOR_LAUNDRY'
            => match (true) {
                $hasOutstandingLinen && $hasOutstandingNonLinen => 'Mixed Return Pending',
                $hasOutstandingNonLinen => 'Non-Linen Return Pending',
                $laundry?->hasVerifiedAccomplishedForm() => 'Awaiting SPMU Linen Encoding',
                $hasOutstandingLinen => 'Awaiting Laundry Return',
                default => 'Return Reconciliation',
            },

        $isReleased
            && $laundryStatus === 'TURNED_OVER_TO_LAUNDRY'
            => 'Linen Turned Over to Laundry',

        $isReleased
            && in_array($laundryStatus, ['IN_PROCESS', 'READY_FOR_SPMU_RETURN', 'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true)
            => 'Laundry Workflow Updating',

        $isReleased
            => 'Return / Custody In Progress',

        $preparationComplete
            && $hasActivePickupSchedule
            => 'Ready for Release',

        ! $hasActivePickupSchedule
            && $isApproved
            => 'Pickup Scheduling Required',

        $hasActivePickupSchedule
            && ! $preparationComplete
            => 'Preparing for Release',

        $preparationComplete
            && $isApproved
            => 'Pickup Scheduling Required',

        $waitingForActionOfficer
            => 'Waiting for Action Officer Verification',

        $waitingForHead
            => 'Waiting for SPMU Head / Admin Review',

        in_array(
            $statusValue,
            [
                'SUBMITTED',
                'UNDER_SPMU',
                'UNDER_GSU',
                'UNDER_VPAF',
            ],
            true
        )
            => $requiresActionOfficerVerification
                ? 'Gate Pass Verification in Progress'
                : 'Waiting for SPMU Head / Admin Review',

        $statusValue === 'SIGNED'
            => 'Ready for Submission',

        default
            => 'Preparing Request',
    };
@endphp


<section
    class="content-area request-tracker-card"
    data-transaction-tracker
    aria-labelledby="request-progress-title"
>
    <article class="card request-tracker">

        {{-- ================================================================
             HEADER
        ================================================================= --}}

        <div class="request-tracker__header {{ $compact ? 'visually-hidden' : '' }}">

            <div>

                <p class="eyebrow">
                    {{ $trackerEyebrow }}
                </p>

                <h2 id="request-progress-title">
                    {{ $trackerTitle }}
                </h2>

                <p class="request-tracker__intro">
                    {{ $trackerIntro }}
                </p>

            </div>


            @if($showCurrentStatus)

                <span
                    class="
                        request-tracker__current
                        {{
                            $terminal
                                ? 'is-terminal'
                                : (
                                    $returnedForRevision
                                    || $pickupExpired
                                        ? 'is-warning'
                                        : ''
                                )
                        }}
                    "
                >

                    <span
                        class="request-tracker__current-dot"
                        aria-hidden="true"
                    ></span>

                    {{ $currentLabel }}

                </span>

            @endif

        </div>


        {{-- ================================================================
             TERMINAL / ATTENTION MESSAGES
        ================================================================= --}}

       @if($terminal && ! $isCancelled && $statusValue !== 'REJECTED')
            <div class="request-tracker__terminal-note" role="status">
                <x-icon name="information" size="18" />
                <span>
                    This request is {{ strtolower(str_replace('_', ' ', $statusValue)) }}.
                    The tracker shows the furthest workflow point reached before it was closed.
                </span>
            </div>
        @elseif($pickupExpired)
            <div class="request-tracker__attention-note" role="status">
                <x-icon name="information" size="18" />
                <span>
                    The pickup was missed &mdash; For SPMU Review.
                    SPMU is reviewing whether the items can still be released or whether the borrowing must be cancelled. No action is required from you at this time.
                </span>
            </div>
        @endif


        {{-- ================================================================
             TRACKER RAIL
        ================================================================= --}}

        <div
            class="request-tracker__scroll"
            tabindex="0"
            aria-label="Request progress steps"
        >

            <ol class="request-tracker__rail" style="--request-tracker-step-count: {{ count($steps) }}">

                @foreach($steps as $index => $step)

                    @php

                        $stepState = 'is-pending';

                        /*
                         * Completed transaction:
                         * everything becomes green/completed.
                         */
                        if ($step['not_required'] ?? false) {

                            $stepState = 'is-not-required';

                        } elseif ($isClosed) {

                            $stepState = 'is-complete';

                        }

                        /*
                         * Rejected / cancelled / expired.
                         */
                        elseif ($terminal) {

                            $stepState =
                                $index < $currentIndex
                                    ? 'is-complete'
                                    : (
                                        $index === $currentIndex
                                            ? 'is-stopped'
                                            : 'is-pending'
                                    );

                        }

                        /*
                         * Previous milestones.
                         */
                        elseif ($index < $currentIndex) {

                            $stepState = 'is-complete';

                        }

                        /*
                         * Current milestone.
                         */
                        elseif ($index === $currentIndex) {

                            $stepState =
                                (
                                    $returnedForRevision
                                    || $pickupExpired
                                )
                                    ? 'is-warning'
                                    : 'is-current';

                        }

                        $stepTime = $stepState === 'is-not-required' ? null : $step['time'];
                        $stepDescription = $step['description'];
                        if ($stepState === 'is-stopped') {
                            $stepTime = $stoppedAt;
                            $stepMeta = $stoppedLabel.($stepTime ? ' · '.$formatDateTime($stepTime) : ' · Date not recorded');
                        } elseif ($stepState === 'is-not-required') {
                            $stepMeta = 'Not required';
                        } elseif ($terminal && $stepState === 'is-pending') {
                            $stepTime = null;
                            $stepMeta = 'Not reached';
                            $stepDescription = 'This stage was not reached before the transaction was '.strtolower($stoppedLabel).'.';
                        } else {
                            $stepMeta = $stepTime ? $formatDateTime($stepTime)
                                : ($stepState === 'is-complete' ? 'Date not recorded' : 'Pending');
                        }
                    @endphp


                    <li
                        class="
                            request-tracker__step
                            {{ $stepState }}
                            {{ $stepState === 'is-not-required' && ($isClosed || $index < $currentIndex) ? 'is-passed' : '' }}
                        "

                        @if(
                            in_array(
                                $stepState,
                                [
                                    'is-current',
                                    'is-warning',
                                    'is-stopped',
                                ],
                                true
                            )
                        )
                            aria-current="step"
                        @endif
                    >

                        {{-- Marker --}}

                        <div
                            class="request-tracker__marker"
                            aria-hidden="true"
                        >

                            @if($stepState === 'is-complete')

                                <svg
                                    width="25"
                                    height="25"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M5 12.5L9.5 17L19 7.5"
                                        stroke="currentColor"
                                        stroke-width="3.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>

                            @elseif($stepState === 'is-stopped')

                                <x-icon
                                    name="error"
                                    size="22"
                                />

                            @else

                                <x-icon
                                    :name="$step['icon']"
                                    size="21"
                                />

                            @endif

                        </div>


                        {{-- Text --}}

                        <div
                            class="request-tracker__copy workflow-tracker__interactive"
                            data-workflow-step
                            data-workflow-title="{{ $step['label'] }}"
                            data-workflow-meta="{{ $stepMeta }}"
                            data-workflow-description="{{ $stepDescription }}"
                            tabindex="0"
                            aria-label="{{ $step['label'] }}. {{ $stepMeta }}. {{ $stepDescription }}"
                        >

                            <strong>
                                {{ $step['label'] }}
                            </strong>

                            @if($stepState === 'is-not-required' || $stepState === 'is-stopped')
                                <span class="request-tracker__state-label">{{ $stepState === 'is-stopped' ? $stoppedLabel : 'Not required' }}</span>
                            @endif

                            @if($stepTime)

                                <time
                                    datetime="{{
                                        $stepTime
                                            ->toIso8601String()
                                    }}"
                                >

                                    {{ $stepMeta }}

                                </time>

                            @else

                                <span
                                    class="
                                        request-tracker__pending-label
                                    "
                                >
                                    {{ $stepMeta }}
                                </span>

                            @endif

                        </div>

                    </li>

                @endforeach

            </ol>

        </div>

        @if($isCancelled)
            <div class="request-tracker__cancellation-summary" role="status">
                <strong>Cancelled</strong>
                <span class="request-tracker__cancellation-meta">{{ $cancellationAt ? 'Cancelled on '.$formatDateTime($cancellationAt) : 'Cancellation time not recorded' }}</span>

                @if($cancellationReason !== '')
                    <span class="request-tracker__cancellation-label">Cancellation reason</span>
                    <p class="request-tracker__cancellation-reason">{{ $cancellationReason }}</p>
                @endif
            </div>
        @endif


        {{-- ================================================================
             FOOTER / INFO
        ================================================================= --}}

        @unless($compact)
        <p class="request-tracker__hint">

            <x-icon
                name="information"
                size="16"
            />

            <span>

                @if($isCancelled)

                    This borrowing was cancelled. The completed history is retained for reference.

                @elseif($isClosed)

                    This history is retained for reference.

                @else

                    Progress is updated as each stage is completed.

                @endif

            </span>

        </p>
        @endunless

    </article>
</section>

<x-workflow-tracker-interactions />
