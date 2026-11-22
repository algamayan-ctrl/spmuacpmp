<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AuthMailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_transport_never_claims_a_real_otp_was_sent_or_keeps_a_new_code(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['mail.default' => 'log']);
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->assertFalse(app(EmailVerificationService::class)->send($user));
        $this->assertDatabaseMissing('email_verification_otps', ['user_id' => $user->id]);
        Mail::assertNothingSent();
    }

    public function test_log_fallback_cannot_mask_an_undelivered_auth_email(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'failover']);
        $this->assertFalse(app(\App\Services\AuthMailTransport::class)->deliversMail());
    }

    #[DataProvider('mailTransportFailures')]
    public function test_failed_otp_delivery_logs_only_safe_diagnostics_and_invalidates_the_code(
        string $failure,
        string $expectedReason,
        ?int $expectedCode,
    ): void {
        config(['mail.default' => 'smtp']);
        $user = User::factory()->unverified()->create();
        $originalStatus = $user->account_status;
        Mail::shouldReceive('html')->once()->andThrow(new TransportException($failure));
        Log::spy();

        $this->assertFalse(app(EmailVerificationService::class)->send($user));
        $this->assertDatabaseMissing('email_verification_otps', ['user_id' => $user->id]);
        $this->assertFalse($user->fresh()->hasVerifiedInstitutionalEmail());
        $this->assertSame($originalStatus, $user->fresh()->account_status);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($user, $expectedReason, $expectedCode): bool {
            $this->assertSame('SPMU-ACPMP institutional email verification OTP delivery failed.', $message);
            $this->assertSame([
                'user_id' => $user->id,
                'email' => $user->email,
                'exception' => TransportException::class,
                'mail_error' => $expectedReason,
                'smtp_response_code' => $expectedCode,
            ], $context);
            $this->assertStringNotContainsString('synthetic-private-password', json_encode($context));
            $this->assertStringNotContainsString('749203', json_encode($context));

            return true;
        });
    }

    public static function mailTransportFailures(): array
    {
        return [
            'gmail authentication' => ['Failed to authenticate. Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted. synthetic-private-password OTP 749203"', 'authentication_rejected', 535],
            'smtp response without wrapper' => ['535 5.7.8 Username and Password not accepted.', 'authentication_rejected', 535],
            'tls certificate' => ['Unable to connect with STARTTLS: certificate verify failed. synthetic-private-password OTP 749203', 'tls_failed', null],
            'connection failure' => ['Connection could not be established: Connection refused. synthetic-private-password OTP 749203', 'connection_failed', null],
            'smtp rejection' => ['Expected response code "250" but got code "550", with message "550 5.7.1 Message rejected"', 'smtp_rejected', 550],
            'unclassified failure' => ['Unexpected transport failure: synthetic-private-password OTP 749203', 'transport_error', null],
        ];
    }

    public function test_preview_password_reset_reports_configuration_failure_without_creating_token(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['mail.default' => 'array']);
        Notification::fake();
        $user = User::factory()->create();
        $csrf = 'synthetic-mail-configuration-test-token';
        $this->withSession(['_token' => $csrf])->post(route('password.email'), ['email' => $user->email, '_token' => $csrf])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::assertNothingSent();
    }

    public function test_password_reset_message_remains_generic_for_unknown_and_ineligible_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $response = $this->post(route('password.email'), ['email' => 'unknown@example.test']);
        $status = session('status');
        $response->assertSessionHasNoErrors();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status', $status);
        Notification::assertNothingSent();
    }

    public function test_valid_password_reset_uses_the_delivered_token_once_without_approving_registration(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        $notice = Notification::sent($user, ResetPassword::class)->first();
        $this->assertNotNull($notice);
        $this->assertNotSame($notice->token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        $payload = ['email' => $user->email, 'token' => $notice->token, 'password' => 'Updated!Pass2026', 'password_confirmation' => 'Updated!Pass2026'];
        $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_expired_reset_token_and_changed_email_do_not_change_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'Original!Pass2026']);
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = Notification::sent($user, ResetPassword::class)->first()->token;
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'Changed!Pass2026', 'password_confirmation' => 'Changed!Pass2026'];
        $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
        $data['email'] = 'other@example.test';
        $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Original!Pass2026', $user->fresh()->password));
    }
}
