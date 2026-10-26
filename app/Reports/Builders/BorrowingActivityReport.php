<?php

namespace App\Reports\Builders;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Reports\OperationalStatus;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Reports\RequestReceiptTiming;
use App\Services\AnalyticsService;
use App\Support\ReportAffiliation;
use Illuminate\Support\Collection;

/**
 * Borrowing Activity Report.
 *
 * Request-level activity for the period, reported under the authoritative
 * operational status (see OperationalStatus: custody wins once it exists).
 * Drafts are never reportable. Cancelled/expired requests remain outside the
 * default handled-activity population, but become reportable when the user
 * explicitly selects either status and the request has proof of filing.
 *
 * Borrower affiliation is read from the request version snapshot
 * (division_code / office_unit) captured when the request was filed, never
 * inferred from the borrower's current profile — a borrower who transfers
 * units must not retroactively move their past requests.
 */
class BorrowingActivityReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        /*
         * Eager loading is what keeps this report off the N+1 path: the row
         * loop below touches borrower, currentVersion and custody for every
         * record.
         */
        $excluded = array_map(
            static fn (RequestStatus $status): string => $status->value,
            AnalyticsService::excludedFromActivity()
        );

        $borrower = $filters->get('borrower');
        $status = $filters->get('status');
        $explicitClosedOutcome = in_array($status, [
            RequestStatus::Cancelled->value,
            RequestStatus::Expired->value,
        ], true);

        $requests = BorrowingRequest::query()
            ->join('request_versions', function ($join): void {
                $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                    ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
            })
            ->with(['borrower', 'currentVersion.items', 'custody'])
            ->where(function ($scope) use ($filters, $explicitClosedOutcome): void {
                $scope->whereBetween('request_versions.submitted_at', [$filters->from, $filters->to]);

                /*
                 * CANCELLED / EXPIRED only belong in the report when there is
                 * proof they were actually filed. Their raw status alone does
                 * not prove that: a draft can be cancelled before submission.
                 */
                if (! $explicitClosedOutcome) {
                    $scope->orWhere(function ($legacy) use ($filters): void {
                        $legacy->whereNull('request_versions.submitted_at')
                            ->whereBetween('borrowing_requests.created_at', [$filters->from, $filters->to]);
                    });
                }
            })
            ->when(
                $explicitClosedOutcome,
                fn ($query) => $query->where('borrowing_requests.status', $status),
                fn ($query) => $query->whereNotIn('borrowing_requests.status', $excluded)
            )
            ->when(
                $borrower !== null,
                fn ($query) => $query->where('borrowing_requests.borrower_user_id', (int) $borrower)
            )
            ->select('borrowing_requests.*')
            ->orderByRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) DESC')
            ->get();

        $division = $filters->get('division');
        $unit = $filters->get('unit');

        $rows = $requests
            ->map(function (BorrowingRequest $request): array {
                [$statusCode, $statusLabel] = OperationalStatus::forRequest($request);
                $version = $request->currentVersion;

                return array_merge([
                    '_status_code' => $statusCode,
                    '_borrower_user_id' => (int) $request->borrower_user_id,
                    '_division_code' => ReportAffiliation::divisionCode($version),
                    '_office_unit' => ReportAffiliation::employeeUnit($version),
                    '_link' => route('requests.show', $request),
                    '_request_id' => $request->id,
                    '_tone_status' => self::statusTone($statusCode),

                    'request_no' => (string) $request->request_no,
                    'borrower' => (string) ($request->borrower?->full_name ?? ''),
                    'division' => ReportAffiliation::division($version),
                    'office_unit' => ReportAffiliation::display($version),
                    'affiliation' => implode("\n", array_filter(array_unique([
                        ReportAffiliation::display($version),
                        ReportAffiliation::division($version),
                    ]))),
                    'requested_equipment' => $version?->items->map(fn ($item): string => trim($item->requested_quantity.' '.$item->unit_snapshot).' - '.$item->description_snapshot
                    )->implode("\n") ?? '',
                    'purpose_event' => (string) ($version?->purpose_event ?? ''),
                    'schedule_date' => $this->date(
                        $version?->schedule_date ?: $version?->needed_from
                    ),
                    'return_date' => $this->date(
                        $version?->return_date ?: $version?->return_due_at
                    ),
                    'status' => OperationalStatus::label($statusCode, $statusLabel),
                    'filed_at' => $this->dateTime($version?->submitted_at ?: $request->created_at),
                    'approved_at' => $this->dateTime($request->final_approved_at),
                ], RequestReceiptTiming::fields($version?->submitted_at, $request->custody?->released_at));
            })
            ->when(
                $division !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_division_code'] === $division
                )
            )
            ->when(
                $unit !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => strcasecmp($row['_office_unit'], (string) $unit) === 0
                )
            )
            ->when(
                $status !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['_status_code'] === $status
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'borrowing',
            label: ReportCatalogue::definition('borrowing')['label'],
            columns: [
                ['key' => 'request_no', 'label' => 'Control No.', 'width' => 124],
                ['key' => 'borrower', 'label' => 'Name', 'width' => 88],
                ['key' => 'affiliation', 'label' => 'Office / Unit / Colleges / Division', 'width' => 137],
                ['key' => 'requested_equipment', 'label' => 'Requested Equipment', 'width' => 118],
                ['key' => 'schedule_date', 'label' => 'Dates Needed From (DD/MM/YYYY)', 'heading' => 'From (DD/MM/YYYY)', 'group' => 'Dates Needed', 'width' => 60],
                ['key' => 'return_date', 'label' => 'Dates Needed To (DD/MM/YYYY)', 'heading' => 'To (DD/MM/YYYY)', 'group' => 'Dates Needed', 'width' => 60],
                ['key' => 'received_date', 'label' => 'Received Date', 'heading' => 'Date', 'group' => 'Received', 'width' => 60],
                ['key' => 'received_time', 'label' => 'Received Time', 'heading' => 'Time', 'group' => 'Received', 'width' => 48],
                ['key' => 'released_date', 'label' => 'Released Date', 'heading' => 'Date', 'group' => 'Released', 'width' => 60],
                ['key' => 'released_time', 'label' => 'Released Time', 'heading' => 'Time', 'group' => 'Released', 'width' => 48],
                ['key' => 'total_time_allocated', 'label' => 'Total Time Allocated', 'width' => 64],
                ['key' => 'status', 'label' => 'Remarks', 'badge' => true, 'width' => 73],
            ],
            rows: $rows,
            summary: $this->summarize($rows),
            meta: ['table_notes' => [
                'Received = request submitted to SPMU. Total Time Allocated = actual release minus receipt of the current request version (calendar elapsed time, to the minute).',
                'Dates: DD/MM/YYYY. Times: '.config('app.timezone').'. A dash (-) means not recorded or not yet released; duration is unavailable if either timestamp is missing or release precedes receipt.',
            ]],
        );
    }

    /**
     * Counts derived from the same rows the table shows, so a summary figure
     * can never disagree with the records underneath it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarize(Collection $rows): array
    {
        $counts = $rows->countBy(fn (array $row): string => (string) $row['_status_code']);

        $summary = [
            'Total requests' => $rows->count(),
            'Under SPMU Review' => (int) ($counts['UNDER_SPMU'] ?? 0),
            'Ready for Release' => (int) ($counts['APPROVED_READY_FOR_RELEASE'] ?? 0)
                + (int) ($counts['PREPARING_RELEASE'] ?? 0),
            'Pickup Scheduled' => (int) ($counts['PICKUP_SCHEDULED'] ?? 0),
            'Released / On Custody' => (int) ($counts['ACTIVE'] ?? 0),
            'Return Processing' => (int) ($counts['RETURN_PROCESSING'] ?? 0),
            'Overdue' => (int) ($counts['OVERDUE'] ?? 0),
            'Incident Open' => (int) ($counts['INCIDENT_OPEN'] ?? 0),
            'Obligation Open' => (int) ($counts['OBLIGATION_OPEN'] ?? 0),
            'Completed' => (int) ($counts['COMPLETED'] ?? 0),
            'Returned for Revision' => (int) ($counts['RETURNED_FOR_REVISION'] ?? 0),
            'Rejected' => (int) ($counts['REJECTED'] ?? 0),
            'Cancelled' => (int) ($counts['CANCELLED'] ?? 0),
            'Expired' => (int) ($counts['EXPIRED'] ?? 0),
            'Draft' => (int) ($counts['DRAFT'] ?? 0),
        ];

        return array_filter(
            $summary,
            fn (int $count, string $label): bool => $label === 'Total requests' || $count > 0,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Badge tone for a reported operational status.
     *
     * Tone is restraint only: every badge still reads as its own words,
     * so the report does not depend on colour to be understood.
     */
    public static function statusTone(string $status): string
    {
        return match ($status) {
            'COMPLETED', 'ACTIVE', 'APPROVED_READY_FOR_RELEASE' => 'positive',
            'OVERDUE', 'REJECTED', 'INCIDENT_OPEN' => 'critical',
            'RETURNED_FOR_REVISION', 'OBLIGATION_OPEN', 'RETURN_PROCESSING' => 'attention',
            'UNDER_SPMU', 'PREPARING_RELEASE', 'PICKUP_SCHEDULED' => 'progress',
            default => 'neutral',
        };
    }

    private function date(mixed $value): string
    {
        return $value ? $value->format('d/m/Y') : '-';
    }

    private function dateTime(mixed $value): string
    {
        return $value ? $value->format('d M Y, g:i A') : '';
    }
}
