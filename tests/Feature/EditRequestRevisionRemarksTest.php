<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EditRequestRevisionRemarksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_edit_request_shows_action_officer_return_remarks_and_timestamp(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $officer = $this->user(AccessClassification::SpmuOfficer);
        $version = $this->returnedRequestVersion($borrower, 1);
        $returnedAt = CarbonImmutable::parse('2026-09-27 14:30:00', 'Asia/Manila');

        $this->returnForRevision(
            $version,
            $officer,
            1,
            'Please replace the unreadable approval letter.',
            $returnedAt
        );

        $this->editResponse($borrower, $version->request)
            ->assertSeeText('Revision required')
            ->assertSeeText('Update the details noted in the review remarks.')
            ->assertSeeText('Review remarks')
            ->assertSeeText('Please replace the unreadable approval letter.')
            ->assertSeeText('Returned by: SPMU Action Officer')
            ->assertSeeText('27 Sep 2026, 2:30 PM');
    }

    public function test_edit_request_shows_only_the_current_head_admin_return_remarks(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $officer = $this->user(AccessClassification::SpmuOfficer);
        $head = $this->user(AccessClassification::SpmuHead);
        $oldVersion = $this->returnedRequestVersion($borrower, 1);

        $this->returnForRevision(
            $oldVersion,
            $officer,
            1,
            'Older Action Officer correction that remains in request history.',
            CarbonImmutable::parse('2026-09-24 09:00:00', 'Asia/Manila')
        );

        $oldVersion->request->update(['current_version_no' => 2]);
        $currentVersion = $this->version($oldVersion->request, $borrower, 2);

        ApprovalStep::query()->create([
            'request_version_id' => $currentVersion->id,
            'approver_user_id' => $officer->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 1,
            'decision' => 'VERIFIED',
            'decided_at' => CarbonImmutable::parse('2026-09-26 09:00:00', 'Asia/Manila'),
            'remarks' => 'An earlier verification note that is not a return instruction.',
        ]);

        $this->returnForRevision(
            $currentVersion,
            $head,
            2,
            'Please correct the expected return date before resubmitting.',
            CarbonImmutable::parse('2026-09-27 16:45:00', 'Asia/Manila')
        );

        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $oldVersion->id,
            'remarks' => 'Older Action Officer correction that remains in request history.',
        ]);

        $this->editResponse($borrower, $oldVersion->request)
            ->assertSeeText('Please correct the expected return date before resubmitting.')
            ->assertSeeText('Returned by: SPMU Head/Admin')
            ->assertSeeText('27 Sep 2026, 4:45 PM')
            ->assertDontSeeText('Older Action Officer correction that remains in request history.')
            ->assertDontSeeText('An earlier verification note that is not a return instruction.');
    }

    public function test_edit_request_uses_the_legacy_fallback_when_return_remarks_are_missing(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $officer = $this->user(AccessClassification::SpmuOfficer);
        $version = $this->returnedRequestVersion($borrower, 1);

        $this->returnForRevision(
            $version,
            $officer,
            1,
            null,
            CarbonImmutable::parse('2026-09-27 10:15:00', 'Asia/Manila')
        );

        $this->editResponse($borrower, $version->request)
            ->assertSeeText('No review remarks were recorded. Please contact SPMU for clarification.')
            ->assertSeeText('Returned by: SPMU Action Officer')
            ->assertSeeText('27 Sep 2026, 10:15 AM');
    }

    public function test_owner_has_a_collapsed_revision_panel_with_the_edit_action_outside_the_disclosure(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $head = $this->user(AccessClassification::SpmuHead);
        $version = $this->returnedRequestVersion($borrower, 1);
        $this->returnForRevision($version, $head, 2, 'Correct the venue and upload the signed letter.', CarbonImmutable::now());
        $response = $this->requestResponse($borrower, 'BORROWER', $version->request);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-revision-panel and contains(@class,"is-owner")]'));
        $this->assertCount(1, $xpath->query('//details[@data-revision-details and not(@open)]/summary'));
        $action = $xpath->query('//*[@data-revision-action]');
        $this->assertCount(1, $action);
        $this->assertSame(route('requests.edit', $version->request), $action->item(0)->getAttribute('href'));
        $this->assertCount(0, $xpath->query('ancestor::details', $action->item(0)), 'The owner action must stay visible when details are collapsed.');
        $response->assertSeeText('Revision required')->assertSeeText('View details')
            ->assertSeeText('Required corrections')->assertSeeText('Correct the venue and upload the signed letter.')
            ->assertSeeText('Update the request, then E-sign and submit it again.');
    }

    public function test_admin_has_collapsed_read_only_remarks_without_borrower_actions_or_instructions(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $head = $this->user(AccessClassification::SpmuHead);
        $version = $this->returnedRequestVersion($borrower, 1);
        $this->returnForRevision($version, $head, 2, 'Replace the incomplete request letter.', CarbonImmutable::now());
        $before = $this->revisionSnapshot();
        $response = $this->requestResponse($head, 'SPMU', $version->request);
        $xpath = $this->xpath($response->getContent());
        $panel = $xpath->query('//*[@data-revision-panel]');
        $this->assertCount(1, $panel);
        $this->assertStringContainsString('is-reviewer', $panel->item(0)->getAttribute('class'));
        $this->assertCount(1, $xpath->query('//details[@data-revision-details and not(@open)]'));
        $this->assertCount(0, $xpath->query('//*[@data-revision-action or @id="borrower-next-action"]'));
        $this->assertCount(0, $xpath->query('.//a|.//form|.//input|.//button', $panel->item(0)));
        $response->assertSeeText('Awaiting borrower resubmission')->assertSeeText('Revision remarks')
            ->assertSeeText('Replace the incomplete request letter.')
            ->assertDontSeeText('Revise Request')->assertDontSeeText('Update the request, then E-sign and submit it again.')
            ->assertDontSee(route('requests.edit', $version->request), false);
        $this->assertSame($before, $this->revisionSnapshot());
        $this->withSession(['active_workspace' => 'SPMU'])->actingAs($head)
            ->get(route('requests.edit', $version->request))->assertForbidden();
    }

    public function test_request_ownership_alone_does_not_show_revision_actions_in_the_admin_workspace(): void
    {
        $head = $this->user(AccessClassification::SpmuHead);
        $version = $this->returnedRequestVersion($head, 1);
        $this->returnForRevision($version, $head, 2, 'Owner is currently viewing as Admin.', CarbonImmutable::now());
        $response = $this->requestResponse($head, 'SPMU', $version->request);
        $this->assertCount(0, $this->xpath($response->getContent())->query('//*[@data-revision-action]'));
        $response->assertSeeText('Awaiting borrower resubmission')->assertDontSeeText('Revise Request');
    }

    public function test_non_owner_borrower_cannot_view_or_edit_the_returned_request(): void
    {
        $owner = $this->user(AccessClassification::BorrowerOnly);
        $other = User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
        $version = $this->returnedRequestVersion($owner, 1);
        $this->returnForRevision($version, $this->user(AccessClassification::SpmuHead), 2, 'Private corrections.', CarbonImmutable::now());
        foreach (['requests.show', 'requests.edit'] as $route) {
            $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($other)
                ->get(route($route, $version->request))->assertForbidden();
        }
    }

    public function test_missing_remarks_have_a_read_only_fallback_for_both_roles(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $head = $this->user(AccessClassification::SpmuHead);
        $version = $this->returnedRequestVersion($borrower, 1);
        $this->returnForRevision($version, $head, 2, null, CarbonImmutable::now());
        foreach ([[$borrower, 'BORROWER'], [$head, 'SPMU']] as [$viewer, $workspace]) {
            $response = $this->requestResponse($viewer, $workspace, $version->request);
            $response->assertSeeText('No review remarks were recorded.');
            $this->assertCount(1, $this->xpath($response->getContent())->query('//details[@data-revision-details and not(@open)]'));
        }
    }

    public function test_revision_panel_uses_current_version_remarks_and_escapes_long_multiline_content(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $head = $this->user(AccessClassification::SpmuHead);
        $old = $this->returnedRequestVersion($borrower, 1);
        $this->returnForRevision($old, $head, 2, 'Obsolete version correction.', CarbonImmutable::now()->subDay());
        $current = $this->version($old->request, $borrower, 2);
        $remarks = "<script>alert('synthetic')</script>\n".str_repeat('Corrected evidence details ', 30);
        $this->returnForRevision($current, $head, 2, $remarks, CarbonImmutable::now());
        $old->request->update(['current_version_no' => 2]);
        foreach ([[$borrower, 'BORROWER'], [$head, 'SPMU']] as [$viewer, $workspace]) {
            $response = $this->requestResponse($viewer, $workspace, $old->request);
            $paragraph = $this->xpath($response->getContent())->query('//*[@data-revision-panel]//p[contains(@class,"request-revision-remarks")]')->item(0);
            $this->assertNotNull($paragraph);
            $this->assertSame(trim($remarks), $paragraph->textContent);
            $response->assertDontSee("<script>alert('synthetic')</script>", false);
        }
    }

    public function test_normal_submitted_request_does_not_show_the_revision_panel(): void
    {
        $borrower = $this->user(AccessClassification::BorrowerOnly);
        $version = $this->returnedRequestVersion($borrower, 1);
        $version->request->update(['status' => RequestStatus::UnderSpmu]);
        $response = $this->requestResponse($borrower, 'BORROWER', $version->request);
        $this->assertCount(0, $this->xpath($response->getContent())->query('//*[@data-revision-panel]'));
    }

    private function requestResponse(User $user, string $workspace, BorrowingRequest $request): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['active_workspace' => $workspace])->actingAs($user)
            ->get(route('requests.show', $request))->assertOk();
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    private function revisionSnapshot(): array
    {
        return collect(['borrowing_requests', 'request_versions', 'approval_steps', 'inventory_items', 'request_supporting_documents'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function user(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }

    private function returnedRequestVersion(User $borrower, int $versionNo): RequestVersion
    {
        $request = BorrowingRequest::query()->create([
            'request_no' => 'REVISION-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => $versionNo,
            'status' => RequestStatus::ReturnedForRevision,
        ]);

        return $this->version($request, $borrower, $versionNo);
    }

    private function version(
        BorrowingRequest $request,
        User $borrower,
        int $versionNo
    ): RequestVersion {
        $scheduleDate = CarbonImmutable::parse('2026-10-05', 'Asia/Manila');

        return $request->versions()->create([
            'version_no' => $versionNo,
            'created_by_user_id' => $borrower->id,
            'purpose_event' => 'Revision remarks coverage',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate,
            'return_date' => $scheduleDate->addDay(),
            'needed_from' => $scheduleDate->startOfDay(),
            'return_due_at' => $scheduleDate->addDay()->endOfDay(),
            'off_campus' => false,
            'represents_student_activity' => false,
        ]);
    }

    private function returnForRevision(
        RequestVersion $version,
        User $reviewer,
        int $sequenceNo,
        ?string $remarks,
        CarbonImmutable $returnedAt
    ): ApprovalStep {
        return ApprovalStep::query()->create([
            'request_version_id' => $version->id,
            'approver_user_id' => $reviewer->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => $sequenceNo,
            'decision' => 'RETURNED_FOR_REVISION',
            'decided_at' => $returnedAt,
            'remarks' => $remarks,
        ]);
    }

    private function editResponse(User $borrower, BorrowingRequest $request): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.edit', $request))
            ->assertOk();
    }
}
