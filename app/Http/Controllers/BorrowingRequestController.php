<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\NotificationEvent;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestSupportingDocument;
use App\Models\RequestVersion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BorrowerRecordListService;
use App\Services\BorrowerRequestAffiliationService;
use App\Services\BorrowingRequestNumberService;
use App\Services\InventoryService;
use App\Services\OperationalCalendarService;
use App\Services\ProtectedFileService;
use App\Services\RequestWorkflowService;
use App\Services\SubmissionEvidenceService;
use App\Services\TransactionAccountabilityHistoryService;
use App\Support\RequestRecordStatus;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

// This is the controller for borrowing request forms, records, and borrower actions.
class BorrowingRequestController extends Controller
{
    private const MAX_UPLOAD_MB = 64;

    /**
     * Status filter options on My Requests, in lifecycle order. Each value is
     * a badge key from BorrowingRequest::borrowerRecordStatus().
     */
    public const BORROWER_REQUEST_STATUS_FILTERS = RequestRecordStatus::ONGOING_FILTERS;

    /**
     * Return active Office / Unit assignments that ICTU has authorized this
     * borrower to represent. The primary assignment is always included.
     */
    private function authorizedRequestingUnits(User $user)
    {
        if (app(BorrowerRequestAffiliationService::class)->isExternal($user)) {
            return collect();
        }
        $unitIds = $user->authorizedOrganizationalUnits()
            ->pluck('organizational_units.id')
            ->push($user->organizational_unit_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return OrganizationalUnit::query()
            ->activeSelectable()
            ->whereIn('id', $unitIds)
            ->orderBy('unit_name')
            ->get();
    }

    /**
     * @return list<array{id:int,name:string,division_code:string,division_label:string,is_primary:bool}>
     */
    private function requestingUnitOptions(User $user): array
    {
        return $this->authorizedRequestingUnits($user)
            ->map(function (OrganizationalUnit $unit) use ($user): ?array {
                $divisionCode = $unit->divisionCode();
                $divisionLabel = $unit->divisionLabel();

                if (! $divisionCode || ! $divisionLabel) {
                    return null;
                }

                return [
                    'id' => (int) $unit->id,
                    'name' => $unit->unit_name,
                    'division_code' => $divisionCode,
                    'division_label' => $divisionLabel,
                    'is_primary' => (int) $unit->id === (int) $user->organizational_unit_id,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function requestingUnitNotice(User $user): string
    {
        if ($this->authorizedRequestingUnits($user)->isNotEmpty()) {
            return 'Choose one of the Office / College / Unit assignments authorized for your account by ICTU.';
        }

        $assignedUnitIds = $user->authorizedOrganizationalUnits()
            ->pluck('organizational_units.id')
            ->push($user->organizational_unit_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $hasHistoricalAssignment = $assignedUnitIds->isNotEmpty()
            && OrganizationalUnit::query()
                ->whereIn('id', $assignedUnitIds)
                ->get()
                ->contains(fn (OrganizationalUnit $unit) => ! $unit->isSelectable());

        return $hasHistoricalAssignment
            ? 'Your organizational assignment is historical or inactive. Contact the SPMU Admin to assign an active Office / College / Unit before filing a new request.'
            : 'No borrowing Office / College / Unit is authorized for this account. Contact the SPMU Admin.';
    }

    public function index(Request $request): View
    {
        $query = BorrowingRequest::query()
            ->with([
                'borrower',
                'currentVersion.items',
                'currentVersion.approvalSteps',
                'custody.lines.requestItem.inventoryItem',
                'custody.returns',
                'custody.incidents',
                'custody.laundryJob',
                'custody.overdueCase',
            ])
            ->latest();

        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        if (\Illuminate\Support\Facades\Schema::hasTable('request_number_aliases')) $query->with('numberAliases');
        if ($workspace === 'BORROWER') {
            $query->where(
                'borrower_user_id',
                $request->user()->id
            );
        } elseif ($workspace === 'SPMU') {
            $user = $request->user();

            if (
                $user->access_classification
                    === AccessClassification::SpmuOfficer
            ) {
                $isDelegatedOfficer =
                    $user->activeDelegationFor('SPMU') !== null;

                /*
                 * ACTION OFFICER RECORD VISIBILITY
                 *
                 * During active review:
                 * - show only requests currently waiting for AO verification
                 *   (sequence 1).
                 *
                 * After final approval:
                 * - show again because AO handles pickup, release, return,
                 *   Gate Pass, Laundry, and custody operations.
                 *
                 * A formally delegated officer may additionally see the
                 * Head/Admin decision step while that delegation is active.
                 */
                $query->where(
                    function ($query) use ($isDelegatedOfficer): void {
                        $query
                            ->whereNotNull('final_approved_at')
                            ->orWhere(
                                function ($query) use ($isDelegatedOfficer): void {
                                    $query
                                        ->where(
                                            'status',
                                            RequestStatus::UnderSpmu
                                        )
                                        ->where(
                                            function ($query) use ($isDelegatedOfficer): void {
                                                $query->whereHas(
                                                    'currentVersion.approvalSteps',
                                                    fn ($step) => $step
                                                        ->where(
                                                            'stage_code',
                                                            'SPMU'
                                                        )
                                                        ->where(
                                                            'sequence_no',
                                                            1
                                                        )
                                                        ->whereIn(
                                                            'decision',
                                                            [
                                                                'PENDING',
                                                                'RECEIVED',
                                                            ]
                                                        )
                                                );

                                                if ($isDelegatedOfficer) {
                                                    $query->orWhereHas(
                                                        'currentVersion.approvalSteps',
                                                        fn ($step) => $step
                                                            ->where(
                                                                'stage_code',
                                                                'SPMU'
                                                            )
                                                            ->where(
                                                                'sequence_no',
                                                                2
                                                            )
                                                            ->whereIn(
                                                                'decision',
                                                                [
                                                                    'PENDING',
                                                                    'RECEIVED',
                                                                ]
                                                            )
                                                    );
                                                }
                                            }
                                        );
                                }
                            );
                    }
                );
            } elseif (
                $user->access_classification
                    === AccessClassification::SpmuHead
            ) {
                /*
                 * HEAD / ADMIN RECORD VISIBILITY
                 *
                 * While UNDER_SPMU:
                 * - show only requests that already have sequence 2
                 *   (Head/Admin final decision).
                 *
                 * Therefore an OFF-CAMPUS request still waiting for AO
                 * verification is not yet visible as an active Admin record.
                 *
                 * Historical records remain available for oversight.
                 */
                $query->where(function ($query): void {
                    $query
                        ->where(function ($historical): void {
                            $historical
                                ->where('status', '!=', RequestStatus::UnderSpmu)
                                ->where(function ($visible): void {
                                    $visible->whereNotNull('final_approved_at')
                                        ->orWhereHas(
                                            'currentVersion.approvalSteps',
                                            fn ($step) => $step->where('stage_code', 'SPMU')
                                        );
                                });
                        })
                        ->orWhereHas(
                            'currentVersion.approvalSteps',
                            fn ($step) => $step
                                ->where('stage_code', 'SPMU')
                                ->where('sequence_no', 2)
                                ->whereIn('decision', ['PENDING', 'RECEIVED'])
                        );
                });
            }
        }

        $activeCustodiesFilter = $workspace === 'SPMU'
            && $request->user()->access_classification === AccessClassification::SpmuHead
            && $request->query('kpi') === 'active_custodies';
        if ($activeCustodiesFilter) {
            $query->whereHas('custody', fn ($custody) => $custody->whereNotNull('released_at')
                ->whereNotIn('status', ['CLOSED', 'CANCELLED']));
        }

        /*
         * The borrower workspace renders the paginated My Requests list, with
         * search, status, and sort applied on the server so they cover every
         * page. Staff record views keep the full unpaginated table.
         */
        if ($workspace === 'BORROWER') {
            $lists = app(BorrowerRecordListService::class);
            $filters = $lists->filters($request, true);
            $query = $lists->query((int) $request->user()->id, true);
            $lists->apply($query, $filters, true);

            return view('requests.index', [
                'activeCustodiesFilter' => $activeCustodiesFilter,
                'requests' => $query->paginate($filters['per_page'])->withQueryString(),
                'borrowerRequestFilters' => $filters,
                'borrowerRequestStatusFilters' => self::BORROWER_REQUEST_STATUS_FILTERS,
            ]);
        }

        return view(
            'requests.index',
            [
                'activeCustodiesFilter' => $activeCustodiesFilter,
                'requests' => $query->get(),
            ]
        );
    }

    public function create(Request $request, \App\Services\BorrowerObligationService $obligations): View
    {
        /*
         * Organizational identity is centrally managed by ICTU. A borrower
         * with one authorized unit sees it read-only. A borrower with multiple
         * official assignments may choose only from those authorized units.
         */
        $borrower = $request->user()->loadMissing(['organizationalUnit', 'authorizedOrganizationalUnits']);
        $requestingUnitOptions = $this->requestingUnitOptions($borrower);

        /*
         * Server-side submission is the real gate (assertEligibleToBorrow()
         * in store()/RequestWorkflowService::submit()) - this is only the
         * same reasons shown up front so a restricted borrower is not left
         * to fill out the whole multi-step form before discovering why it
         * was rejected at the very end.
         */
        $borrowingBlockedReasons = $obligations->blockingReasons($borrower->id);

        $prefillRequestingUnitId = collect($requestingUnitOptions)
            ->firstWhere('is_primary', true)['id']
            ?? ($requestingUnitOptions[0]['id'] ?? null);

        $prefillOption = collect($requestingUnitOptions)
            ->firstWhere('id', $prefillRequestingUnitId);

        $prefillDivisionCode = $prefillOption['division_code'] ?? null;
        $prefillOfficeUnit = $prefillOption['name'] ?? null;

        return view(
            'requests.form',
            [
                'borrowingRequest' => new BorrowingRequest,
                'version' => new RequestVersion,

                ...app(BorrowerRequestAffiliationService::class)->formContext($borrower),

                'borrowingBlockedReasons' => $borrowingBlockedReasons,

                'officeUnitsByDivision' => [],
                'requestingUnitOptions' => $requestingUnitOptions,
                'requestingUnitNotice' => $this->requestingUnitNotice($borrower),
                'prefillRequestingUnitId' => $prefillRequestingUnitId,
                'prefillDivisionCode' => $prefillDivisionCode,
                'prefillOfficeUnit' => $prefillOfficeUnit,
                'maxUploadMb' => $this->uploadLimitMb(),

                'items' => InventoryItem::with(['unit', 'category'])
                    ->where('active', true)
                    ->where('borrowable', true)
                    ->where('condition_code', 'SERVICEABLE')
                    ->orderBy('unique_description')
                    ->get(),
            ]
        );
    }

    public function operationalDateAvailability(
        Request $request,
        OperationalCalendarService $calendar
    ): JsonResponse {
        // SPMU_MONTH_CALENDAR_START
        if ($request->has('month')) {
            $values = $request->validate([
                'month' => ['required', 'date_format:Y-m']
            ]);

            $monthStart = Carbon::parse(
                $values['month'].'-01',
                config('app.timezone') ?: 'Asia/Manila'
            )->startOfMonth();

            $profiles = $calendar->profilesForRange(
                $monthStart,
                $monthStart->copy()->endOfMonth()
            );

            return response()->json([
                'dates' => $profiles->mapWithKeys(
                    fn (array $profile, string $date): array => [
                        $date => [
                            'open' => (bool) ($profile['is_open'] ?? false),
                            'reason' => $profile['reason'] ?? null,
                            'special' => ($profile['source'] ?? '') === 'EXCEPTION',
                        ],
                    ]
                )->all(),
            ]);
        }
        // SPMU_MONTH_CALENDAR_END
        $data = $request->validate([
            'schedule_date' => ['required', 'date'],
            'return_date' => ['required', 'date'],
        ]);

        $timezone = config('app.timezone') ?: 'Asia/Manila';

        return response()->json([
            'schedule_date' => $this->operationalDateResult(
                $calendar,
                Carbon::parse($data['schedule_date'], $timezone)->startOfDay(),
                'Items Needed From'
            ),
            'return_date' => $this->operationalDateResult(
                $calendar,
                Carbon::parse($data['return_date'], $timezone)->startOfDay(),
                'Expected Return Date'
            ),
        ]);
    }

    public function store(
        Request $request,
        InventoryService $inventory,
        ProtectedFileService $files,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $data = $this->validateRequest($request);

        if ($request->input('intent') === 'submit') {
            $this->validateESignatureConfirmation($request);
        }

        $user = $request->user();

        /*
         * Same eligibility gate submit() enforces (overdue custody, open
         * accountability cases, outstanding billing, active restrictions) -
         * checked here too so a restricted borrower cannot even create a
         * new request/draft, not only be stopped at final submission.
         */
        $workflow->assertEligibleToBorrow($user);

        $isSubmission = $request->input('intent') === 'submit';

        /*
         * DUPLICATE-REQUEST PROTECTION
         * ----------------------------
         * Creation, supporting-document upload, and submission are ONE atomic
         * unit. Submission can still fail validation for reasons that are only
         * known at that point (missing scanned letter, active restriction,
         * outstanding custody, closed pickup date, item no longer serviceable).
         *
         * Previously the request row was already committed when that happened,
         * so the borrower was returned to the form with an orphaned DRAFT and
         * every corrected retry created another borrowing request. Rolling the
         * whole unit back means a failed submission leaves no request at all,
         * and the borrower's retry creates exactly one.
         */
        $borrowingRequest = DB::transaction(
            function () use (
                $data,
                $user,
                $inventory,
                $request,
                $files,
                $workflow,
                $isSubmission
            ): BorrowingRequest {
                $borrowingRequest =
                    app(BorrowingRequestNumberService::class)->createHeader([
                        'borrower_user_id' =>
                            $user->id,

                        'accountable_unit_id' =>
                            $data['requesting_organizational_unit_id'],

                        'current_version_no' =>
                            1,

                        'status' =>
                            RequestStatus::Draft,
                    ]);

                $version =
                    $borrowingRequest
                        ->versions()
                        ->create(
                            $this->versionData(
                                $data,
                                $user->id,
                                1
                            )
                        );

                $this->saveItems(
                    $version,
                    $data,
                    $inventory
                );

                $borrowingRequest->load('currentVersion');

                if (! $isSubmission) {
                    app(AuditService::class)->record('REQUEST_DRAFT_CREATED', $borrowingRequest, after: [
                        'request_version_id' => $version->id,
                        'affiliation_type' => $version->affiliation_type->value,
                        'external_company_name' => $version->external_company_name,
                        'accountable_unit_id' => $borrowingRequest->accountable_unit_id,
                        'division_code' => $version->division_code,
                        'office_unit' => $version->office_unit,
                    ]);
                }

                $this->syncSupportingDocuments(
                    $request,
                    $borrowingRequest,
                    $files,
                    (bool) (
                        $data['represents_student_activity']
                        ?? false
                    )
                );

                if ($isSubmission) {
                    $workflow->submit(
                        $borrowingRequest->fresh(),
                        $request->user(),
                        $request->boolean('confirm_e_signature')
                    );
                }

                return $borrowingRequest;
            },
            3
        );

        if ($isSubmission) {
            return redirect()
                ->route('requests.show', $borrowingRequest)
                ->with(
                    'status',
                    'Request version E-signed and submitted with the required scanned document(s) to SPMU for verification.'
                );
        }

        return redirect()
            ->route('requests.index')
            ->with(
                'status',
                'Draft saved. You can continue editing and submit when the required scanned document(s) are complete.'
            );
    }

    public function show(
        Request $request,
        BorrowingRequest $borrowingRequest,
        TransactionAccountabilityHistoryService $transactionHistory
    ): View|RedirectResponse {
        $canVerify = $this->canVerifyRequest($request, $borrowingRequest);
        $canDecide = $this->canDecideApproval($request, $borrowingRequest);

        $this->authorizeRequest(
            $request,
            $borrowingRequest,
            $canVerify,
            $canDecide
        );

        /*
         * A DRAFT has no workflow to show: nothing has been submitted, so the
         * detail page would render a progress timeline for a request that has
         * not entered the process. Every entry point - My Requests, the
         * dashboard, a bookmarked URL - therefore lands on the editor, which
         * reopens the stage the borrower stopped on.
         *
         * authorizeRequest() has already run, and for a DRAFT the only branch
         * that can pass it is the owning borrower (there is no approval
         * history and the status is not UNDER_SPMU), so this redirect cannot
         * widen access: a non-owner has been aborted above.
         */
        if ($borrowingRequest->status === RequestStatus::Draft) {
            return redirect()->route('requests.edit', $borrowingRequest);
        }

        $reviewMode = match (true) {
            $canVerify => 'ACTION_OFFICER_VERIFICATION',
            $canDecide => 'HEAD_DECISION',
            default => null,
        };

        $approvalStage =
            $this->approvalStage(
                $borrowingRequest->status
            );

        $borrowingRequest->load([
            'borrower.organizationalUnit',
            'accountableUnit',

            'currentVersion.items.inventoryItem.unit',

            'currentVersion.approvalSteps.approver',

            'currentVersion.documents.downloads',

            'currentVersion.supportingDocuments.file',
            'currentVersion.approvalSteps',

            'currentVersion.supportingDocuments.uploader',

            'statusHistory.actor',
            'latestCancellation.requestedBy',

            'custody.lines.requestItem.inventoryItem',
            'custody.incidents',
            'custody.overdueCase',
            'custody.pickupScheduledBy',
            'custody.preparedBy',
            'custody.releasedBy',
            'custody.returns.receivedBy',
            'custody.returns.lines.custodyLine.requestItem.inventoryItem',
            'custody.laundryJob.formVerifier',
            'custody.laundryJob.latestEvidence.file',
            'custody.gatePass.uploadedBy',
            'custody.gatePass.verifiedBy',
            'custody.gatePass.accomplishedFile',
        ]);

        $accountabilityHistory = $transactionHistory->forCustody($borrowingRequest->custody);

        /*
         * The request detail timeline is shared by Borrower, Action Officer,
         * and Head/Admin. Pull only significant operational events that are
         * not represented by request status history or the persisted return /
         * accountability records already loaded above. Keeping this list
         * narrow prevents duplicate history rows while ensuring newer pickup
         * and Operational Calendar branches remain visible in one transaction
         * history.
         */
        $operationalHistoryAuditEvents = collect();
        $operationalHistoryNotificationEvents = collect();

        if ($borrowingRequest->custody) {
            $custody = $borrowingRequest->custody;

            $operationalHistoryAuditEvents = AuditEvent::query()
                ->with('actor')
                ->where('record_type', CustodyTransaction::class)
                ->where('record_id', $custody->id)
                ->whereIn('action_code', [
                    'PICKUP_SCHEDULE_AUTOMATICALLY_ACTIVATED',
                    'PICKUP_SCHEDULE_CONFIRMED',
                    'PICKUP_WINDOW_EXPIRED',
                    'PICKUP_RESCHEDULE_REQUESTED',
                    'PICKUP_RESCHEDULED',
                    'PICKUP_HELD_FOR_PREPARATION_ISSUE',
                    'PREPARATION_ISSUE_REPORTED',
                    'PREPARATION_ISSUE_RESOLVED',
                ])
                ->orderBy('occurred_at')
                ->get();

            $operationalHistoryNotificationEvents = NotificationEvent::query()
                ->where('source_type', $custody->getMorphClass())
                ->where('source_id', $custody->id)
                ->whereIn('event_code', [
                    'RETURN_SCHEDULE_ADJUSTED',
                ])
                ->orderBy('occurred_at')
                ->get();
        }

        return view(
            'requests.show',
            compact(
                'borrowingRequest',
                'canDecide',
                'canVerify',
                'reviewMode',
                'approvalStage',
                'accountabilityHistory',
                'operationalHistoryAuditEvents',
                'operationalHistoryNotificationEvents'
            )
        );
    }

    public function edit(
        Request $request,
        BorrowingRequest $borrowingRequest,
        \App\Services\BorrowerObligationService $obligations
    ): View {
        abort_unless(
            $borrowingRequest->borrower_user_id
                === $request->user()->id
            && in_array(
                $borrowingRequest->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            ),
            403
        );

        $borrowingRequest->load([
            'currentVersion.items',
            'currentVersion.supportingDocuments.file',
            'currentVersion.approvalSteps',
        ]);

        $borrower = $request->user()->loadMissing(['organizationalUnit', 'authorizedOrganizationalUnits']);
        $requestingUnitOptions = $this->requestingUnitOptions($borrower);
        $prefillRequestingUnitId = collect($requestingUnitOptions)
            ->contains(fn (array $option) => (int) $option['id'] === (int) $borrowingRequest->accountable_unit_id)
                ? (int) $borrowingRequest->accountable_unit_id
                : null;

        return view(
            'requests.form',
            [
                'borrowingRequest' =>
                    $borrowingRequest,

                'version' =>
                    $borrowingRequest->currentVersion,

                ...app(BorrowerRequestAffiliationService::class)->formContext($borrower, $borrowingRequest->currentVersion),

                'borrowingBlockedReasons' => $obligations->blockingReasons($borrower->id),

                'officeUnitsByDivision' => [],
                'requestingUnitOptions' => $requestingUnitOptions,
                'requestingUnitNotice' => $this->requestingUnitNotice($borrower),
                'prefillRequestingUnitId' => $prefillRequestingUnitId,
                'prefillDivisionCode' => null,
                'prefillOfficeUnit' => null,
                'maxUploadMb' => $this->uploadLimitMb(),

                'items' =>
                    InventoryItem::with(['unit', 'category'])
                        ->where('active', true)
                        ->where('borrowable', true)
                        ->where('condition_code', 'SERVICEABLE')
                        ->orderBy(
                            'unique_description'
                        )
                        ->get(),
            ]
        );
    }

    public function update(
        Request $request,
        BorrowingRequest $borrowingRequest,
        InventoryService $inventory,
        ProtectedFileService $files,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        abort_unless(
            $borrowingRequest->borrower_user_id
                === $request->user()->id
            && in_array(
                $borrowingRequest->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            ),
            403
        );

        $data =
            $this->validateRequest($request, $borrowingRequest->currentVersion);

        if ($request->input('intent') === 'submit') {
            $this->validateESignatureConfirmation($request);
        }

        $workflow->assertEligibleToBorrow($request->user());

        DB::transaction(
            function () use (
                $borrowingRequest,
                $data,
                $request,
                $inventory,
                $files,
                $workflow
            ): void {
                BorrowingRequest::query()->whereKey($borrowingRequest->id)->lockForUpdate()->firstOrFail();
                $borrowingRequest->refresh();
                abort_unless($borrowingRequest->borrower_user_id === $request->user()->id
                    && in_array($borrowingRequest->status, [RequestStatus::Draft, RequestStatus::ReturnedForRevision], true), 403);
                $borrowingRequest->update([
                    'accountable_unit_id' => $data['requesting_organizational_unit_id'],
                ]);

                $versionNo =
                    $borrowingRequest->status
                        === RequestStatus::ReturnedForRevision
                    ? $borrowingRequest->current_version_no + 1
                    : $borrowingRequest->current_version_no;

                if (
                    $borrowingRequest->status
                    === RequestStatus::ReturnedForRevision
                ) {
                    /*
                     * Returned requests create a new version.
                     *
                     * Old scanned approved documents are NOT
                     * copied to the new version automatically.
                     *
                     * The borrower must attach the corrected
                     * approved document(s) before resubmission.
                     */
                    $version =
                        $borrowingRequest
                            ->versions()
                            ->create(
                                $this->versionData(
                                    $data,
                                    $request->user()->id,
                                    $versionNo
                                )
                            );

                    $borrowingRequest->update([
                        'current_version_no' =>
                            $versionNo,

                        'status' =>
                            RequestStatus::Draft,
                    ]);
                } else {
                    $version =
                        $borrowingRequest
                            ->currentVersion;

                    $version->update(
                        $this->versionData(
                            $data,
                            $request->user()->id,
                            $versionNo
                        )
                    );

                    $version
                        ->items()
                        ->delete();
                }

                $this->saveItems(
                    $version,
                    $data,
                    $inventory
                );
                app(AuditService::class)->record('REQUEST_DRAFT_UPDATED', $borrowingRequest, after: [
                    'request_version_id' => $version->id,
                    'affiliation_type' => $version->affiliation_type->value,
                    'external_company_name' => $version->external_company_name,
                    'accountable_unit_id' => $borrowingRequest->accountable_unit_id,
                    'division_code' => $version->division_code,
                    'office_unit' => $version->office_unit,
                ]);
                $borrowingRequest->refresh()->load('currentVersion');
                $this->syncSupportingDocuments($request, $borrowingRequest, $files,
                    (bool) ($data['represents_student_activity'] ?? false));
                if ($request->input('intent') === 'submit') {
                    $workflow->submit($borrowingRequest->fresh(), $request->user(), $request->boolean('confirm_e_signature'));
                }
            },
            3
        );

        if ($request->input('intent') === 'submit') {
            return redirect()
                ->route('requests.show', $borrowingRequest)
                ->with(
                    'status',
                    'Request version E-signed and submitted with the required scanned document(s) to SPMU for verification.'
                );
        }

        return redirect()
            ->route('requests.index')
            ->with(
                'status',
                'Draft changes saved.'
            );
    }

    public function submit(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $request->validate([
            'borrower_acknowledgement' => [
                'required',
                'accepted',
            ],
        ], [
            'borrower_acknowledgement.accepted' =>
                'Read and accept the Borrower Certification and Acknowledgement before submitting to SPMU.',
        ]);
        $this->validateESignatureConfirmation($request);

        $workflow->submit(
            $borrowingRequest,
            $request->user(),
            $request->boolean('confirm_e_signature')
        );

        return back()->with(
            'status',
            'Request version E-signed and submitted with the scanned approved document(s) to SPMU for verification. No inventory reservation has been created yet.'
        );
    }

    private function validateESignatureConfirmation(Request $request): void
    {
        $request->validate([
            'confirm_e_signature' => ['required', 'accepted'],
        ], [
            'confirm_e_signature.accepted' => 'Confirm that you want to apply your registered E-signature before submitting.',
            'confirm_e_signature.required' => 'Confirm that you want to apply your registered E-signature before submitting.',
        ]);

        $hasCurrentSignature = $request->user()
            ->currentSignature()
            ->whereHas('file')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            })
            ->exists();

        if (! $hasCurrentSignature) {
            throw ValidationException::withMessages([
                'signature' => 'Register an E-signature in Account Settings before submitting this request.',
            ]);
        }
    }

    public function cancel(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('cancel', $borrowingRequest);

        $data = $request->validate([
            'reviewed_version_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $workflow->cancel(
            $borrowingRequest,
            $request->user(),
            $data['reason'],
            isset($data['reviewed_version_id']) ? (int) $data['reviewed_version_id'] : null
        );

        $message = 'Request cancelled. Any approved but unreleased allocation has been restored to Available inventory, and any pending pickup schedule/documents are no longer active.';

        if (
            $request->input('return_to') === 'release'
            && strtoupper((string) $request->session()->get('active_workspace')) === 'SPMU'
        ) {
            return redirect()
                ->route('custody.release.index')
                ->with('status', $message);
        }

        return back()->with('status', $message);
    }

    private function validateRequest(
        Request $request,
        ?RequestVersion $existingVersion = null
    ): array {
        $maxUploadKb = $this->uploadLimitMb() * 1024;
        $external = app(BorrowerRequestAffiliationService::class)->isExternal($request->user());

        /*
         * Date-only UI contract.
         *
         * Current/legacy Blade files may still submit:
         * - needed_from
         * - return_due_at
         *
         * New UI may submit the clearer names:
         * - schedule_date
         * - return_date
         *
         * Both are accepted here so UI work can be merged
         * without changing the backend workflow. Any time
         * portion submitted by the old datetime-local UI is
         * intentionally ignored.
         */
        $request->merge([
            /*
             * CREATE_REQUEST_EVENT_DETAILS_COMPAT
             *
             * The revised Borrower form collects Event Details only.
             * purpose_event remains a legacy stored field used by older
             * reports/views, so derive it server-side instead of asking
             * the borrower to enter the same information twice.
             */
            'purpose_event' =>
                $request->input('purpose_event')
                ?: mb_substr(
                    trim(
                        (string) $request->input('event_details')
                    ),
                    0,
                    255
                ),
            'schedule_date' =>
                $request->input('schedule_date')
                ?: $request->input('needed_from'),

            'return_date' =>
                $request->input('return_date')
                ?: $request->input('return_due_at'),
        ]);

        $data = $request->validate([
            'purpose_event' => [
                'required',
                'string',
                'max:255',
            ],

            'location' => [
                'required',
                'string',
                'max:255',
            ],

            'requesting_organizational_unit_id' => [
                $external ? 'prohibited' : 'required',
                'nullable',
                'integer',
            ],
            'accountable_unit_id' => ['prohibited'],
            'organizational_unit_id' => ['prohibited'],
            'affiliation_type' => ['prohibited'],
            'external_company_name' => ['prohibited'],
            'company_name' => ['prohibited'],
            ...($external ? ['division_code' => ['prohibited'], 'office_unit' => ['prohibited']] : []),

            'schedule_date' => [
                'required',
                'date',
            ],

            'return_date' => [
                'required',
                'date',
            ],

            /*
             * Legacy field names remain accepted while the
             * existing Borrower UI is still being replaced.
             */
            'needed_from' => [
                'nullable',
                'date',
            ],

            'return_due_at' => [
                'nullable',
                'date',
            ],

            'student_organization' => [
                ...($external ? ['prohibited'] : []),
                'nullable',
                'string',
                'max:255',
            ],

            'represents_student_activity' => [
                'nullable',
                'boolean',
                ...($external ? [Rule::in([false, 0, '0'])] : []),
            ],

            'represented_program_department' => [
                ...($external ? ['prohibited'] : []),
                'nullable',
                'string',
                'max:255',
            ],
            /*
             * Year Level is intentionally not collected by the revised form.
             */
            'represented_year_level' => [
                ...($external ? ['prohibited'] : []),
                'nullable',
                'string',
                'max:40',
            ],

            'event_details' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'off_campus' => [
                'nullable',
                'boolean',
            ],

            'intent' => [
                'nullable',
                Rule::in(['draft', 'submit']),
            ],

            'borrower_acknowledgement' => [
                'nullable',
                'boolean',
            ],

            /*
             * Supporting documents are optional while
             * saving a DRAFT.
             *
             * RequestWorkflowService::submit()
             * enforces the required document set before
             * SPMU can receive the request.
             */
            'approved_request_letter' => [
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:'.$maxUploadKb,
            ],

            'permission_to_conduct_letter' => [
                ...($external ? ['prohibited'] : []),
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:'.$maxUploadKb,
            ],

            'item_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'item_ids.*' => [
                'required',
                'integer',
                'exists:inventory_items,id',
            ],

            'quantities' => [
                'required',
                'array',
            ],

            'quantities.*' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'locations' => [
                'nullable',
                'array',
            ],

            'locations.*' => [
                'nullable',
                Rule::in([
                    'ON_CAMPUS',
                    'OFF_CAMPUS',
                ]),
            ],
        ], [
            'represents_student_activity.in' => 'Student activity requests are available only to CSPC Borrowers.',
            'permission_to_conduct_letter.prohibited' => 'Permission to Conduct Letters apply only to CSPC Borrowers.',
        ]);

        foreach (['approved_request_letter', 'permission_to_conduct_letter'] as $field) {
            if ($request->hasFile($field)) {
                app(SubmissionEvidenceService::class)->validateUpload($request->file($field), $field);
            }
        }

        $borrower = $request->user()->loadMissing([
            'organizationalUnit',
            'authorizedOrganizationalUnits',
        ]);

        $data = array_merge($data, app(BorrowerRequestAffiliationService::class)->snapshot($borrower, $existingVersion));
        if ($external) {
            $data['represents_student_activity'] = false;
            $data['requesting_organizational_unit_id'] = null;
            $data['division_code'] = null;
            $data['office_unit'] = null;
        } else {
            $authorizedUnit = $this->authorizedRequestingUnits($borrower)
                ->firstWhere('id', (int) $data['requesting_organizational_unit_id']);

            if (! $authorizedUnit) {
                throw ValidationException::withMessages([
                    'requesting_organizational_unit_id' => $this->requestingUnitNotice($borrower),
                ]);
            }
            $divisionCode = $authorizedUnit->divisionCode();
            if (! $divisionCode) {
                throw ValidationException::withMessages([
                    'requesting_organizational_unit_id' => 'The selected Office / College / Unit does not have a valid Division. Contact the SPMU Admin.',
                ]);
            }
            // Preserve the existing server-derived employee assignment snapshot.
            $data['division_code'] = $divisionCode;
            $data['office_unit'] = $authorizedUnit->unit_name;
        }

        $timezone =
            config('app.timezone')
            ?: 'Asia/Manila';

        $scheduleDate = Carbon::parse(
            $data['schedule_date'],
            $timezone
        )->startOfDay();

        $returnDate = Carbon::parse(
            $data['return_date'],
            $timezone
        )->startOfDay();

        /*
         * Preserve the existing business rule that the
         * borrowing date must be a future calendar date.
         * The comparison is now DATE-ONLY, not time-based.
         */
        if (
            ! $scheduleDate->gt(
                now($timezone)->startOfDay()
            )
        ) {
            throw ValidationException::withMessages([
                'schedule_date' =>
                    'Items Needed From must be '.now($timezone)->addDay()->format('F j, Y').' or later.',
            ]);
        }

        /*
         * Preserve the existing rule that Return Date is
         * after Schedule Date, but compare calendar dates
         * only.
         */
        if (! $returnDate->gt($scheduleDate)) {
            throw ValidationException::withMessages([
                'return_date' =>
                    'Please choose a return date after '.$scheduleDate->format('F j, Y').'.',
            ]);
        }

        $calendar = app(OperationalCalendarService::class);

        foreach ([
            'schedule_date' => [$scheduleDate, 'Items Needed From'],
            'return_date' => [$returnDate, 'Expected Return Date'],
        ] as $field => [$date, $label]) {
            $availability = $this->operationalDateResult(
                $calendar,
                $date,
                $label
            );

            if (! $availability['open']) {
                throw ValidationException::withMessages([
                    $field => $availability['message'],
                ]);
            }
        }

        if (
            ($data['intent'] ?? 'draft') === 'submit'
            && ! $request->boolean('borrower_acknowledgement')
        ) {
            throw ValidationException::withMessages([
                'borrower_acknowledgement' =>
                    'Read and accept the Borrower Certification and Acknowledgement before submitting to SPMU.',
            ]);
        }

        return $data;
    }

    private function uploadLimitMb(): int
    {
        return min(
            self::MAX_UPLOAD_MB,
            max(1, (int) SystemSetting::value('max_upload_mb', 5))
        );
    }

    /** @return array{open: bool, message: ?string} */
    private function operationalDateResult(
        OperationalCalendarService $calendar,
        Carbon $date,
        string $label
    ): array {
        if ($calendar->isOpenDate($date)) {
            return ['open' => true, 'message' => null];
        }

        $profile = $calendar->profile($date);
        $reason = $profile['reason'] ? ' ('.rtrim(trim($profile['reason']), '.').')' : '';

        return [
            'open' => false,
            'message' => 'SPMU is closed on '.$date->format('F j, Y').$reason.'. Please choose a different date.',
        ];
    }

    private function versionData(
        array $data,
        int $userId,
        int $versionNo
    ): array {
        return [
            'version_no' =>
                $versionNo,

            'purpose_event' =>
                $data['purpose_event'],

            'location' =>
                $data['location'],

            'division_code' =>
                $data['division_code'],

            'office_unit' =>
                $data['office_unit'],

            'affiliation_type' => $data['affiliation_type'],
            'external_company_name' => $data['external_company_name'],

            /*
             * Canonical date-only fields.
             */
            'schedule_date' =>
                Carbon::parse(
                    $data['schedule_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->toDateString(),

            'return_date' =>
                Carbon::parse(
                    $data['return_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->toDateString(),

            /*
             * Legacy timestamp columns remain populated for
             * existing inventory/calendar/report code. Their
             * time portions are normalized and are not user-
             * selected times.
             */
            'needed_from' =>
                Carbon::parse(
                    $data['schedule_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->startOfDay(),

            'return_due_at' =>
                Carbon::parse(
                    $data['return_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->endOfDay(),

            'represents_student_activity' =>
                (bool) (
                    $data[
                        'represents_student_activity'
                    ] ?? false
                ),

            'student_organization' =>
                null,

            'represented_program_department' =>
                null,

            'represented_year_level' =>
                null,

            'event_details' =>
                null,

            'off_campus' =>
                collect(
                    $data['locations'] ?? []
                )->contains(
                    'OFF_CAMPUS'
                ),

            'remarks' =>
                $data['remarks'] ?? null,

            'created_by_user_id' =>
                $userId,
        ];
    }

    private function saveItems(
        RequestVersion $version,
        array $data,
        InventoryService $inventory
    ): void {
        $selected = 0;

        $selectedItemIds = collect($data['item_ids'])
            ->filter(
                fn ($itemId) =>
                    (int) (
                        $data['quantities'][$itemId]
                        ?? 0
                    ) > 0
            )
            ->values();

        $hasOffCampusSelection =
            $selectedItemIds->contains(
                fn ($itemId) =>
                    strtoupper(
                        (string) (
                            $data['locations'][$itemId]
                            ?? 'ON_CAMPUS'
                        )
                    ) === 'OFF_CAMPUS'
            );

        if (
            $hasOffCampusSelection
            && $selectedItemIds->count() > 1
        ) {
            throw ValidationException::withMessages([
                'locations' =>
                    'An Off-Campus item must be the only selected item in the request.',
            ]);
        }

        foreach (
            $data['item_ids']
            as $itemId
        ) {
            $quantity =
                (int) (
                    $data['quantities'][$itemId]
                    ?? 0
                );

            if ($quantity <= 0) {
                continue;
            }

            $item =
                InventoryItem::with(['unit', 'category'])
                    ->where(
                        'active',
                        true
                    )
                    ->where(
                        'borrowable',
                        true
                    )
                    ->where(
                        'condition_code',
                        'SERVICEABLE'
                    )
                    ->findOrFail(
                        $itemId
                    );

            /*
             * Draft-time availability check.
             *
             * This provides immediate borrower feedback.
             *
             * IMPORTANT:
             * This does NOT create a reservation.
             */
            $balance =
                $inventory->availability(
                    $item,
                    Carbon::parse(
                        $data['schedule_date'],
                        config('app.timezone')
                            ?: 'Asia/Manila'
                    )->startOfDay(),
                    Carbon::parse(
                        $data['return_date'],
                        config('app.timezone')
                            ?: 'Asia/Manila'
                    )->endOfDay()
                );

            if (
                $quantity
                > $balance['available']
            ) {
                throw ValidationException::withMessages([
                    'quantities' =>
                        "{$item->unique_description} has only {$balance['available']} available for the complete period.",
                ]);
            }

            $location =
                strtoupper(
                    (string) (
                        $data[
                            'locations'
                        ][$item->id]
                        ?? 'ON_CAMPUS'
                    )
                );

            if (
                ! $item->off_campus_allowed
                && $location !== 'ON_CAMPUS'
            ) {
                throw ValidationException::withMessages([
                    'locations' =>
                        "{$item->unique_description} is restricted to On-Campus use.",
                ]);
            }

            if (
                ! in_array(
                    $location,
                    [
                        'ON_CAMPUS',
                        'OFF_CAMPUS',
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'locations' =>
                        'Choose a valid campus location for each selected item.',
                ]);
            }

            RequestItem::query()->create([
                'request_version_id' =>
                    $version->id,

                'inventory_item_id' =>
                    $item->id,

                'description_snapshot' =>
                    $item->unique_description,

                'unit_snapshot' =>
                    $item->unit->unit_name,

                'requested_quantity' =>
                    $quantity,

                'use_location' =>
                    $location,
            ]);

            $selected++;
        }

        if ($selected === 0) {
            throw ValidationException::withMessages([
                'items' =>
                    'Enter a quantity greater than zero for at least one item.',
            ]);
        }
    }

    private function syncSupportingDocuments(
        Request $request,
        BorrowingRequest $borrowingRequest,
        ProtectedFileService $files,
        bool $representsStudentActivity
    ): void {
        $version = $borrowingRequest->currentVersion;

        if (! $version) {
            throw ValidationException::withMessages([
                'documents' => 'The current request version could not be found.',
            ]);
        }

        $representsStudentActivity = ! $version->isExternalAffiliation() && $representsStudentActivity;

        if ($request->hasFile('approved_request_letter')) {
            $this->storeSupportingDocument(
                $borrowingRequest,
                $version,
                $request->file('approved_request_letter'),
                RequestSupportingDocument::TYPE_REQUEST_LETTER,
                $files,
                $request->user()->id
            );
        }

        if (
            $representsStudentActivity
            && $request->hasFile('permission_to_conduct_letter')
        ) {
            $this->storeSupportingDocument(
                $borrowingRequest,
                $version,
                $request->file('permission_to_conduct_letter'),
                RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT,
                $files,
                $request->user()->id
            );
        }

        /*
        * If a draft is changed from student activity to
        * regular borrowing, do NOT delete the historical PTC.
        *
        * Mark the current PTC as superseded so the audit trail
        * is preserved.
        */
        if (! $representsStudentActivity) {
            DB::transaction(function () use ($borrowingRequest, $version): void {
                $retired = $version
                    ->supportingDocuments()
                    ->where(
                        'document_type',
                        RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
                    )
                    ->where('is_current', true)
                    ->with(['file', 'uploader'])
                    ->get();

                foreach ($retired as $document) {
                    $before = $this->supportingDocumentAuditSnapshot($borrowingRequest, $version, $document, 'Current');

                    $document->update([
                        'is_current' => false,
                        'superseded_at' => now(),
                    ]);

                    app(AuditService::class)->record(
                        'REQUEST_SUPPORTING_DOCUMENT_SUPERSEDED',
                        $borrowingRequest,
                        reason: 'The request no longer represents a student activity, so the Permission to Conduct Letter no longer applies. The file is retained as a historical version.',
                        before: $before,
                        after: $this->supportingDocumentAuditSnapshot($borrowingRequest, $version, $document, 'Superseded')
                    );
                }
            });
        }
    }

    /**
     * Safe, content-free identification of one supporting-document version
     * for audit events: identifiers, filename and integrity metadata only.
     *
     * @return array<string, mixed>
     */
    private function supportingDocumentAuditSnapshot(
        BorrowingRequest $borrowingRequest,
        RequestVersion $version,
        RequestSupportingDocument $document,
        string $status
    ): array {
        $document->loadMissing(['file', 'uploader']);

        return [
            'request_no' => $borrowingRequest->request_no,
            'request_version_no' => $version->version_no,
            'document_type' => $document->document_type,
            'document_record_no' => $document->id,
            'document_version' => $document->version_no,
            'stored_file_no' => $document->stored_file_id,
            'original_filename' => $document->file?->original_name,
            'file_sha256' => $document->file?->sha256,
            'file_size_bytes' => $document->file?->byte_size,
            'file_type' => $document->file?->mime_type,
            'uploaded_by' => $document->uploader?->full_name,
            'uploaded_at' => $document->uploaded_at?->toIso8601String(),
            'document_status' => $status,
        ];
    }

    private function storeSupportingDocument(
        BorrowingRequest $borrowingRequest,
        RequestVersion $version,
        UploadedFile $upload,
        string $documentType,
        ProtectedFileService $files,
        int $uploaderId
    ): void {
        $storedFile = $files->storeUpload(
            $upload,
            'request-supporting-documents/'
                .$borrowingRequest->id
                .'/version-'
                .$version->version_no,
            'REQUEST_SUPPORTING_DOCUMENT'
        );

        /*
         * The supersede, the new version row and the audit event commit
         * together, so a failed upload never leaves a "successful" audit
         * event behind and a recorded upload always has its version row.
         */
        DB::transaction(function () use (
            $borrowingRequest,
            $version,
            $documentType,
            $storedFile,
            $uploaderId
        ): void {
            /*
            * Determine the next document version.
            */
            $latestVersion = (int) RequestSupportingDocument::query()
                ->where('request_version_id', $version->id)
                ->where('document_type', $documentType)
                ->max('version_no');

            $nextVersion = $latestVersion + 1;

            $previous = RequestSupportingDocument::query()
                ->where('request_version_id', $version->id)
                ->where('document_type', $documentType)
                ->where('is_current', true)
                ->with(['file', 'uploader'])
                ->orderByDesc('version_no')
                ->first();

            $previousSnapshot = $previous
                ? $this->supportingDocumentAuditSnapshot($borrowingRequest, $version, $previous, 'Current')
                : null;

            /*
            * Preserve the old uploaded document.
            *
            * Never overwrite or delete historical request evidence.
            */
            RequestSupportingDocument::query()
                ->where('request_version_id', $version->id)
                ->where('document_type', $documentType)
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'superseded_at' => now(),
                ]);

            $document = RequestSupportingDocument::query()->create([
                'request_id' => $borrowingRequest->id,
                'request_version_id' => $version->id,
                'document_type' => $documentType,
                'version_no' => $nextVersion,
                'stored_file_id' => $storedFile->id,
                'uploaded_by_user_id' => $uploaderId,
                'uploaded_at' => now(),

                'verification_status' =>
                    RequestSupportingDocument::STATUS_PENDING,

                'verified_by_user_id' => null,
                'verified_at' => null,
                'verification_remarks' => null,

                'is_current' => true,
                'superseded_at' => null,
            ]);

            /*
             * No reason is collected for an upload or replacement, so none
             * is recorded: the event states only what happened.
             */
            app(AuditService::class)->record(
                $previous
                    ? 'REQUEST_SUPPORTING_DOCUMENT_REPLACED'
                    : 'REQUEST_SUPPORTING_DOCUMENT_UPLOADED',
                $borrowingRequest,
                before: $previousSnapshot,
                after: $this->supportingDocumentAuditSnapshot($borrowingRequest, $version, $document, 'Current')
            );
        });
    }

    private function authorizeRequest(
        Request $request,
        BorrowingRequest $borrowingRequest,
        bool $canVerify,
        bool $canDecide
    ): void {
        $user = $request->user();

        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        $isBorrowerOwner = $workspace === 'BORROWER'
            && $borrowingRequest->borrower_user_id === $user->id;

        $isSpmuHead = $workspace === 'SPMU'
            && $user->access_classification === AccessClassification::SpmuHead;

        $isDelegatedOfficer = $workspace === 'SPMU'
            && $user->access_classification === AccessClassification::SpmuOfficer
            && $user->activeDelegationFor('SPMU') !== null;

        /*
         * During UNDER_SPMU review, access follows the CURRENT approval step:
         *
         * sequence 1 = Action Officer only
         * sequence 2 = Head / Admin only
         */
        $isAuthorizedActiveReviewer =
            $borrowingRequest->status === RequestStatus::UnderSpmu
            && ($canVerify || $canDecide);

        /*
         * After final approval, AO regains access because responsibility
         * transfers to pickup/release/return operations.
         */
        $isOperationalOfficer = $workspace === 'SPMU'
            && $user->access_classification === AccessClassification::SpmuOfficer
            && $borrowingRequest->final_approved_at !== null;

        /*
         * Head/Admin keeps historical oversight after the active review stage.
         */
        $hasSpmuWorkflowHistory = $borrowingRequest->final_approved_at !== null
            || (bool) $borrowingRequest->currentVersion?->approvalSteps()
                ->where('stage_code', 'SPMU')
                ->exists();

        $isHistoricalDecisionViewer =
            $borrowingRequest->status !== RequestStatus::UnderSpmu
            && $hasSpmuWorkflowHistory
            && ($isSpmuHead || $isDelegatedOfficer);

        abort_unless(
            $isBorrowerOwner
            || $isAuthorizedActiveReviewer
            || $isOperationalOfficer
            || $isHistoricalDecisionViewer,
            403
        );
    }

    private function canVerifyRequest(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): bool {
        if (
            $borrowingRequest->status !== RequestStatus::UnderSpmu
            || $request->user()->primaryWorkspace() !== 'SPMU'
            || $request->user()->access_classification !== AccessClassification::SpmuOfficer
        ) {
            return false;
        }

        $borrowingRequest->loadMissing('currentVersion.approvalSteps');

        $step = $borrowingRequest->currentVersion?->approvalSteps
            ?->where('sequence_no', 1)
            ->first(fn ($item) => (string) $item->stage_code->value === 'SPMU');

        return $step && in_array($step->decision, ['PENDING', 'RECEIVED'], true);
    }

    private function canDecideApproval(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): bool {
        if ($borrowingRequest->status !== RequestStatus::UnderSpmu) {
            return false;
        }

        $user = $request->user();

        if ($user->primaryWorkspace() !== 'SPMU') {
            return false;
        }

        $hasFinalAuthority =
            $user->access_classification === AccessClassification::SpmuHead
            || (
                $user->access_classification === AccessClassification::SpmuOfficer
                && $user->activeDelegationFor('SPMU') !== null
            );

        if (! $hasFinalAuthority) {
            return false;
        }

        $borrowingRequest->loadMissing('currentVersion.approvalSteps');

        $step = $borrowingRequest->currentVersion?->approvalSteps
            ?->where('sequence_no', 2)
            ->first(fn ($item) => (string) $item->stage_code->value === 'SPMU');

        return $step && in_array($step->decision, ['PENDING', 'RECEIVED'], true);
    }

    private function approvalStage(
        RequestStatus $status
    ): ?string {
        return $status === RequestStatus::UnderSpmu
            ? 'SPMU'
            : null;
    }
}
