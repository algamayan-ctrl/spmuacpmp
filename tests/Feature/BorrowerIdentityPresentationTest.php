<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RegistrationType;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Services\BorrowerRegistrationService;
use App\Services\UserRoleAssignmentService;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BorrowerIdentityPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(9, 0));
        config(['app.seed_demo_users' => true]);
        $this->seed(DatabaseSeeder::class);
        Mail::fake();
        Storage::fake('local');
    }

    public static function borrowerTypes(): array
    {
        return [['EXTERNAL'], ['EMPLOYEE']];
    }

    #[DataProvider('borrowerTypes')]
    public function test_registration_values_appear_in_the_appropriate_read_only_profile(string $type): void
    {
        $borrower = $this->registeredBorrower($type);
        $response = $this->actingAs($borrower)->get(route('profile.show'))->assertOk();
        $identity = $this->section($response->getContent(), '//*[@aria-labelledby="account-details-heading"]');
        foreach ([$borrower->full_name, $borrower->email, $borrower->mobile_no, RegistrationType::from($type)->label()] as $value) {
            $this->assertStringContainsString($value, $identity);
        }
        if ($type === 'EXTERNAL') {
            $this->assertStringContainsString('Company / Agency / Institution', $identity);
            $this->assertStringContainsString('Registered Example Agency', $identity);
            $this->assertStringContainsString('On file · Private', $identity);
            foreach (['Borrower / Employee Number', 'Designation / Position', 'Personnel Type', 'Employment Status', 'Division', 'Office / College / Unit'] as $label) {
                $this->assertStringNotContainsString($label, $identity);
            }
            $file = $borrower->borrowerRegistration->validIdFile;
            $this->assertStringNotContainsString($file->storage_path, $response->getContent());
            $this->assertStringNotContainsString(route('files.preview', $file, false), $identity);
        } else {
            foreach ([$borrower->employee_no, $borrower->designation, 'Office / College / Unit', $borrower->organizationalUnit->unit_name] as $value) {
                $this->assertStringContainsString($value, $identity);
            }
            $this->assertStringNotContainsString('Company / Agency / Institution', $identity);
            $this->assertStringNotContainsString('Valid ID', $identity);
        }
        $this->assertSame(0, substr_count($identity, '<input'));
    }

    public function test_external_contact_updates_reflect_without_self_editing_registration_identity(): void
    {
        $borrower = $this->registeredBorrower('EXTERNAL');
        $registrationBefore = $borrower->borrowerRegistration->getAttributes();
        $identityBefore = $borrower->only(['full_name', 'email', 'employee_no', 'organizational_unit_id']);
        $this->actingAs($borrower)->put(route('profile.update'), [
            'mobile_no' => '09179998888', 'system_notifications' => 1, 'email_notifications' => 1,
            'company_name' => 'Self assigned agency', 'registration_type' => 'EMPLOYEE',
            'full_name' => 'Self assigned identity', 'email' => 'changed@example.test',
            'organizational_unit_id' => OrganizationalUnit::where('unit_code', 'SPMU')->value('id'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($identityBefore, $borrower->fresh()->only(array_keys($identityBefore)));
        $this->assertSame($registrationBefore, $borrower->borrowerRegistration->fresh()->getAttributes());
        $response = $this->get(route('profile.show'))->assertOk();
        $identity = $this->section($response->getContent(), '//*[@aria-labelledby="account-details-heading"]');
        $this->assertStringContainsString('09179998888', $identity);
        $this->assertStringContainsString('Registered Example Agency', $identity);
    }

    public function test_missing_external_data_stays_explicit_without_inventing_an_employee_assignment(): void
    {
        $borrower = $this->registeredBorrower('EXTERNAL');
        $borrower->borrowerRegistration->update(['company_name' => null, 'valid_id_file_id' => null]);
        $borrower->update(['mobile_no' => null, 'organizational_unit_id' => OrganizationalUnit::where('unit_code', 'CCS')->value('id')]);
        $response = $this->actingAs($borrower)->get(route('profile.show'))->assertOk();
        $identity = $this->section($response->getContent(), '//*[@aria-labelledby="account-details-heading"]');
        $this->assertStringContainsString('External Borrower', $identity);
        $this->assertStringContainsString('Company / Agency / Institution', $identity);
        $this->assertSame(3, substr_count($identity, 'Not recorded'));
        $this->assertStringNotContainsString('Office / College / Unit', $identity);
        $this->assertStringNotContainsString('College of Computer Studies', $identity);
    }

    public static function recordViews(): array
    {
        $cases = [];
        foreach (['EXTERNAL', 'EMPLOYEE'] as $type) {
            foreach ([
                ['review', 'borrower', 'requests.show'], ['review', 'officer', 'requests.show'],
                ['review', 'head', 'requests.show'], ['revision', 'head', 'requests.show'],
                ['release', 'head', 'requests.show'], ['release', 'officer', 'requests.show'],
                ['release', 'borrower', 'requests.show'], ['closed', 'head', 'requests.show'],
                ['closed', 'officer', 'requests.show'], ['closed', 'borrower', 'requests.show'],
                ['release', 'head', 'custody.show'], ['release', 'borrower', 'custody.show'],
                ['release', 'officer', 'custody.release.show'], ['return', 'officer', 'custody.return.show'],
                ['closed', 'head', 'custody.show'], ['closed', 'borrower', 'custody.show'],
            ] as [$stage, $role, $route]) {
                $cases[$type.' '.$stage.' '.$role.' '.$route] = [$type, $stage, $role, $route];
            }
        }
        return $cases;
    }

    #[DataProvider('recordViews')]
    public function test_request_and_custody_views_show_saved_affiliation_for_every_role(string $type, string $stage, string $role, string $route): void
    {
        $borrower = $this->registeredBorrower($type);
        [$request, $custody] = $this->record($borrower, $type, $stage, $role);
        $versionBefore = $request->currentVersion->getAttributes();
        // Later account edits must never replace the reviewed transaction affiliation.
        $borrower->borrowerRegistration->update(['company_name' => 'Later Profile Agency']);
        $borrower->update(['organizational_unit_id' => OrganizationalUnit::where('unit_code', 'SPMU')->value('id')]);
        $actor = $role === 'borrower' ? $borrower : $this->staff($role);
        $response = $this->actingAs($actor)->withSession(['active_workspace' => $role === 'borrower' ? 'BORROWER' : 'SPMU'])
            ->get(route($route, str_starts_with($route, 'requests.') ? $request : $custody))->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Borrower Type', $html);
        $this->assertStringContainsString(RegistrationType::from($type)->label(), $html);
        $this->assertStringContainsString($type === 'EXTERNAL' ? 'Company / Agency / Institution' : 'Office / College / Unit', $html);
        $this->assertStringContainsString($type === 'EXTERNAL' ? 'Registered Example Agency' : 'Saved Requesting Office', $html);
        $this->assertStringNotContainsString('Later Profile Agency', $html);
        $contacts = $this->section($html, '//details[contains(@class,"borrower-contact-disclosure")]');
        $this->assertStringContainsString($borrower->email, $contacts);
        $this->assertStringContainsString($borrower->mobile_no, $contacts);
        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen(?:\s|=|>)/', $contacts);
        $this->assertSame($versionBefore, $request->currentVersion->fresh()->getAttributes());
    }

    public function test_custody_identity_uses_its_original_request_version(): void
    {
        $borrower = $this->registeredBorrower('EXTERNAL');
        [$request, $custody] = $this->record($borrower, 'EXTERNAL', 'closed', 'head');
        $next = $request->currentVersion->replicate();
        $next->version_no = 2;
        $next->external_company_name = 'Later Request Agency';
        $next->save();
        $request->update(['current_version_no' => 2]);
        $response = $this->actingAs($this->staff('head'))->withSession(['active_workspace' => 'SPMU'])
            ->get(route('custody.show', $custody))->assertOk();
        $identity = $this->section($response->getContent(), '//*[contains(@class,"borrower-record-identity")]');
        $this->assertStringContainsString('Registered Example Agency', $identity);
        $this->assertStringNotContainsString('Later Request Agency', $identity);
    }

    public function test_missing_saved_company_is_not_replaced_from_the_current_registration(): void
    {
        $borrower = $this->registeredBorrower('EXTERNAL');
        [$request] = $this->record($borrower, 'EXTERNAL', 'closed', 'head');
        $request->currentVersion->update(['external_company_name' => null]);
        $response = $this->actingAs($this->staff('head'))->withSession(['active_workspace' => 'SPMU'])
            ->get(route('requests.show', $request))->assertOk();
        $info = $this->section($response->getContent(), '//*[@aria-labelledby="request-information-title"]');
        $this->assertStringContainsString('External Borrower', $info);
        $this->assertStringContainsString('Company / Agency / Institution', $info);
        $this->assertStringContainsString('Not recorded', $info);
        $this->assertStringNotContainsString('Registered Example Agency', $info);
    }

    public function test_identity_output_is_escaped_and_other_borrowers_cannot_access_the_record(): void
    {
        $borrower = $this->registeredBorrower('EXTERNAL');
        [$request, $custody] = $this->record($borrower, 'EXTERNAL', 'closed', 'head');
        $unsafe = '<script>alert("identity")</script>';
        $borrower->borrowerRegistration->update(['company_name' => $unsafe]);
        $request->currentVersion->update(['external_company_name' => $unsafe]);
        $this->actingAs($borrower)->withSession(['active_workspace' => 'BORROWER']);
        foreach ([route('profile.show'), route('requests.show', $request), route('custody.show', $custody)] as $url) {
            $this->get($url)->assertOk()->assertSee(e($unsafe), false)->assertDontSee($unsafe, false);
        }
        $other = User::where('email', 'borrower@spmu.test')->firstOrFail();
        $this->actingAs($other)->get(route('requests.show', $request))->assertForbidden();
        $this->get(route('custody.show', $custody))->assertForbidden();
    }

    public function test_legacy_and_missing_affiliations_do_not_invent_external_registration_facts(): void
    {
        $borrower = User::where('email', 'borrower@spmu.test')->firstOrFail();
        [$request] = $this->record($borrower, 'EMPLOYEE', 'closed', 'head');
        $version = $request->currentVersion;
        $version->update(['affiliation_type' => null]);
        $this->assertSame('CSPC Employee', $version->affiliationTypeLabel());
        $this->assertSame('Saved Requesting Office', $version->affiliationLabel());
        $version->update(['office_unit' => null]);
        $request->update(['accountable_unit_id' => null]);
        $this->assertSame('Not recorded', $version->fresh()->affiliationTypeLabel());
        $profile = $this->actingAs($borrower)->get(route('profile.show'))->assertOk();
        $identity = $this->section($profile->getContent(), '//*[@aria-labelledby="account-details-heading"]');
        $this->assertStringNotContainsString('External Borrower', $identity);
    }

    private function registeredBorrower(string $type): User
    {
        $registration = app(BorrowerRegistrationService::class)->register([
            'registration_type' => $type, 'full_name' => $type.' Registered Borrower',
            'email' => strtolower($type).'@example.test', 'mobile_no' => '09171234567',
            'password' => 'Registration!Pass2026', 'company_name' => 'Registered Example Agency',
            'employee_no' => 'CSPC-IDENTITY-001', 'designation' => 'Instructor I',
            'organizational_unit_id' => OrganizationalUnit::where('unit_code', 'CCS')->value('id'),
        ], $type === 'EXTERNAL' ? UploadedFile::fake()->image('private-id.png') : null);
        $registration->update(['status' => 'APPROVED']);
        $borrower = $registration->user;
        $borrower->markInstitutionalEmailAsVerified();
        app(UserRoleAssignmentService::class)->synchronize($borrower, AccessClassification::BorrowerOnly, $this->staff('officer')->id);
        return $borrower->fresh();
    }

    private function staff(string $role): User
    {
        return User::where('access_classification', $role === 'head' ? AccessClassification::SpmuHead : AccessClassification::SpmuOfficer)->firstOrFail();
    }

    private function record(User $borrower, string $type, string $stage, string $role): array
    {
        $request = BorrowingRequest::create([
            'request_no' => 'IDENTITY-'.uniqid(), 'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $type === 'EXTERNAL' ? null : $borrower->organizational_unit_id,
            'current_version_no' => 1, 'status' => match ($stage) {
                'review' => RequestStatus::UnderSpmu, 'revision' => RequestStatus::ReturnedForRevision,
                default => RequestStatus::ApprovedReadyForRelease,
            }, 'final_approved_at' => in_array($stage, ['review', 'revision']) ? null : now(),
        ]);
        $version = $request->versions()->create([
            'version_no' => 1, 'purpose_event' => 'Identity presentation fixture', 'location' => 'Fixture venue',
            'schedule_date' => now()->addDay()->toDateString(), 'return_date' => now()->addDays(3)->toDateString(),
            'needed_from' => now()->addDay()->startOfDay(), 'return_due_at' => now()->addDays(3)->endOfDay(),
            'created_by_user_id' => $borrower->id, 'affiliation_type' => $type,
            'office_unit' => $type === 'EXTERNAL' ? 'Stray internal assignment' : 'Saved Requesting Office',
            'external_company_name' => $type === 'EXTERNAL' ? 'Registered Example Agency' : null,
        ]);
        ApprovalStep::create([
            'request_version_id' => $version->id, 'stage_code' => 'SPMU',
            'sequence_no' => $role === 'head' ? 2 : 1,
            'decision' => $stage === 'review' ? 'PENDING' : 'APPROVED',
        ]);
        $item = InventoryItem::with('unit')->where('laundry_required', false)->where('borrowable', true)->firstOrFail();
        $line = $version->items()->create([
            'inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name, 'requested_quantity' => 1, 'approved_quantity' => 1, 'use_location' => 'ON_CAMPUS',
        ]);
        if (in_array($stage, ['review', 'revision'])) {
            return [$request->fresh(), null];
        }
        $released = in_array($stage, ['return', 'closed']);
        $custody = CustodyTransaction::create([
            'custody_no' => 'CUS-IDENTITY-'.uniqid(), 'request_id' => $request->id, 'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id, 'status' => $stage === 'closed' ? 'CLOSED' : ($released ? 'ON_CUSTODY' : 'PREPARING_RELEASE'),
            'due_at' => $version->return_due_at, 'original_due_at' => $version->return_due_at,
            'released_at' => $released ? now() : null, 'closed_at' => $stage === 'closed' ? now() : null,
        ]);
        $allocation = Allocation::create([
            'request_item_id' => $line->id, 'period_start' => $version->needed_from, 'period_end' => $version->return_due_at,
            'allocated_quantity' => 1, 'released_quantity' => $released ? 1 : 0,
            'restored_quantity' => $stage === 'closed' ? 1 : 0, 'status' => 'ACTIVE', 'allocated_at' => now(),
        ]);
        CustodyLine::create([
            'custody_transaction_id' => $custody->id, 'request_item_id' => $line->id, 'allocation_id' => $allocation->id,
            'approved_quantity' => 1, 'quantity_to_receive' => 1, 'actual_released_quantity' => $released ? 1 : 0,
            'returned_quantity' => $stage === 'closed' ? 1 : 0, 'item_status' => 'PENDING',
        ]);
        return [$request->fresh(), $custody];
    }

    private function section(string $html, string $query): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $nodes = (new DOMXPath($dom))->query($query);
        $this->assertSame(1, $nodes->length, $query);
        return $dom->saveHTML($nodes->item(0));
    }
}
