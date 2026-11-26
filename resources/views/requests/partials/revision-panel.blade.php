@php
    $canReviseRequest = $isBorrower
        && (int) auth()->id() === (int) $borrowingRequest->borrower_user_id;
@endphp

<section class="content-area request-revision-area" @if($canReviseRequest) id="borrower-next-action" @endif>
    <div class="request-revision-panel {{ $canReviseRequest ? 'is-owner' : 'is-reviewer' }}" data-revision-panel>
        <details class="request-revision-details" data-revision-details>
            <summary class="request-revision-summary">
                <span class="request-revision-heading">
                    <x-icon :name="$canReviseRequest ? 'edit' : 'clock'" size="17" />
                    <strong>{{ $canReviseRequest ? 'Revision required' : 'Awaiting borrower resubmission' }}</strong>
                </span>
                <span class="request-revision-toggle">
                    <span class="revision-details-show">View details</span>
                    <span class="revision-details-hide">Hide details</span>
                    <x-icon name="chevron-down" size="16" />
                </span>
            </summary>
            <div class="request-revision-body">
                <strong>{{ $canReviseRequest ? 'Required corrections' : 'Revision remarks' }}</strong>
                <p class="request-revision-remarks">{{ $revisionRemarks !== '' ? $revisionRemarks : 'No review remarks were recorded.' }}</p>
                @if($canReviseRequest)
                    <p class="request-revision-instruction">Update the request, then E-sign and submit it again.</p>
                @endif
            </div>
        </details>
        @if($canReviseRequest)
            <a class="button primary ui-pressable" href="{{ route('requests.edit', $borrowingRequest) }}" data-revision-action>Revise Request</a>
        @endif
    </div>
</section>

<style>
.request-revision-area { margin-bottom: 12px; }
.request-revision-panel {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    gap: 12px;
    padding: 10px 14px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-left: 3px solid var(--border);
    border-radius: 10px;
}
.request-revision-panel.is-owner {
    background: color-mix(in srgb, var(--warning) 8%, var(--surface-elevated));
    border-left-color: var(--warning);
}
.request-revision-panel.is-reviewer { grid-template-columns: minmax(0, 1fr); }
.request-revision-details { min-width: 0; }
.request-revision-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 34px;
    list-style: none;
    cursor: pointer;
}
.request-revision-summary::-webkit-details-marker { display: none; }
.request-revision-heading { display: flex; align-items: center; gap: 8px; min-width: 0; }
.request-revision-heading strong { color: var(--heading); font-size: 14px; line-height: 1.4; }
.request-revision-heading svg { flex-shrink: 0; }
.request-revision-toggle { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; color: var(--primary); font-size: 12px; font-weight: 700; }
.request-revision-toggle svg { flex-shrink: 0; transition: transform .15s ease; }
.request-revision-details .revision-details-hide { display: none; }
.request-revision-details[open] .revision-details-show { display: none; }
.request-revision-details[open] .revision-details-hide { display: inline; }
.request-revision-details[open] .request-revision-toggle svg { transform: rotate(180deg); }
.request-revision-body { border-top: 1px solid var(--border); margin-top: 8px; padding-top: 10px; }
.request-revision-body > strong { color: var(--heading); font-size: 12px; }
.request-revision-remarks { margin: 4px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.5; }
.request-revision-instruction { margin: 8px 0 0; font-size: 12px; }
.request-revision-panel > .button { margin: 0; min-height: 34px; padding: 8px 12px; font-size: 12px; line-height: 1.4; white-space: nowrap; }
@media (max-width: 600px) {
    .request-revision-panel.is-owner { grid-template-columns: minmax(0, 1fr); gap: 8px; }
    .request-revision-panel > .button { justify-self: start; }
}
@media (pointer: coarse) {
    .request-revision-summary, .request-revision-panel > .button { min-height: 44px; }
}
@media (prefers-reduced-motion: reduce) {
    .request-revision-toggle svg { transition: none; }
}
</style>
