@php
    // This is the fallback for pickup-schedule values missing from older release views.
    $hasSystemPickupWindow = isset($hasSystemPickupWindow)
        ? (bool) $hasSystemPickupWindow
        : ((bool) $custody->scheduled_release_at && (bool) $custody->pickup_expires_at);

    $hasPickupSchedule = isset($hasPickupSchedule)
        ? (bool) $hasPickupSchedule
        : ($hasSystemPickupWindow
            && (bool) $custody->pickup_scheduled_at
            && ! $custody->pickup_expired_at);

    $pickupWindowPassed = isset($pickupWindowPassed)
        ? (bool) $pickupWindowPassed
        : ($hasSystemPickupWindow
            && $custody->pickup_expires_at
            && now()->gt($custody->pickup_expires_at));

    $releasePreparationIssues = collect($preparationIssues ?? []);
    $openPreparationIssues = $releasePreparationIssues->where('is_resolved', false)->values();
    $hasOpenPreparationIssue = $openPreparationIssues->isNotEmpty();
    $hasPreparationExceptionPendingRelease = $custody->status === 'PREPARING_RELEASE'
        && ! $custody->released_at
        && ! $custody->prepared_at
        && $releasePreparationIssues->isNotEmpty();
    $pickupHeldForPreparationIssue = $hasPreparationExceptionPendingRelease
        && $pickupWindowPassed;

    $pickupMissed = ! $pickupHeldForPreparationIssue
        && ! $custody->released_at
        && (bool) $custody->pickup_scheduled_at
        && ((bool) $custody->pickup_expired_at || $pickupWindowPassed);

    // Only shown when the AO actually needs to act on the schedule - a normal,
    // on-track schedule is already fully represented by the top status card.
    $releaseScheduleAttention = ! $hasPickupSchedule || $pickupWindowPassed || $pickupMissed || $pickupHeldForPreparationIssue;

    $canConfirmPreparation = $hasPickupSchedule && ! $hasOpenPreparationIssue;
@endphp

<section class="content-grid two release-overview-grid">
    <article class="card release-context-card">
        <div class="release-card-title"><x-icon name="calendar" size="21" /><h2>Borrowing Details</h2></div>
        <x-borrower-identity :borrower="$custody->borrower" :version="$custody->requestVersion ?: $version" />
        <div class="release-details-columns">
            <div class="release-detail-group">
                <dl class="detail-list release-context-list">
                    <dt>Purpose</dt><dd>{{ $version?->purpose_event ?: '—' }}</dd>
                    <dt>Date Needed</dt><dd>{{ $scheduleDate?->format('d M Y') ?: 'Not available' }}</dd>
                </dl>
            </div>
            <div class="release-detail-group">
                <dl class="detail-list release-context-list">
                    <dt>Return Date</dt><dd>{{ $returnDate?->format('d M Y') ?: 'Not available' }}</dd>
                    <dt>Use Location</dt><dd>{{ $hasOffCampusItem ? 'Off-campus' : 'On-campus' }}</dd>
                </dl>
            </div>
        </div>
    </article>

    <article class="card release-documents-card" id="release-actions">
        <div class="release-card-title"><x-icon name="requests" size="21" /><h2>Release Documents</h2></div>
        @include('custody.partials.release-documents')
        @if($hasOffCampusItem)
            <p class="release-step-note">Check the signed Gate Pass before release.</p>
        @endif
        @if($hasLaundryItem)
            <p class="release-step-note">Complete <strong>ISSUED BY</strong> on the Laundry Form.</p>
        @endif
    </article>
</section>

@if($releaseScheduleAttention)

    <section class="content-area">
        <article class="card release-schedule-exception-card" role="alert">
            <div class="release-card-title"><x-icon name="warning" size="21" /><h2>{{ $pickupHeldForPreparationIssue ? 'Item Review' : ($pickupMissed ? 'Pickup Missed' : (($pickupScheduleReviewPending ?? false) ? 'Pickup Confirmation' : 'Pickup Schedule')) }}</h2></div>
            @if($pickupHeldForPreparationIssue)
                <p class="release-step-note">Release is on hold while the SPMU Head reviews the reported item issue.</p>
            @else
                @include('custody.partials.pickup-schedule-form')
            @endif
        </article>
    </section>
@endif

<section class="content-area" id="item-preparation">
    <article class="card release-approved-card">
        <div class="release-card-title"><x-icon name="inventory" size="21" /><h2>Approved Items</h2></div>
        <div class="table-wrap">
            <table class="release-approved-table">
                <thead><tr><th scope="col">Item</th><th scope="col">Approved Quantity</th><th scope="col">Preparation</th></tr></thead>
                <tbody>
                    @forelse($custody->lines as $line)
@php
                            $openLineIssue = $openPreparationIssues->firstWhere('custody_line_id', $line->id);
                            $latestLineIssue = $releasePreparationIssues->firstWhere('custody_line_id', $line->id);
                            $lineStatus = $preparationComplete
                                ? 'Prepared'
                                : ($openLineIssue
                                    ? 'Inventory Review Required'
                                    : ($latestLineIssue && $latestLineIssue['is_resolved']
                                        ? 'Pending Recheck'
                                        : 'Pending'));
                            $lineTone = $preparationComplete
                                ? 'is-complete'
                                : ($openLineIssue
                                    ? 'is-issue'
                                    : ($latestLineIssue && $latestLineIssue['is_resolved']
                                        ? 'is-recheck'
                                        : 'is-pending'));
                        @endphp
                        <tr>
                            <td><strong>{{ $line->requestItem->description_snapshot }}</strong><small>{{ $line->requestItem->unit_snapshot }}</small></td>
                            <td>{{ $line->approved_quantity + 0 }}</td>
                            <td><span class="release-preparation-status {{ $lineTone }}">{{ $lineStatus }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No approved items.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @unless($preparationComplete)
            <div class="release-approved-actions">
                <form method="post" action="{{ route('custody.prepare', $custody) }}">
                    @csrf
                    <button class="button primary ui-pressable release-primary" type="submit" @disabled(! $canConfirmPreparation)>
                        <span>Confirm Items Prepared</span>
                    </button>
                </form>
            </div>
        @endunless
    </article>
</section>

<section class="content-area">
    <article class="card release-physical-card">
        <div class="release-card-title"><x-icon name="custody" size="21" /><h2 id="physical-release-title">Release Confirmation</h2></div>
        @if($preparationComplete && $hasPickupSchedule)
            @if($pickupWindowUpcoming)
                <div class="callout info release-window-notice" id="physical-release-availability">
                    <strong>Pickup has not started.</strong>
                    <p>Available from <strong>{{ optional($pickupWindowStartsAt)->format('d F Y, g:i A') }}</strong> to <strong>{{ optional($pickupWindowEndsAt)->format('d F Y, g:i A') }}</strong>.</p>
                </div>
            @elseif($pickupWindowPassed)
                <div class="callout warning release-window-notice" id="physical-release-availability">
                    <strong>Pickup schedule has ended.</strong>
                    <p>The pickup schedule ended on <strong>{{ optional($pickupWindowEndsAt)->format('d F Y, g:i A') }}</strong>. Confirm a new pickup schedule before releasing the items.</p>
                </div>
            @endif
        @elseif($hasOpenPreparationIssue)
            <p class="release-step-note" id="physical-release-availability">Complete the item review before release.</p>
        @elseif($pickupMissed)
            <p class="release-step-note" id="physical-release-availability">Review the pickup schedule before proceeding.</p>
        @elseif(!$preparationComplete)
            <p class="release-step-note" id="physical-release-availability">Confirm that the items are ready before proceeding.</p>
        @else
            <p class="release-step-note" id="physical-release-availability">Confirm the pickup schedule before release.</p>
        @endif
        <div class="release-confirmation-fields">
            @include('custody.partials.physical-release-form')
        </div>
    </article>
</section>
