<?php

namespace App\Support;

use App\Models\BorrowingRequest;
use App\Services\ReturnMetricsService;

/** Shared Request Records/My Requests presentation, using the Admin status rules. */
final class RequestRecordStatus
{
    public const FILTERS = [
        'FOR_APPROVAL' => 'For Approval',
        'NEEDS_REVISION' => 'Needs Revision',
        'PICKUP_SCHEDULED' => 'Pickup Scheduled',
        'FOR_ISSUANCE' => 'For Issuance',
        'READY_FOR_PICKUP' => 'Ready for Pickup',
        'MISSED_PICKUP' => 'Missed Pickup',
        'ON_CUSTODY' => 'On Custody',
        'OVERDUE' => 'Overdue',
        'RETURNED_LATE' => 'Returned Late',
        'SLDDP' => 'SLDDP',
        'ACCOUNTABILITY_REVIEW' => 'Accountability Review',
        'COMPLETED' => 'Completed',
        'CANCELLED' => 'Cancelled',
        'REJECTED' => 'Rejected',
    ];

    public const ONGOING_FILTERS = [
        'DRAFT' => 'Draft',
        'FOR_APPROVAL' => self::FILTERS['FOR_APPROVAL'],
        'NEEDS_REVISION' => self::FILTERS['NEEDS_REVISION'],
        'PICKUP_SCHEDULED' => self::FILTERS['PICKUP_SCHEDULED'],
        'FOR_ISSUANCE' => self::FILTERS['FOR_ISSUANCE'],
        'READY_FOR_PICKUP' => self::FILTERS['READY_FOR_PICKUP'],
        'MISSED_PICKUP' => self::FILTERS['MISSED_PICKUP'],
        'ON_CUSTODY' => self::FILTERS['ON_CUSTODY'],
        'OVERDUE' => self::FILTERS['OVERDUE'],
        'RETURNED_LATE' => self::FILTERS['RETURNED_LATE'],
        'SLDDP' => self::FILTERS['SLDDP'],
        'ACCOUNTABILITY_REVIEW' => self::FILTERS['ACCOUNTABILITY_REVIEW'],
    ];

    /** A late-return badge is historical; completion comes from the workflow. */
    public static function isOngoing(BorrowingRequest $request): bool
    {
        $request->custody?->setRelation('request', $request);
        $workflow = $request->custody?->workflowStatus() ?? self::forRequest($request);

        return ! in_array($workflow['key'], ['COMPLETED', 'BORROWER_CLEARED', 'CANCELLED', 'REJECTED'], true);
    }

    private const ACCOUNTABILITY_KEYS = [
        'INCIDENT_OPEN', 'OBLIGATION_OPEN', 'FOR_BILLING',
        'BILLING_PENDING', 'BILLING_OPEN', 'BILLING_ISSUED',
        'PAYMENT_VERIFICATION', 'RECEIPT_SUBMITTED',
        'COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING',
        'F2F_PROCESSING', 'RSLDDP_AWAITING_UPLOAD',
        'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'RSLDDP_PAYMENT_REQUIRED',
        'RSLDDP_DISPOSITION_PENDING', 'RSLDDP_COMPLIANCE_VERIFICATION',
        'RSLDDP_FOR_RESOLUTION', 'BORROWING_RESTRICTED', 'LATE_RETURN',
    ];

    private const SLDDP_KEYS = [
        'F2F_PROCESSING', 'COMPLIANCE_RSLDDP_PENDING',
        'RSLDDP_AWAITING_UPLOAD', 'RSLDDP_FOR_ACCOUNTING_PROCESSING',
        'RSLDDP_PAYMENT_REQUIRED', 'RSLDDP_DISPOSITION_PENDING',
        'RSLDDP_COMPLIANCE_VERIFICATION', 'RSLDDP_FOR_RESOLUTION',
    ];

    public static function filters(bool $includeDraft = false): array
    {
        return $includeDraft ? ['DRAFT' => 'Draft', ...self::FILTERS] : self::FILTERS;
    }

    /** @return array{key:string,label:string,group:string} */
    public static function forRequest(BorrowingRequest $request): array
    {
        if ($custody = $request->custody) {
            if (! $custody->relationLoaded('request')) {
                $custody->setRelation('request', $request);
            }
            $workflow = $custody->workflowStatus();
            $key = $workflow['key'];
            // A cancelled request is terminal, not a physical return.
            if ($key === 'CANCELLED') {
                return $workflow;
            }

            if (in_array($key, self::ACCOUNTABILITY_KEYS, true)) {
                return in_array($key, self::SLDDP_KEYS, true)
                    ? ['key' => 'SLDDP', 'label' => 'SLDDP', 'group' => 'attention']
                    : ['key' => 'ACCOUNTABILITY_REVIEW', 'label' => 'Accountability Review', 'group' => 'attention'];
            }
            if (app(ReturnMetricsService::class)->state($custody) === ReturnMetricsService::RETURNED_LATE) {
                return ['key' => 'RETURNED_LATE', 'label' => 'Returned Late', 'group' => 'attention'];
            }

            return match ($key) {
                'PICKUP_EXPIRED' => ['key' => 'MISSED_PICKUP', 'label' => 'Missed Pickup', 'group' => 'attention'],
                'PICKUP_SCHEDULING', 'ITEM_PREPARATION', 'PREPARING_RELEASE' => [
                    'key' => 'FOR_ISSUANCE', 'label' => 'For Issuance', 'group' => 'release',
                ],
                'READY_FOR_RELEASE' => ['key' => 'READY_FOR_PICKUP', 'label' => 'Ready for Pickup', 'group' => 'release'],
                'BORROWED', 'RETURN_PROCESSING', 'LINEN_PENDING', 'GATE_PASS_PENDING' => [
                    'key' => 'ON_CUSTODY', 'label' => 'On Custody', 'group' => 'custody',
                ],
                'BORROWER_CLEARED' => ['key' => 'COMPLETED', 'label' => 'Completed', 'group' => 'completed'],
                default => $workflow,
            };
        }

        $status = $request->status;
        $key = strtoupper($status instanceof \BackedEnum ? $status->value : (string) $status);
        [$key, $label] = match ($key) {
            'DRAFT' => ['DRAFT', 'Draft'],
            'SIGNED', 'SUBMITTED', 'UNDER_SPMU', 'UNDER_GSU', 'UNDER_VPAF' => ['FOR_APPROVAL', 'For Approval'],
            'RETURNED_FOR_REVISION' => ['NEEDS_REVISION', 'Needs Revision'],
            'FINAL_APPROVED_AWAITING_DOWNLOAD', 'APPROVED_READY_FOR_RELEASE' => ['FOR_ISSUANCE', 'For Issuance'],
            'EXPIRED', 'CANCELLED' => ['CANCELLED', 'Cancelled'],
            'REJECTED' => ['REJECTED', 'Rejected'],
            default => [$key, str($key)->replace('_', ' ')->lower()->title()->toString()],
        };

        return ['key' => $key, 'label' => $label, 'group' => 'request'];
    }
}
