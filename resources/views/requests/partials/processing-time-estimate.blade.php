@php
    $estimate = app(\App\Services\ProcessingTimeEstimateService::class)->estimate($borrowingRequest);
    $etaActor = auth()->user();
    $etaIsStaff = strtoupper((string) session('active_workspace')) === 'SPMU';
    $etaIsOfficer = $etaActor?->access_classification === \App\Enums\AccessClassification::SpmuOfficer;
    $etaCanApprove = $etaActor?->access_classification === \App\Enums\AccessClassification::SpmuHead
        || ($etaIsOfficer && $etaActor?->activeDelegationFor('SPMU') !== null);
@endphp

<section class="content-area" aria-label="Estimated request processing time" id="processing-time-estimate"
    data-estimate-url="{{ route('requests.processing-estimate', $borrowingRequest) }}">
    <article class="card" style="padding:18px 20px;">
        <header style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">
            <div>
                <p class="eyebrow">Borrowing progress</p>
                <h2 style="margin:4px 0 8px;font-size:1.1rem;">Estimated processing time</h2>
                <p class="meta" style="margin:0;">Normal active work: <strong>{{ $estimate['min_total'] }}–{{ $estimate['max_total'] }} minutes</strong> · Typical: {{ $estimate['typical_total'] }} minutes</p>
            </div>
            <div style="text-align:right;">
                <strong data-eta-progress-text>{{ $estimate['progress'] }}% complete</strong>
                <p class="meta" style="margin:4px 0 0;" data-eta-remaining>{{ $estimate['remaining_min'] }}–{{ $estimate['remaining_max'] }} min active work remaining</p>
            </div>
        </header>
        <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $estimate['progress'] }}"
            data-eta-progress style="height:8px;background:var(--border, #dce3e9);border-radius:12px;overflow:hidden;margin:14px 0;">
            <div data-eta-progress-bar style="width:{{ $estimate['progress'] }}%;height:100%;background:var(--primary,#2472b7);transition:width .25s;"></div>
        </div>
        <ol style="list-style:none;padding:0;margin:0;display:grid;gap:9px;">
            @foreach($estimate['stages'] as $stage)
                @php
                    $etaCanStart = $etaIsStaff && $stage['can_start']
                        && ($stage['key'] === 'HEAD_APPROVAL' ? $etaCanApprove : $etaIsOfficer);
                @endphp
                <li data-eta-stage="{{ $stage['key'] }}"
                    data-eta-start="{{ $stage['started_at'] ?? '' }}"
                    data-eta-finished="{{ $stage['finished_at'] ?? '' }}"
                    data-eta-max="{{ $stage['max'] }}"
                    style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:11px 12px;border:1px solid var(--border,#dce3e9);border-radius:10px;">
                    <div>
                        <strong>{{ $stage['label'] }}</strong>
                        <div class="meta" style="margin-top:3px;">
                            {{ $stage['min'] }}–{{ $stage['max'] }} min active ·
                            <span data-eta-stage-status>{{ $stage['status'] }}</span>
                            <span data-eta-stage-queue>
                                @if($stage['queue_minutes'] !== null)
                                    · Queue/wait {{ number_format($stage['queue_minutes'], 1) }} min (wall-clock)
                                @endif
                            </span>
                        </div>
                        <small data-eta-stage-countdown class="meta">
                            @if($stage['status'] === 'In Progress' || $stage['status'] === 'Delayed')
                                Active stage countdown is running.
                            @elseif($stage['status'] === 'Completed' && $stage['elapsed_active_min'] === null)
                                Completed; active handling time was not recorded.
                            @endif
                        </small>
                    </div>
                    @if($etaCanStart)
                        <form method="post" action="{{ route('requests.processing-stages.start', $borrowingRequest) }}">
                            @csrf
                            <input type="hidden" name="stage" value="{{ $stage['key'] }}">
                            <button class="button secondary ui-pressable" type="submit">Start active timer</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
        <div class="meta" style="margin-top:14px;">
            <strong>Expected release:</strong>
            <span data-eta-release>
                {{ $estimate['expected_release']
                    ? \Carbon\Carbon::parse($estimate['expected_release'])->format('d M Y, g:i A')
                    : 'Not available yet' }}
            </span>
            <p data-eta-note style="margin:4px 0 0;">{{ $estimate['release_note'] }}</p>
            <p style="margin:7px 0 0;">
                Estimates are targets, not release guarantees. Countdown begins only when authorized personnel select
                <em>Start active timer</em>. Pending queues, office closures, incomplete requirements, and equipment
                pickup availability may extend the actual turnaround. Exceeding five minutes flags a review stage as
                delayed but never automatically approves or rejects the request.
            </p>
        </div>
    </article>
</section>

@once
<script>
(() => {
    const root = document.getElementById('processing-time-estimate');
    if (!root) return;
    const stages = new Map();
    root.querySelectorAll('[data-eta-stage]').forEach(el => stages.set(el.dataset.etaStage, el));
    const formatClock = seconds => {
        const abs = Math.abs(Math.floor(seconds));
        return `${Math.floor(abs / 60)}:${String(abs % 60).padStart(2, '0')}`;
    };
    const tick = () => {
        for (const el of stages.values()) {
            const start = el.dataset.etaStart;
            if (!start || el.dataset.etaFinished) continue;
            const seconds = Math.floor((Date.now() - Date.parse(start)) / 1000);
            if (!Number.isFinite(seconds)) continue;
            const left = Number(el.dataset.etaMax) * 60 - seconds;
            const count = el.querySelector('[data-eta-stage-countdown]');
            const status = el.querySelector('[data-eta-stage-status]');
            count.textContent = left < 0
                ? `Over active target by ${formatClock(left)} (no automatic decision)`
                : `Active target remaining: ${formatClock(left)}`;
            status.textContent = left < 0 ? 'Delayed' : 'In Progress';
        }
    };
    const refresh = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(root.dataset.estimateUrl, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) return;
            const data = await response.json();
            for (const stage of data.stages ?? []) {
                const el = stages.get(stage.key);
                if (!el) continue;
                el.dataset.etaStart = stage.started_at || '';
                el.dataset.etaFinished = stage.finished_at || '';
                const status = el.querySelector('[data-eta-stage-status]');
                status.textContent = stage.status;
                const queue = el.querySelector('[data-eta-stage-queue]');
                queue.textContent = stage.queue_minutes === null
                    ? '' : ` · Queue/wait ${stage.queue_minutes.toFixed(1)} min (wall-clock)`;
                if (stage.finished_at) {
                    el.querySelector('[data-eta-stage-countdown]').textContent =
                        stage.elapsed_active_min === null
                            ? 'Completed; active handling time was not recorded.'
                            : `Recorded active handling: ${stage.elapsed_active_min.toFixed(1)} min`;
                }
            }
            root.querySelector('[data-eta-progress]').setAttribute('aria-valuenow', String(data.progress));
            root.querySelector('[data-eta-progress-bar]').style.width = data.progress + '%';
            root.querySelector('[data-eta-progress-text]').textContent = data.progress + '% complete';
            root.querySelector('[data-eta-remaining]').textContent =
                `${data.remaining_min}–${data.remaining_max} min active work remaining`;
            root.querySelector('[data-eta-release]').textContent = data.expected_release
                ? new Date(data.expected_release).toLocaleString() : 'Not available yet';
            root.querySelector('[data-eta-note]').textContent = data.release_note;
            tick();
        } catch (_) {
            // A stale/offline estimate must not block the existing request workflow.
        }
    };
    tick();
    window.setInterval(tick, 1000);
    window.setInterval(refresh, 60000);
})();
</script>
@endonce
