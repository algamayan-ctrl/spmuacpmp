<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Auth\Concerns\EstablishesAuthenticatedSession;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OauthIdentity;
use App\Services\AuditService;
use App\Services\BorrowerRequestAffiliationService;
use App\Services\EmailVerificationService;
use App\Services\GoogleRegistrationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google sign-in.
 *
 * Google proves who the person is. SPMU-ACPMP decides whether that person may
 * enter. Known identities use their linked local account. New identities may
 * complete Borrower registration, with email locked and SAO approval required.
 */
class GoogleAuthController extends Controller
{
    use EstablishesAuthenticatedSession;

    /**
     * Send the visitor to Google's consent screen.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->forget(['google_registration', 'google_link']);
        if (! $this->isConfigured()) {
            return redirect()->route('home')->withErrors([
                'google' => 'Google sign-in is not configured on this server. Use your email and password.',
            ]);
        }

        $applicationUrl = rtrim((string) config('app.url'), '/');
        if ((string) config('services.google.redirect') !== $applicationUrl.'/auth/google/callback') {
            // Name the mismatch for ICTU; both values are public URLs, never secrets.
            Log::warning('Google sign-in refused: GOOGLE_REDIRECT_URI does not match APP_URL.', [
                'app_url' => $applicationUrl,
                'google_redirect_uri' => (string) config('services.google.redirect'),
                'expected_redirect_uri' => $applicationUrl.'/auth/google/callback',
            ]);

            return redirect()->route('home')->withErrors([
                'google' => 'Google sign-in needs server configuration. Contact the SPMU Admin for assistance.',
            ]);
        }
        // Start state and receive the callback on the same browser origin.
        // Google still requires this exact callback in its client configuration.
        $applicationOrigin = parse_url($applicationUrl, PHP_URL_SCHEME).'://'.parse_url($applicationUrl, PHP_URL_HOST);
        if ($port = parse_url($applicationUrl, PHP_URL_PORT)) {
            $applicationOrigin .= ':'.$port;
        }
        if ($request->getSchemeAndHttpHost() !== $applicationOrigin) {
            return redirect()->away($applicationUrl.'/auth/google');
        }

        try {
            $driver = Socialite::driver('google')->scopes(['openid', 'email', 'profile']);

            /*
             * The hosted-domain parameter only pre-filters Google's own account
             * chooser. It is a convenience, never the security check - the
             * returned address is still validated server-side below.
             */
            $hint = $this->allowedDomains()[0] ?? null;
            $parameters = ['prompt' => 'select_account'];

            if ($hint !== null) {
                $parameters['hd'] = $hint;
            }
            $driver->with($parameters);

            return $driver->redirect();
        } catch (Throwable $exception) {
            return $this->fail($exception, 'Google sign-in is unavailable right now. Use your email and password.');
        }
    }

    /**
     * Handle Google's callback and sign in the matching local account.
     */
    public function callback(Request $request): RedirectResponse
    {
        $request->session()->forget('google_registration');
        // Invalidate any in-flight linking session from before this revision.
        $legacyLink = $request->session()->pull('google_link');
        if ($legacyLink !== null || $request->user() !== null) {
            $request->session()->forget('state');
            $target = $request->user() !== null ? 'profile.show' : 'home';

            return redirect()->route($target)->withErrors([
                'google' => 'Google account linking is no longer available. Sign out before using Continue with Google.',
            ]);
        }
        if (! $this->isConfigured()) {
            return redirect()->route('home')->withErrors([
                'google' => 'Google sign-in is not configured on this server. Use your email and password.',
            ]);
        }

        /* The visitor dismissed Google's consent screen. */
        if ($request->filled('error')) {
            return redirect()->route('home')->withErrors([
                'google' => 'Google sign-in was cancelled. You can try again or use your email and password.',
            ]);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            return $this->fail($exception, 'Google sign-in could not be completed. Please try again.');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));

        $verified = $googleUser->getRaw()['email_verified'] ?? null;
        if ($verified !== true || trim((string) $googleUser->getId()) === '') {
            return redirect()->route('home')->withErrors([
                'google' => 'Google could not provide a verified identity. Use your email and password.',
            ]);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return redirect()->route('home')->withErrors([
                'google' => 'Google did not return an email address for this account. Use your email and password.',
            ]);
        }

        if (! $this->domainAllowed($email)) {
            return redirect()->route('home')->withErrors([
                'google' => 'This Google account is not from an allowed domain. Contact the SPMU Admin for account access assistance.',
            ]);
        }

        /* A CSPC student address never registers or signs in through Google. */
        if (app(EmailVerificationService::class)->isStudentEmail($email)) {
            return redirect()->route('home')->withErrors([
                'google' => 'Continue with Google is not available for CSPC student email addresses.',
            ]);
        }

        // A CSPC Google identity alone never proves employee borrowing eligibility.
        $user = OauthIdentity::query()->where('provider', 'google')->where('subject', $googleUser->getId())->first()?->user;

        if (! $user) {
            if (! User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                /* Google sign-up is for CSPC Employees only; External Borrowers and students use the email form. */
                $emails = app(EmailVerificationService::class);
                if (! $emails->domainAllowed($email)) {
                    return redirect()->route('home')->withErrors([
                        'google' => self::EXTERNAL_MESSAGE.' New External Borrowers register with the Sign up form.',
                    ]);
                }

                app(GoogleRegistrationContext::class)->remember($request, $email,
                    trim((string) $googleUser->getId()), $googleUser->getName());

                return redirect()->route('register', ['source' => 'google']);
            }
            return redirect()->route('home')->withErrors([
                'google' => 'This Google account is not registered or linked in SPMU-ACPMP. Existing accounts without a Google identity must sign in with email and password. New eligible CSPC Employees may register through Google and await SAO approval.',
            ]);
        }

        /* A linked identity never lets an External Borrower in through Google. */
        if ($this->isExternalBorrower($user)) {
            return redirect()->route('home')->withErrors(['google' => self::EXTERNAL_MESSAGE]);
        }

        /* The same account-status rule the credential login applies. */
        if ($user->account_status !== AccountStatus::Active) {
            return redirect()->route('home')->withErrors([
                'google' => 'This account is inactive. Contact the SPMU Admin if you believe this is an error.',
            ]);
        }

        Auth::login($user);

        $error = $this->establishAuthenticatedSession($request, $user);

        if ($error !== null) {
            return redirect()->route('home')->withErrors(['google' => $error]);
        }

        app(AuditService::class)->record('GOOGLE_LOGIN', $user, after: ['provider' => 'google']);

        return redirect()->intended(route('dashboard'));
    }

    private const EXTERNAL_MESSAGE = 'External Borrowers sign in with email and password. Continue with Google is for CSPC Employee accounts only.';

    /** External Borrowers (an External borrower registration) use email/password + OTP + SAO approval only. */
    private function isExternalBorrower(?User $user): bool
    {
        return $user !== null && app(BorrowerRequestAffiliationService::class)->isExternal($user);
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * Optional allow-list, comma separated. Blank means every Google account
     * may reach the local user lookup, which still decides access.
     *
     * @return list<string>
     */
    private function allowedDomains(): array
    {
        return collect(explode(',', (string) config('services.google.allowed_domains')))
            ->map(fn (string $domain) => strtolower(trim(ltrim($domain, '@'))))
            ->filter()
            ->values()
            ->all();
    }

    private function domainAllowed(string $email): bool
    {
        $domains = $this->allowedDomains();

        if ($domains === []) {
            return true;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, $domains, true);
    }

    /**
     * Log the real cause for ICTU and show the visitor a plain message. OAuth
     * secrets, tokens and stack traces never reach the response.
     */
    private function fail(Throwable $exception, string $message): RedirectResponse
    {
        Log::warning('Google sign-in failed.', [
            'exception' => $exception::class,
        ]);

        return redirect()->route('home')->withErrors(['google' => $message]);
    }
}
