<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Models\BorrowerRegistration;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * The approved authentication policy.
 *
 * - CSPC Employees (INSTITUTIONAL_EMAIL_DOMAINS = cspc.edu.ph): email/password,
 *   or Continue with Google once the identity is linked or registered.
 * - External Borrowers: email/password + OTP + SAO approval only; Google is
 *   refused at the backend for registration, linking and sign-in.
 * - CSPC students (my.cspc.edu.ph): neither an Employee nor an External Borrower.
 * - Retired ICTU Maintainer accounts cannot sign in by either method.
 */
class AuthenticationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
            'services.google.allowed_domains' => '',
            'services.institutional_email.allowed_domains' => 'cspc.edu.ph',
            'services.institutional_email.student_domains' => 'my.cspc.edu.ph',
        ]);
        Mail::fake();
        Storage::fake('local');
    }

    /* ------------------------------------------------------------------ */
    /* CSPC Employees                                                      */
    /* ------------------------------------------------------------------ */

    public function test_an_eligible_cspc_employee_signs_in_with_email_and_password(): void
    {
        $employee = $this->borrower('employee@cspc.edu.ph');

        $this->post('/login', ['email' => $employee->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($employee);
    }

    public function test_an_approved_and_linked_cspc_employee_signs_in_with_google(): void
    {
        $employee = $this->borrower('linked.employee@cspc.edu.ph');
        $this->link($employee, 'employee-subject');
        $this->fakeGoogle('linked.employee@cspc.edu.ph', 'employee-subject');

        $this->get(route('auth.google.callback'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($employee);
    }

    public function test_an_unlinked_existing_employee_must_use_local_password(): void
    {
        $this->borrower('unlinked.employee@cspc.edu.ph');
        $this->fakeGoogle('unlinked.employee@cspc.edu.ph', 'unlinked-subject');

        $this->get(route('auth.google.callback'))->assertRedirect(route('home'))->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertDatabaseCount('oauth_identities', 0);
    }

    public function test_a_new_cspc_employee_google_identity_opens_employee_registration_only(): void
    {
        $this->fakeGoogle('new.employee@cspc.edu.ph', 'new-employee-subject');

        $this->get(route('auth.google.callback'))->assertRedirect(route('register', ['source' => 'google']));
        $this->assertSame('EMPLOYEE', session('google_registration.registration_type'));
        $this->assertGuest();
    }

    /* ------------------------------------------------------------------ */
    /* External Borrowers                                                  */
    /* ------------------------------------------------------------------ */

    public function test_an_approved_external_borrower_keeps_email_and_password_sign_in(): void
    {
        $external = $this->external('agency.contact@gmail.com', 'APPROVED');

        $this->post('/login', ['email' => $external->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($external);
    }

    public function test_an_external_borrower_still_needs_otp_and_sao_approval(): void
    {
        $pending = $this->external('pending.agency@gmail.com', 'PENDING');
        $this->post('/login', ['email' => $pending->email, 'password' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->assertGuest();

        $rejected = $this->external('rejected.agency@gmail.com', 'REJECTED');
        $this->post('/login', ['email' => $rejected->email, 'password' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->assertGuest();

        $unverified = $this->external('unverified.agency@gmail.com', 'APPROVED', verified: false);
        $this->post('/login', ['email' => $unverified->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('account.verification.show', ['email' => $unverified->email]));
        $this->assertGuest();
    }

    public function test_an_external_borrower_with_a_linked_google_identity_cannot_sign_in_with_google(): void
    {
        $external = $this->external('linked.agency@gmail.com', 'APPROVED');
        $this->link($external, 'external-subject');
        $this->fakeGoogle('linked.agency@gmail.com', 'external-subject');

        $this->get(route('auth.google.callback'))->assertRedirect(route('home'))->assertSessionHasErrors('google');
        $this->assertGuest();
        /* The existing link is reported, never removed automatically. */
        $this->assertDatabaseHas('oauth_identities', ['user_id' => $external->id, 'subject' => 'external-subject']);
    }

    public function test_an_external_borrower_cannot_link_google(): void
    {
        $external = $this->external('agency.link@gmail.com', 'APPROVED');

        $this->actingAs($external)->withSession(['active_workspace' => 'BORROWER'])
            ->post('/profile/google/link', ['password' => self::PASSWORD])
            ->assertNotFound();
        $this->assertNull(session('google_link'));

        /* A callback reached with a forged link session is refused as well. */
        $this->actingAs($external)->withSession(['active_workspace' => 'BORROWER', 'google_link' => ['user_id' => $external->id, 'expires_at' => now()->addMinutes(5)->timestamp]])
            ->get(route('auth.google.callback'))->assertRedirect(route('profile.show'))->assertSessionHasErrors('google');
        $this->assertDatabaseCount('oauth_identities', 0);
    }

    public function test_an_external_google_identity_cannot_start_registration(): void
    {
        $this->fakeGoogle('new.agency@gmail.com', 'agency-new-subject');

        $this->get(route('auth.google.callback'))->assertRedirect(route('home'))->assertSessionHasErrors('google');
        $this->assertNull(session('google_registration'));
        $this->assertSame(0, User::query()->count());
    }

    public function test_external_borrowers_register_with_the_manual_form(): void
    {
        $this->post(route('register.store'), $this->externalPayload('agency.applicant@gmail.com'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('borrower_registrations', ['registration_type' => 'EXTERNAL', 'status' => 'PENDING']);
        $this->assertDatabaseCount('oauth_identities', 0);
    }

    /* ------------------------------------------------------------------ */
    /* CSPC students                                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_student_address_cannot_register_as_an_external_borrower(): void
    {
        $this->post(route('register.store'), $this->externalPayload('student@my.cspc.edu.ph'))
            ->assertSessionHasErrors('email');
        $this->assertDatabaseCount('borrower_registrations', 0);
    }

    public function test_a_student_address_cannot_register_as_a_cspc_employee(): void
    {
        $this->post(route('register.store'), [
            'registration_type' => 'EMPLOYEE', 'full_name' => 'Student Applicant', 'email' => 'student@my.cspc.edu.ph',
            'mobile_no' => '09171234567', 'password' => 'Registration!Pass2026', 'password_confirmation' => 'Registration!Pass2026',
            'employee_no' => 'STUDENT-001', 'designation' => 'Student', 'division_code' => 'ACADEMIC', 'organizational_unit_id' => 1,
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('borrower_registrations', 0);
    }

    public function test_a_student_google_identity_is_refused(): void
    {
        $this->fakeGoogle('student@my.cspc.edu.ph', 'student-subject');

        $this->get(route('auth.google.callback'))->assertRedirect(route('home'))->assertSessionHasErrors('google');
        $this->assertNull(session('google_registration'));
        $this->assertGuest();
    }

    public function test_an_existing_student_address_account_cannot_sign_in_or_link_with_google(): void
    {
        /* A provisioned account on the student domain, already linked: Google is still refused. */
        $student = $this->borrower('provisioned.student@my.cspc.edu.ph');
        $this->link($student, 'student-linked-subject');
        $this->fakeGoogle($student->email, 'student-linked-subject');
        $this->get(route('auth.google.callback'))->assertRedirect(route('home'))->assertSessionHasErrors('google');
        $this->assertGuest();

        /* Linking from Account Settings is refused at the callback. */
        $unlinked = $this->borrower('second.student@my.cspc.edu.ph');
        $this->actingAs($unlinked)->withSession(['active_workspace' => 'BORROWER', 'google_link' => ['user_id' => $unlinked->id, 'expires_at' => now()->addMinutes(5)->timestamp]])
            ->get(route('auth.google.callback'))->assertRedirect(route('profile.show'))->assertSessionHasErrors('google');
        $this->assertDatabaseMissing('oauth_identities', ['user_id' => $unlinked->id]);

        /* Their existing password sign-in is not changed by this policy. */
        auth()->logout();
        $this->post('/login', ['email' => $unlinked->email, 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
    }

    /* ------------------------------------------------------------------ */
    /* Blocked accounts                                                    */
    /* ------------------------------------------------------------------ */

    public function test_inactive_and_unverified_accounts_remain_blocked_by_password_and_google(): void
    {
        $inactive = $this->borrower('inactive@cspc.edu.ph', ['account_status' => AccountStatus::Inactive]);
        $this->post('/login', ['email' => $inactive->email, 'password' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->link($inactive, 'inactive-subject');
        $this->fakeGoogle($inactive->email, 'inactive-subject');
        $this->get(route('auth.google.callback'))->assertSessionHasErrors('google');
        $this->assertGuest();

        $unverified = $this->borrower('unverified@cspc.edu.ph', ['email_verified_at' => null]);
        $this->post('/login', ['email' => $unverified->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('account.verification.show', ['email' => $unverified->email]));
        $this->assertGuest();
    }

    public function test_a_retired_ictu_maintainer_cannot_sign_in_by_password_or_google(): void
    {
        $ictu = $this->borrower('ictu.maintainer@cspc.edu.ph', ['access_classification' => AccessClassification::IctuMaintainer]);

        $this->post('/login', ['email' => $ictu->email, 'password' => self::PASSWORD])->assertSessionHasErrors('password');
        $this->assertGuest();

        $this->link($ictu, 'ictu-subject');
        $this->fakeGoogle($ictu->email, 'ictu-subject');
        $this->get(route('auth.google.callback'))->assertRedirect(route('home'))->assertSessionHasErrors('google');
        $this->assertGuest();

        /* The legacy account is kept, only refused. */
        $this->assertDatabaseHas('users', ['id' => $ictu->id, 'access_classification' => 'ICTU_MAINTAINER']);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function borrower(string $email, array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'email' => $email,
            'password' => self::PASSWORD,
            'access_classification' => AccessClassification::BorrowerOnly,
        ]);
    }

    private function external(string $email, string $status, bool $verified = true): User
    {
        $user = $this->borrower($email, $verified ? [] : ['email_verified_at' => null]);
        BorrowerRegistration::create([
            'user_id' => $user->id, 'registration_type' => 'EXTERNAL',
            'company_name' => 'Reviewed Example Agency', 'status' => $status,
        ]);

        return $user->fresh();
    }

    private function link(User $user, string $subject): void
    {
        OauthIdentity::query()->create(['user_id' => $user->id, 'provider' => 'google', 'subject' => $subject]);
    }

    private function fakeGoogle(string $email, string $subject): void
    {
        $profile = (new SocialiteUser)->map(['id' => $subject, 'name' => 'Google Person', 'email' => $email]);
        $profile->setRaw(['sub' => $subject, 'email' => $email, 'email_verified' => true]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($profile);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function externalPayload(string $email): array
    {
        return [
            'registration_type' => 'EXTERNAL', 'full_name' => 'Agency Applicant', 'email' => $email,
            'mobile_no' => '09171234567', 'password' => 'Registration!Pass2026', 'password_confirmation' => 'Registration!Pass2026',
            'company_name' => 'Reviewed Example Agency', 'valid_id' => UploadedFile::fake()->image('valid-id.png'),
        ];
    }
}
