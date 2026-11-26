@props(['custody' => null])
@php($inspectionSummary = App\Support\ReturnInspectionSummary::for($custody))
@if($inspectionSummary)
<section class="content-area record-inspection-summary" data-return-inspection-summary aria-labelledby="record-inspection-title">
    <article class="card">
        <header class="record-inspection-heading">
            <x-icon name="information" size="20" />
            <h2 id="record-inspection-title">Return Inspection &amp; Accountability</h2>
            <span class="status-badge {{ $inspectionSummary['flagged'] ? 'status-warning' : 'status-neutral' }}">{{ $inspectionSummary['category'] }}</span>
        </header>
        <div class="record-inspection-content">
            <div class="record-inspection-reason {{ $inspectionSummary['flagged'] ? 'is-flagged' : '' }}">
                <span>{{ $inspectionSummary['flagged'] ? 'Why this record is flagged' : 'Recorded findings' }}</span>
                <p data-inspection-reason>{{ $inspectionSummary['reason'] }}</p>
            </div>
            <div class="record-inspection-results {{ $inspectionSummary['late'] ? 'has-timing' : '' }}">
                <div class="record-inspection-table-wrap">
                    <table class="record-inspection-table">
                        <thead><tr><th scope="col">Item</th><th scope="col">Issued</th><th scope="col">Recorded inspection</th></tr></thead>
                        <tbody>
                            @forelse($inspectionSummary['rows'] as $row)
                                <tr>
                                    <td>{{ $row['item'] }}</td>
                                    <td>{{ $row['issued'] }} {{ $row['unit'] }}</td>
                                    <td>
                                        @if(!$row['inspected'])<span class="record-inspection-missing">No recorded inspection</span>@endif
                                        <div class="record-inspection-findings">
                                            @foreach($row['findings'] as $finding)
                                                <span class="record-inspection-finding is-{{ $finding['tone'] }}" data-finding-source="{{ $finding['source'] }}">
                                                    {{ $finding['source'] === 'Reported' ? 'Reported: ' : '' }}{{ $finding['label'] }} · {{ $finding['quantity'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3">Item-level inspection details are not recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($inspectionSummary['late'])
                    <section class="record-inspection-timing" data-recorded-late-assessment aria-label="Recorded return timing">
                        <h3>Recorded return timing</h3>
                        <dl>
                            <dt>Effective due date</dt><dd>{{ $inspectionSummary['late']['effective_due'] ?: 'Not recorded' }}</dd>
                            <dt>Physical return date</dt><dd>{{ $inspectionSummary['late']['physical_return'] ?: 'Not recorded' }}</dd>
                            <dt>Assessed late days</dt><dd data-assessed-late-days>{{ $inspectionSummary['late']['days'] === null ? 'No finalized assessment recorded' : $inspectionSummary['late']['days'].' SPMU return '.($inspectionSummary['late']['days'] === 1 ? 'day' : 'days') }}</dd>
                        </dl>
                        @if($inspectionSummary['late']['days'] !== null)
                            <p>Finalized assessment based on SPMU open return days, ending at the recorded physical return date.</p>
                        @else
                            <p>The current estimate is not a finalized late-return assessment.</p>
                        @endif
                    </section>
                @endif
            </div>
            <div class="record-inspection-decision"><span>Admin decision</span><strong data-inspection-decision>{{ $inspectionSummary['decision'] }}</strong></div>
            <details class="record-inspection-details" data-inspection-details>
                <summary>Inspection details</summary>
                <div class="record-inspection-detail-content">
                    @forelse($inspectionSummary['inspections'] as $inspection)
                        <section class="record-inspection-entry">
                            <h3>{{ $inspection['reference'] }}</h3>
                            <dl>
                                <dt>Recorded</dt><dd>{{ $inspection['recorded_at'] ?: 'Not recorded' }}</dd>
                                <dt>Recorded by</dt><dd>{{ $inspection['recorded_by'] ?: 'Not recorded' }}</dd>
                                @if($inspection['physical_date'])<dt>Documented physical date</dt><dd>{{ $inspection['physical_date'] }} · {{ $inspection['physical_source'] }}</dd>@endif
                                <dt>Record status</dt><dd>{{ $inspection['status'] }}</dd>
                            </dl>
                            @foreach($inspection['observations'] as $observation)
                                <p><strong>{{ $observation['item'] }}:</strong> {{ $observation['condition'] }} · {{ $observation['quantity'] }}@if($observation['remarks']) — {{ $observation['remarks'] }}@endif</p>
                            @endforeach
                            @if($inspection['remarks'])<p class="record-inspection-remarks">{{ $inspection['remarks'] }}</p>@endif
                        </section>
                    @empty
                        <p>No inspection reference, inspector or recorded inspection date is available.</p>
                    @endforelse
                    @foreach($inspectionSummary['cases'] as $case)
                        <section class="record-inspection-entry">
                            <h3>{{ $case['reference'] }} · {{ $case['finding'] }}</h3>
                            <dl>
                                <dt>Case status</dt><dd>{{ $case['status'] }}</dd>
                                <dt>Admin decision</dt><dd>{{ $case['decision'] }}</dd>
                                @if($case['decided_at'])<dt>Decision recorded</dt><dd>{{ $case['decided_at'] }}@if($case['decided_by']) · {{ $case['decided_by'] }}@endif</dd>@endif
                                @if($case['decision_reason'])<dt>Decision reason</dt><dd>{{ $case['decision_reason'] }}</dd>@endif
                                @if($case['requirement'])<dt>Recorded requirement</dt><dd>{{ $case['requirement'] }}</dd>@endif
                            </dl>
                            @if($case['remarks'])<p class="record-inspection-remarks">{{ $case['remarks'] }}</p>@endif
                        </section>
                    @endforeach
                    @if($inspectionSummary['late'])
                        <dl><dt>Physical return source</dt><dd>{{ $inspectionSummary['late']['source'] }}</dd>
                            @if($inspectionSummary['late']['confirmed_at'])<dt>Assessment confirmed</dt><dd>{{ $inspectionSummary['late']['confirmed_at'] }}@if($inspectionSummary['late']['confirmed_by']) · {{ $inspectionSummary['late']['confirmed_by'] }}@endif</dd>@endif
                        </dl>
                    @endif
                </div>
            </details>
        </div>
    </article>
</section>
@endif
