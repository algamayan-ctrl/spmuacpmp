<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BorrowingRequest;
use App\Models\ProcessingStageRun;
use App\Services\ProcessingTimeEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProcessingTimeController extends Controller
{
    public function status(
        Request $request,
        BorrowingRequest $borrowingRequest,
        ProcessingTimeEstimateService $estimator
    ): JsonResponse {
        $this->authorizeRead($request, $borrowingRequest);

        return response()->json($estimator->estimate($borrowingRequest))
            ->header('Cache-Control', 'private, no-store');
    }

    public function start(
        Request $request,
        BorrowingRequest $borrowingRequest,
        ProcessingTimeEstimateService $estimator
    ): RedirectResponse {
        abort_unless(
            strtoupper((string) $request->session()->get('active_workspace')) === 'SPMU',
            403
        );
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(ProcessingTimeEstimateService::TARGETS))],
        ]);
        $stage = $data['stage'];
        $classification = $request->user()->access_classification;
        $isOfficer = $classification === AccessClassification::SpmuOfficer;
        $isHeadOrDelegate = $classification === AccessClassification::SpmuHead
            || ($isOfficer && $request->user()->activeDelegationFor('SPMU') !== null);
        $roleAllowed = $stage === 'HEAD_APPROVAL' ? $isHeadOrDelegate : $isOfficer;
        abort_unless($roleAllowed, 403);

        $borrowingRequest->load(['currentVersion.approvalSteps', 'custody']);
        $version = $borrowingRequest->currentVersion;
        abort_unless($version, 404);

        $estimate = $estimator->estimate($borrowingRequest);
        $stageData = collect($estimate['stages'])->firstWhere('key', $stage);
        abort_unless($stageData && $stageData['can_start'], 409, 'This stage is not ready to begin.');

        ProcessingStageRun::query()->firstOrCreate(
            ['request_version_id' => $version->id, 'stage_code' => $stage],
            [
                'request_id' => $borrowingRequest->id,
                'started_by_user_id' => $request->user()->id,
                'started_at' => now(),
            ]
        );

        return back()->with('status', 'Active processing timer started for '.$stageData['label'].'. Queue time remains separate.');
    }

    private function authorizeRead(Request $request, BorrowingRequest $borrowingRequest): void
    {
        $workspace = strtoupper((string) $request->session()->get('active_workspace'));
        $borrower = $workspace === 'BORROWER'
            && (int) $borrowingRequest->borrower_user_id === (int) $request->user()->id;
        $staff = $workspace === 'SPMU'
            && in_array($request->user()->access_classification, [
                AccessClassification::SpmuOfficer, AccessClassification::SpmuHead,
            ], true);
        abort_unless($borrower || $staff, 403);
    }
}
