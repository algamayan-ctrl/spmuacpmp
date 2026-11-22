<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Handles institutional mailbox ownership verification using a short-lived OTP.
 *
 * The OTP proves mailbox ownership only. It cannot assign a role, approve a
 * self-registration, or reactivate an account. CSPC employee domain validation
 * is separate from external borrower mailbox verification.
 */
class EmailVerificationService
{
    public const RESULT_VERIFIED = 'VERIFIED';
    public const RESULT_ALREADY_VERIFIED = 'ALREADY_VERIFIED';
    public const RESULT_INVALID = 'INVALID';
    public const RESULT_EXPIRED = 'EXPIRED';
    public const RESULT_LOCKED = 'LOCKED';
    public const RESULT_MISSING = 'MISSING';

    private const OTP_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Generate a new six-digit OTP and deliver it through the configured mail
     * transport. The raw OTP is never stored in the database or application log.
     */
    public function send(User $user): bool
    {
        if ($user->hasVerifiedInstitutionalEmail()) {
            $this->invalidate($user);

            return true;
        }
        if (! app(AuthMailTransport::class)->deliversMail()) {
            Log::warning('Email verification requires a delivery-capable mail transport.', ['user_id' => $user->getKey()]);
            return false;
        }

        $code = str_pad(
            (string) random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT
        );

        $email = strtolower(trim((string) $user->email));
        $codeHash = Hash::make($code);
        $sentAt = now();
        $expiresAt = $sentAt->copy()->addMinutes(self::OTP_MINUTES);

        DB::table('email_verification_otps')->updateOrInsert(
            ['user_id' => $user->getKey()],
            [
                'email' => $email,
                'code_hash' => $codeHash,
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'last_sent_at' => $sentAt,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]
        );

        $recipientName = e($user->full_name ?: 'User');
        $safeCode = e($code);
        $expiration = self::OTP_MINUTES;

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Your SPMU-ACPMP verification code</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#172033;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                       style="max-width:680px;background:#ffffff;border:1px solid #dde6f0;border-radius:12px;">
                    <tr>
                        <td style="padding:28px 32px;border-bottom:3px solid #0b2854;">
                            <div style="font-size:26px;font-weight:800;color:#0b2854;">SPMU-ACPMP</div>
                            <div style="margin-top:5px;font-size:14px;color:#486581;">Camarines Sur Polytechnic Colleges</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 32px;">
                            <h1 style="margin:0 0 18px;font-size:24px;color:#102a43;">Verify your registered email address</h1>

                            <p style="margin:0 0 16px;font-size:15px;line-height:1.7;">
                                Dear {$recipientName},
                            </p>

                            <p style="margin:0 0 22px;font-size:15px;line-height:1.7;">
                                An SPMU-ACPMP account was registered using this email address.
                                Enter the code below to confirm mailbox ownership. Self-registration still requires SAO approval.
                            </p>

                            <div style="margin:0 0 22px;padding:18px 20px;background:#eef5ff;border:1px solid #cfe1fb;border-radius:10px;text-align:center;">
                                <div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:#60758d;text-transform:uppercase;">
                                    Verification Code
                                </div>
                                <div style="margin-top:8px;font-size:34px;font-weight:800;letter-spacing:8px;color:#0b2854;">
                                    {$safeCode}
                                </div>
                            </div>

                            <p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#486581;">
                                This code expires in {$expiration} minutes. Do not share this code with anyone.
                            </p>

                            <p style="margin:0;font-size:14px;line-height:1.65;color:#486581;">
                                If you were not expecting this account, ignore this message and contact the SPMU Admin.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        try {
            Mail::html($html, function (Message $mail) use ($user): void {
                $mail
                    ->to((string) $user->email)
                    ->subject('[SPMU-ACPMP] Your account verification code');
            });

            Log::info('SPMU-ACPMP institutional email verification OTP accepted by mail transport.', [
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'expires_in_minutes' => self::OTP_MINUTES,
            ]);

            return true;
        } catch (Throwable $exception) {
            // This is the cleanup for an OTP rejected by the mail transport.
            $this->invalidate($user);

            Log::warning('SPMU-ACPMP institutional email verification OTP delivery failed.', [
                'user_id' => $user->getKey(),
                'email' => $user->email,
                'exception' => $exception::class,
                ...$this->mailFailureDetails($exception),
            ]);

            return false;
        }
    }

    // This is the function for safe OTP mail-delivery diagnostics.
    private function mailFailureDetails(Throwable $exception): array
    {
        $message = strtolower($exception->getMessage());
        $hasResponseCode = preg_match('/\bgot(?:\s+code)?\D{0,3}([45]\d{2})\b/', $message, $matches)
            || preg_match('/\b([45]\d{2})[- ][245]\.\d{1,3}\.\d{1,3}\b/', $message, $matches);
        $responseCode = $hasResponseCode ? (int) $matches[1] : null;

        $reason = match (true) {
            in_array($responseCode, [534, 535], true), str_contains($message, 'failed to authenticate') => 'authentication_rejected',
            str_contains($message, 'certificate verify failed'), str_contains($message, 'enable crypto'),
                str_contains($message, 'starttls'), str_contains($message, 'ssl operation failed') => 'tls_failed',
            str_contains($message, 'could not be established'), str_contains($message, 'connection refused'),
                str_contains($message, 'timed out'), str_contains($message, 'getaddrinfo'),
                str_contains($message, 'name or service not known') => 'connection_failed',
            $responseCode !== null => 'smtp_rejected',
            default => 'transport_error',
        };

        return ['mail_error' => $reason, 'smtp_response_code' => $responseCode];
    }

    /**
     * Verify one OTP challenge.
     *
     * The challenge is bound to both the local user id and the exact email that
     * was current when the OTP was generated. Changing the ICTU-recorded email
     * therefore invalidates every previously generated code.
     */
    public function verify(User $user, string $code): string
    {
        if ($user->hasVerifiedInstitutionalEmail()) {
            $this->invalidate($user);

            return self::RESULT_ALREADY_VERIFIED;
        }

        return DB::transaction(function () use ($user, $code): string {
            $account = User::query()
                ->lockForUpdate()
                ->find($user->getKey());

            if (! $account) {
                return self::RESULT_MISSING;
            }

            if ($account->hasVerifiedInstitutionalEmail()) {
                $this->invalidate($account);

                return self::RESULT_ALREADY_VERIFIED;
            }

            $challenge = DB::table('email_verification_otps')
                ->where('user_id', $account->getKey())
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                return self::RESULT_MISSING;
            }

            $currentEmail = strtolower(trim((string) $account->email));
            $challengeEmail = strtolower(trim((string) $challenge->email));

            if (! hash_equals($currentEmail, $challengeEmail)) {
                DB::table('email_verification_otps')
                    ->where('user_id', $account->getKey())
                    ->delete();

                return self::RESULT_MISSING;
            }

            if (Carbon::parse($challenge->expires_at)->isPast()) {
                DB::table('email_verification_otps')
                    ->where('user_id', $account->getKey())
                    ->delete();

                return self::RESULT_EXPIRED;
            }

            $attempts = (int) $challenge->attempts;

            if ($attempts >= self::MAX_ATTEMPTS) {
                return self::RESULT_LOCKED;
            }

            if (! Hash::check($code, (string) $challenge->code_hash)) {
                $attempts++;

                DB::table('email_verification_otps')
                    ->where('user_id', $account->getKey())
                    ->update([
                        'attempts' => $attempts,
                        'updated_at' => now(),
                    ]);

                return $attempts >= self::MAX_ATTEMPTS
                    ? self::RESULT_LOCKED
                    : self::RESULT_INVALID;
            }

            $account->markInstitutionalEmailAsVerified();

            DB::table('email_verification_otps')
                ->where('user_id', $account->getKey())
                ->delete();

            return self::RESULT_VERIFIED;
        });
    }

    public function invalidate(User $user): void
    {
        DB::table('email_verification_otps')
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * Prevent repeated self-service resend clicks from generating a new code
     * every few seconds. Route throttling remains an additional protection.
     */
    public function secondsUntilResendAllowed(User $user): int
    {
        $lastSentAt = DB::table('email_verification_otps')
            ->where('user_id', $user->getKey())
            ->value('last_sent_at');

        if (! $lastSentAt) {
            return 0;
        }

        $availableAt = Carbon::parse($lastSentAt)
            ->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return max(
            0,
            $availableAt->timestamp - now()->timestamp
        );
    }

    /**
     * Check the CSPC Employee domain allow-list (INSTITUTIONAL_EMAIL_DOMAINS).
     *
     * A student domain never qualifies, even if it is added to that list by
     * mistake: an institutional address alone does not establish employment.
     */
    public function domainAllowed(string $email): bool
    {
        $allowedDomains = $this->allowedDomains();

        if ($allowedDomains === [] || $this->isStudentEmail($email)) {
            return false;
        }

        $domain = $this->domainOf($email);

        return $domain !== ''
            && in_array($domain, $allowedDomains, true);
    }

    /**
     * A CSPC student address (STUDENT_EMAIL_DOMAINS, default my.cspc.edu.ph).
     * Students may register neither as CSPC Employees nor as External Borrowers.
     */
    public function isStudentEmail(string $email): bool
    {
        $domain = $this->domainOf($email);

        return $domain !== '' && in_array($domain, $this->studentDomains(), true);
    }

    /** @return list<string> */
    public function studentDomains(): array
    {
        return collect(explode(',', (string) config('services.institutional_email.student_domains')))
            ->map(fn (string $domain) => strtolower(trim(ltrim($domain, '@'))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function domainOf(string $email): string
    {
        return strtolower((string) substr(strrchr(trim($email), '@') ?: '', 1));
    }

    /** @return list<string> */
    public function allowedDomains(): array
    {
        return collect(
            explode(
                ',',
                (string) config('services.institutional_email.allowed_domains')
            )
        )
            ->map(fn (string $domain) => strtolower(trim(ltrim($domain, '@'))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
