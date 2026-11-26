@php
    /*
    |--------------------------------------------------------------------------
    | Transaction history
    |--------------------------------------------------------------------------
    |
    | This view intentionally builds a readable lifecycle timeline from the
    | records already loaded for the request detail page. It does not create or
    | modify audit/business records. The goal is to show significant events
    | beyond the request-status transitions (pickup, release, return, etc.)
    | without exposing raw internal status codes to the user.
    |
    */
    $historyEvents = collect();
    $saoAllocationWording = auth()->user()?->access_classification?->value === 'SPMU_OFFICER';

    $addHistoryEvent = function (
        $when,
        string $stage,
        string $event,
        string $actor,
        ?string $details = null,
        ?string $key = null,
        int $sortOrder = 0
    ) use (&$historyEvents): void {
        if (! $when) {
            return;
        }

        $historyEvents->push([
            'when' => $when,
            'stage' => $stage,
            'event' => $event,
            'actor' => $actor,
            'details' => filled($details) ? $details : '—',
            'key' => $key ?: $stage.'|'.$event.'|'.$when->format('Y-m-d H:i:s'),
            // Timestamp remains authoritative. This value is used only when
            // two persisted workflow events share the exact same second.
            'sort_order' => $sortOrder,
        ]);
    };

    $requestHasSubmission = $borrowingRequest->currentVersion?->submitted_at !== null
        || $borrowingRequest->statusHistory->contains(
            fn ($history) => ($history->to_status instanceof \BackedEnum
                ? $history->to_status->value
                : (string) $history->to_status) === 'UNDER_SPMU'
        );

    if (! $requestHasSubmission) {
        $addHistoryEvent(
            $borrowingRequest->created_at,
            'Request',
            'Request created',
            $borrowingRequest->borrower?->full_name ?: 'Borrower',
            null,
            'request-created',
            10
        );
    }

    // 2) Request status changes, translated into user-facing lifecycle events.
    foreach ($borrowingRequest->statusHistory as $history) {
        $rawToStatus = $history->to_status instanceof \BackedEnum
            ? $history->to_status->value
            : (string) $history->to_status;

        [$eventLabel, $defaultDetails] = match ($rawToStatus) {
            'UNDER_SPMU' => [
                'Request submitted',
                'Submitted to SPMU for review.',
            ],
            'RETURNED_FOR_REVISION' => [
                'Returned for revision',
                'The request was returned for borrower correction.',
            ],
            'REJECTED' => [
                'Request rejected',
                'The request did not proceed to physical issuance.',
            ],
            'APPROVED_READY_FOR_RELEASE', 'FINAL_APPROVED_AWAITING_DOWNLOAD' => [
                'Request approved',
                'Final SPMU approval recorded; the transaction may proceed to pickup and issuance processing.',
            ],
            'CANCELLED' => [
                'Request cancelled',
                'The request was cancelled before completion of the borrowing lifecycle.',
            ],
            'DRAFT' => [
                'Request saved as draft',
                'Draft request retained for further preparation.',
            ],
            default => [
                str($rawToStatus)->replace('_', ' ')->lower()->title()->toString(),
                'Request status updated.',
            ],
        };

        // Logical tie-breaker only; changed_at is still the source of truth.
        $statusSortOrder = match ($rawToStatus) {
            'DRAFT' => 10,
            'UNDER_SPMU' => 20,
            'RETURNED_FOR_REVISION' => 30,
            'APPROVED_READY_FOR_RELEASE', 'FINAL_APPROVED_AWAITING_DOWNLOAD' => 40,
            'REJECTED', 'CANCELLED' => 190,
            default => 25,
        };

        $historyDetails = match (trim((string) ($history->reason ?: $defaultDetails))) {
            'E-signed borrowing request version and scanned supporting document(s) submitted to the SPMU Action Officer for verification.' =>
                'Sent to the Action Officer for verification.',
            'E-signed borrowing request version and scanned supporting document(s) submitted to the SPMU Head for approval.' =>
                'Sent to the SPMU Head for approval.',
            'SPMU Head verified and approved the request. The approved quantity is allocated/held for pickup.' =>
                $saoAllocationWording ? 'The request was approved. Items are allocated for pickup.' : $history->reason,
            default => $history->reason ?: $defaultDetails,
        };

        $addHistoryEvent(
            $history->changed_at,
            'Request',
            $eventLabel,
            $history->actor?->full_name ?: 'System',
            $historyDetails,
            'request-status-'.$history->id,
            $statusSortOrder
        );
    }

    // 3) AO verification is a significant review event but may not change the
    // top-level request status, so surface it from the approval step itself.
    $verificationStep = $borrowingRequest->currentVersion?->approvalSteps
        ?->first(fn ($step) => (int) $step->sequence_no === 1 && strtoupper((string) $step->decision) === 'VERIFIED');

    if ($verificationStep) {
        $addHistoryEvent(
            $verificationStep->decided_at,
            'Review',
            'Action Officer verification completed',
            $verificationStep->approver?->full_name ?: 'SPMU Action Officer',
            $verificationStep->remarks ?: 'Request and supporting documents verified for Head/Admin review.',
            'verification-'.$verificationStep->id,
            30
        );
    }

    $custody = $borrowingRequest->custody;

    if ($custody) {
        // 4) Pickup / operational-schedule lifecycle. These events are read
        // from the audit/notification records because the current custody
        // fields alone cannot preserve a missed window or an older schedule
        // after a reschedule overwrites scheduled_release_at.
        $operationalAuditEvents = collect($operationalHistoryAuditEvents ?? []);
        $operationalNotificationEvents = collect($operationalHistoryNotificationEvents ?? []);

        $formatOperationalDateTime = static function ($value): ?string {
            if (blank($value)) {
                return null;
            }

            try {
                return \Carbon\Carbon::parse($value)->format('d M Y, g:i A');
            } catch (\Throwable) {
                return null;
            }
        };

        foreach ($operationalAuditEvents as $auditEvent) {
            $actionCode = strtoupper((string) $auditEvent->action_code);
            $after = is_array($auditEvent->after_json) ? $auditEvent->after_json : [];
            $saoInPersonPickup = $saoAllocationWording && data_get($after, 'borrower_presence_attested_by_spmu') === true;
            $actor = $auditEvent->actor?->full_name;
            $pickupAt = $formatOperationalDateTime(data_get($after, 'pickup_at'));
            $pickupUntil = $formatOperationalDateTime(data_get($after, 'pickup_expires_at'));
            $window = $pickupAt
                ? ' New pickup: '.$pickupAt.($pickupUntil ? ' to '.$pickupUntil.'.' : '.')
                : '';

            [$stage, $event, $defaultActor, $details, $sortOrder] = match ($actionCode) {
                'PICKUP_SCHEDULE_AUTOMATICALLY_ACTIVATED' => [
                    'Pickup',
                    'Initial pickup schedule activated',
                    'System',
                    'The initial pickup/issuance schedule was activated from the SPMU Operational Calendar.'.$window,
                    50,
                ],
                'PICKUP_SCHEDULE_CONFIRMED' => [
                    'Pickup',
                    'Pickup schedule confirmed',
                    'SPMU Action Officer',
                    'SPMU confirmed the pickup/issuance schedule for this approved request.'.$window,
                    52,
                ],
                'PICKUP_WINDOW_EXPIRED' => [
                    'Pickup',
                    'Pickup missed',
                    'System',
                    $saoAllocationWording
                        ? 'The confirmed pickup window passed before physical release. The allocation remained active while the borrower could request rescheduling or cancel the unreleased request.'
                        : 'The confirmed pickup window passed before physical release. The reservation remained active while the borrower could request rescheduling or cancel the unreleased request.',
                    54,
                ],
                'PICKUP_RESCHEDULE_REQUESTED' => [
                    'Pickup',
                    'Pickup reschedule requested',
                    'Borrower',
                    'The borrower requested another valid pickup schedule on the same approved request.',
                    56,
                ],
                'PICKUP_RESCHEDULED' => [
                    'Pickup',
                    $saoInPersonPickup ? 'Borrower pickup confirmed' : 'Pickup rescheduled',
                    'SPMU Action Officer',
                    $saoInPersonPickup
                        ? 'The Action Officer confirmed that the borrower was present and would proceed with pickup.'
                        : ($saoAllocationWording
                        ? 'SPMU assigned the next valid pickup/issuance window while retaining the same approved request and allocation.'
                        : 'SPMU assigned the next valid pickup/issuance window while retaining the same approved request and reservation.').$window,
                    58,
                ],
                'PICKUP_HELD_FOR_PREPARATION_ISSUE' => [
                    'Preparation',
                    'Pickup held for inventory review',
                    'System',
                    'The pickup could not proceed because SPMU was resolving an item-preparation or inventory discrepancy. This was not recorded as a borrower missed pickup.',
                    61,
                ],
                'PREPARATION_ISSUE_REPORTED' => [
                    'Preparation',
                    'Preparation discrepancy reported',
                    'SPMU Action Officer',
                    filled($auditEvent->reason)
                        ? trim((string) $auditEvent->reason)
                        : 'A physical item-preparation discrepancy was recorded for SPMU review before release.',
                    62,
                ],
                'PREPARATION_ISSUE_RESOLVED' => [
                    'Preparation',
                    'Preparation discrepancy resolved',
                    'SPMU Head / Administrator',
                    filled($auditEvent->reason)
                        ? trim((string) $auditEvent->reason)
                        : 'The reported preparation discrepancy was reviewed and resolved before the release workflow continued or was closed.',
                    64,
                ],
                default => [null, null, null, null, 0],
            };

            if ($event) {
                $addHistoryEvent(
                    $auditEvent->occurred_at,
                    $stage,
                    $event,
                    $actor ?: $defaultActor,
                    $details,
                    'operational-audit-'.$auditEvent->id,
                    $sortOrder
                );
            }
        }

        // Legacy/fallback transactions may predate the operational audit
        // events above. In that case preserve the current schedule row.
        $hasPickupScheduleHistory = $operationalAuditEvents->contains(
            fn ($event) => in_array(strtoupper((string) $event->action_code), [
                'PICKUP_SCHEDULE_AUTOMATICALLY_ACTIVATED',
                'PICKUP_SCHEDULE_CONFIRMED',
                'PICKUP_RESCHEDULED',
            ], true)
        );

        if (! $hasPickupScheduleHistory) {
            $addHistoryEvent(
                $custody->pickup_scheduled_at ?: $custody->scheduled_release_at,
                'Pickup',
                'Pickup schedule activated',
                $custody->pickupScheduledBy?->full_name ?: 'System',
                $custody->scheduled_release_at
                    ? 'Pickup/issuance scheduled for '.$custody->scheduled_release_at->format('d M Y, g:i A').'.'
                    : 'Pickup/issuance schedule recorded.',
                'pickup-scheduled',
                50
            );
        }

        foreach ($operationalNotificationEvents as $notificationEvent) {
            if (strtoupper((string) $notificationEvent->event_code) !== 'RETURN_SCHEDULE_ADJUSTED') {
                continue;
            }

            $message = trim((string) data_get($notificationEvent->payload_snapshot_json, 'message', ''));

            $addHistoryEvent(
                $notificationEvent->occurred_at,
                'Return Schedule',
                'Effective return date adjusted',
                'System / SPMU Calendar',
                $message !== ''
                    ? $message
                    : 'The SPMU Operational Calendar moved the effective return date to the next available Return schedule. The adjustment is not treated as a late return.',
                'return-schedule-adjusted-'.$notificationEvent->id,
                95
            );
        }

        // 5) Release preparation and physical issuance.
        $addHistoryEvent(
            $custody->prepared_at,
            'Release',
            'Items prepared for release',
            $custody->preparedBy?->full_name ?: 'SPMU Action Officer',
            'Approved quantities were prepared for physical issuance.',
            'release-prepared',
            60
        );

        $addHistoryEvent(
            $custody->released_at,
            'Release',
            'Items physically released',
            $custody->releasedBy?->full_name ?: 'SPMU Action Officer',
            'Physical issuance recorded and custody began for the released items.',
            'items-released',
            70
        );

        // 6) Applicable Gate Pass evidence.
        $gatePass = $custody->gatePass;
        if ($gatePass) {
            $gatePassRecordedAt = $gatePass->verified_at ?: $gatePass->uploaded_at;

            $addHistoryEvent(
                $gatePassRecordedAt,
                'Gate Pass',
                'Accomplished Gate Pass recorded',
                $gatePass->verified_at
                    ? ($gatePass->verifiedBy?->full_name ?: 'SPMU Action Officer')
                    : ($gatePass->uploadedBy?->full_name ?: 'SPMU Action Officer'),
                'Accomplished Gate Pass recorded by SPMU.',
                'gate-pass-recorded',
                85
            );
        }

        // 7) Applicable Laundry evidence / completion.
        $laundryJob = $custody->laundryJob;
        if ($laundryJob) {
            $addHistoryEvent(
                $laundryJob->form_verified_at,
                'Laundry',
                'Accomplished Laundry Form recorded',
                $laundryJob->formVerifier?->full_name ?: 'SPMU Action Officer',
                'Physical Laundry Form evidence was received and verified by SPMU.',
                'laundry-form-recorded',
                85
            );

            $addHistoryEvent(
                $laundryJob->completed_at,
                'Laundry',
                'Laundry processing completed',
                'System',
                'Applicable serviceable linen processing was completed.',
                'laundry-completed',
                175
            );
        }

        // 8) Current return workflow: one persisted ReturnTransaction represents
        // the completed inspection for one physical channel. Non-linen is
        // inspected directly by the Action Officer. Linen is encoded by SPMU
        // from the accomplished Laundry Form, whose RECEIVED BY date is the
        // authoritative physical-return timestamp. Do not recreate the removed
        // split receipt/inspection fields (receipt_quantities / inspected_at).
        foreach ($custody->returns->sortBy(fn ($return) => $return->received_at ?: $return->created_at) as $return) {
            $isLinenOnlyReturn = $return->lines->isNotEmpty()
                && $return->lines->every(
                    fn ($line) => (bool) $line->custodyLine?->requestItem?->inventoryItem?->laundry_required
                );

            $adverseFindings = $return->lines
                ->filter(fn ($line) => strtoupper((string) $line->condition_code) !== 'FINE')
                ->map(function ($line): string {
                    $requestItem = $line->custodyLine?->requestItem;
                    $description = $requestItem?->description_snapshot
                        ?: $requestItem?->inventoryItem?->unique_description
                        ?: 'Borrowed item';
                    $quantity = (float) $line->quantity_received;
                    $quantityLabel = fmod($quantity, 1.0) === 0.0
                        ? (string) (int) $quantity
                        : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
                    $unit = trim((string) ($requestItem?->unit_snapshot ?: ''));
                    $finding = str((string) $line->condition_code)
                        ->replace('_', ' ')
                        ->lower()
                        ->title()
                        ->toString();

                    return $description.' — '.$quantityLabel.($unit !== '' ? ' '.$unit : '').' '.$finding;
                })
                ->values();

            $conditionDetails = $adverseFindings->isNotEmpty()
                ? ' Adverse finding(s): '.$adverseFindings->implode('; ').'.'
                : ' All quantities in this inspection were recorded as Fine/Good.';

            $returnDetails = $isLinenOnlyReturn
                ? 'SPMU recorded the linen quantities and condition findings from the accomplished Laundry Form. The Laundry RECEIVED BY date is the authoritative physical-return date.'
                : 'SPMU recorded the returned quantities and physical condition findings.';

            if (filled($return->remarks)) {
                $returnDetails .= ' Remarks: '.trim((string) $return->remarks).'.';
            }

            $addHistoryEvent(
                $return->received_at ?: $return->created_at,
                'Return',
                match (true) {
                    $isLinenOnlyReturn && strtoupper((string) $return->return_type) === 'EARLY' => 'Early linen return recorded from Laundry Form',
                    $isLinenOnlyReturn => 'Linen return recorded from Laundry Form',
                    strtoupper((string) $return->return_type) === 'EARLY' => 'Early return inspection recorded',
                    strtoupper((string) $return->return_type) === 'OVERDUE' => 'Overdue return inspection recorded',
                    default => 'Return inspection recorded',
                },
                $isLinenOnlyReturn
                    ? 'Laundry / SPMU record'
                    : ($return->receivedBy?->full_name ?: 'SPMU Action Officer'),
                $returnDetails.$conditionDetails,
                'return-'.$return->id,
                100
            );
        }

        // 9) Accountability continues the SAME borrowing transaction after the
        // physical return. It must remain visible for Borrower, Action Officer,
        // and Head/Admin until every linked obligation is resolved.
        $borrowerHistoryView = strtoupper((string) session('active_workspace')) === 'BORROWER';

        foreach (($accountabilityHistory ?? collect()) as $accountabilityEvent) {
            $addHistoryEvent(
                $accountabilityEvent['when'],
                $accountabilityEvent['stage'],
                $borrowerHistoryView
                    ? $accountabilityEvent['event_borrower']
                    : $accountabilityEvent['event_spmu'],
                $accountabilityEvent['actor'],
                $borrowerHistoryView
                    ? $accountabilityEvent['details_borrower']
                    : $accountabilityEvent['details_spmu'],
                $accountabilityEvent['key'],
                (int) ($accountabilityEvent['sort_order'] ?? 110)
            );
        }

        // 10) Final lifecycle closure.

        // A normally fulfilled borrowing transaction is shown as completed.
        // A cancelled request is shown as closed instead so the history does
        // not imply that physical borrowing was successfully completed.
        $requestWasCancelled = $historyEvents->contains(
            fn ($item) => ($item['event'] ?? null) === 'Request cancelled'
        );

        $pickupWasMissed = $historyEvents->contains(
            fn ($item) => ($item['event'] ?? null) === 'Pickup missed'
        );

        $closureStage = $requestWasCancelled
            ? 'Closure'
            : 'Completion';

        $closureEvent = $requestWasCancelled
            ? 'Transaction closed'
            : 'Transaction completed';

        $closureDetails = match (true) {
            $requestWasCancelled && $pickupWasMissed =>
                'The request lifecycle was closed after the request was automatically cancelled due to the missed pickup and no response was received within the allowed period.',

            $requestWasCancelled =>
                'The request lifecycle was closed after the borrowing request was cancelled before completion.',

            default =>
                'Custody was closed after the applicable return, documentation, inventory, and accountability checks were completed.',
        };

        $addHistoryEvent(
            $custody->closed_at,
            $closureStage,
            $closureEvent,
            'System',
            $closureDetails,
            'custody-closed',
            200
        );
    }

    $historyEvents = $historyEvents
        ->filter(fn ($item) => $item['when'])
        ->unique('key')
        ->sort(function ($left, $right): int {
            $timeOrder = $right['when']->getTimestamp() <=> $left['when']->getTimestamp();

            if ($timeOrder !== 0) {
                return $timeOrder;
            }

            // MySQL timestamps in this project are second-precision. When two
            // real workflow actions share that same second, show the later
            // lifecycle consequence first (e.g. restriction -> incident ->
            // return; pickup schedule -> approval; release -> preparation).
            return ((int) ($right['sort_order'] ?? 0)) <=> ((int) ($left['sort_order'] ?? 0));
        })
        ->values();

    $latestHistory = $historyEvents->first();
@endphp

<details class="card request-activity-history">
    <summary class="request-activity-summary">
        <span class="request-activity-heading">
            <span class="request-section-title">
                <x-icon name="clock" size="18" />
                <span>Transaction History</span>
            </span>

            <span class="request-activity-latest">
                @if($latestHistory)
                    Latest:
                    <strong>{{ $latestHistory['event'] }}</strong>
                    · {{ $latestHistory['when']->format('d M Y, g:i A') }}
                @else
                    No transaction history yet.
                @endif
            </span>
        </span>

        <span class="request-activity-toggle">
            <span class="request-history-show">View History</span>
            <span class="request-history-hide">Hide History</span>
            <x-icon name="chevron-down" size="17" />
        </span>
    </summary>

    <div class="table-wrap request-history-table-wrap">
        <table class="request-history-table" data-record-table="Transaction history" data-record-controls="pagination">
            <colgroup>
                <col class="request-history-col-when">
                <col class="request-history-col-event">
                <col class="request-history-col-actor">
            </colgroup>
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Activity</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
            @forelse($historyEvents as $history)
                <tr data-record-filter="{{ $history['stage'] }}" data-record-date="{{ $history['when']->format('Y-m-d') }}">
                    <td data-label="Date &amp; Time">{{ $history['when']->format('d M Y, g:i A') }}</td>
                    <td data-label="Activity" class="request-history-event">
                        {{ $history['event'] }}
                        @if($history['details'] !== '—')
                            <details class="request-history-more">
                                <summary>Details</summary>
                                <p class="request-history-details">{{ $history['details'] }}</p>
                            </details>
                        @endif
                    </td>
                    <td data-label="By">{{ $history['actor'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="request-history-empty">No transaction history yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</details>

@once
<style>

    .request-activity-history {
        padding: 0;
        overflow: hidden;
    }

    .request-activity-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 16px;
        cursor: pointer;
        list-style: none;
    }

    .request-activity-summary::-webkit-details-marker { display: none; }

    .request-activity-summary:focus-visible {
        outline: 0;
        border-radius: var(--radius);
        box-shadow: var(--focus-ring);
    }

    .request-activity-heading {
        display: grid;
        gap: 5px;
        min-width: 0;
    }

    .request-section-title {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: var(--heading);
        font-size: 15px;
        font-weight: 700;
    }

    .request-section-title > .ui-icon { color: var(--text-soft); }

    .request-activity-latest {
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    .request-activity-latest strong {
        color: var(--text);
        font-weight: 700;
    }

    .request-activity-toggle {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        flex: 0 0 auto;
        color: var(--interactive);
        font-size: 12px;
        font-weight: 700;
    }

    .request-activity-toggle .ui-icon { transition: transform 160ms ease; }

    .request-activity-history .request-history-hide,
    .request-activity-history[open] .request-history-show { display: none; }
    .request-activity-history[open] .request-history-hide { display: inline; }
    .request-activity-history[open] .request-activity-latest { display: none; }
    .request-activity-history[open] .request-activity-toggle .ui-icon { transform: rotate(180deg); }

    .request-activity-history > .request-history-table-wrap {
        box-sizing: border-box;
        width: calc(100% - 36px);
        max-width: calc(100% - 36px);
        margin: 0 12px 12px;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        border: 1px solid var(--border);
        border-radius: calc(var(--radius) - 2px);
    }

    .request-history-table {
        width: 100%;
        min-width: 560px;
        table-layout: fixed;
        margin: 0;
    }

    .request-history-col-when { width: 180px; }
    .request-history-col-event { width: auto; }
    .request-history-col-actor { width: 200px; }

    .request-history-table th,
    .request-history-table td {
        vertical-align: top;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: normal;
    }

    .request-history-table td {
        padding: 12px 12px;
        line-height: 1.45;
    }

    .request-history-event {
        color: var(--heading);
        font-weight: 700;
    }

    .request-history-more { margin-top: 5px; font-weight: 400; }
    .request-history-more > summary { width: fit-content; color: var(--interactive); cursor: pointer; font-size: 12px; font-weight: 600; }
    .request-history-details {
        margin: 7px 0 0;
        color: var(--text-muted);
        font-size: 12px;
        font-weight: 400;
    }

    .request-history-empty {
        padding: 16px !important;
        text-align: center;
        color: var(--text-muted);
    }

    @media (max-width: 760px) {
        .request-activity-summary {
            align-items: flex-start;
            flex-direction: column;
            gap: 10px;
        }

        .request-activity-toggle {
            align-self: flex-end;
        }

        .request-history-table {
            min-width: 560px;
        }
    }
</style>
@endonce
