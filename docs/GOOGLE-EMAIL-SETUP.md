# Google and email setup

The code uses real Laravel Socialite and Laravel mail transports. Localhost can call real Google and SMTP services; hosting is not required for integration testing. Provider configuration and actual inbox/callback verification are still required. Never enter a Google password into SPMU-ACPMP, paste secrets into chat, or commit a live .env.

## Google OAuth

Continue with Google requests Google's account chooser, so users can switch accounts. Google handles password entry and may reuse an existing authenticated Google session. The registration form's System password is a separate local fallback password.

1. The project owner opens Google Cloud Console → Google Auth Platform and selects the intended CSPC/SPMU project. Use an approved display name and support email. Creating a persistent OAuth client requires the owner's account action.
2. Configure an External audience so both CSPC and authorized external Google accounts can participate. While the provider app is in Testing, configure the intended test users as required by Google. CSPC organizational restrictions may still require its Workspace administrator.
3. Create a Web application OAuth client using only openid, email and profile scopes. No Gmail/Drive access is needed.
4. Register this exact development callback: http://127.0.0.1:8765/auth/google/callback. localhost and 127.0.0.1 are different; the callback and application session origin must match.
5. If the checkout has no .env yet, copy .env.example to .env privately and preserve the existing environment/database values for your intended runtime. For a new installation only, generate an application key; preserve an existing production key. Privately set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI; set APP_URL=http://127.0.0.1:8765 and leave GOOGLE_ALLOWED_DOMAINS blank for the confirmed external registration policy. Keep INSTITUTIONAL_EMAIL_DOMAINS=cspc.edu.ph for live employee registration.
6. Clear cached configuration if present and restart the intended runtime with its provider settings. The current updated preview reads the private checkout .env and does not override mail to array. Never migrate:fresh or reseed the existing application database.
7. After deployment, register the exact HTTPS callback for the chosen application domain and set the same value in the hosting environment. Cloudflare DNS/proxying does not create OAuth credentials or send email.

Google flow: new verified @cspc.edu.ph email → locked employee form; other verified Google-account emails → locked external form. Yahoo/government addresses must belong to Google accounts. Submit completed registration → SAO queue. Existing linked users sign in by provider subject. Existing unlinked local email matches require password sign-in and linking in Account Settings; the callback never silently links them.

Sources: https://developers.google.com/identity/protocols/oauth2/web-server and https://laravel.com/framework/docs/13.x/socialite.

## Real email transport

Current localhost Docker verification — 23 November 2026: the owner configured a Gmail App Password privately in `.env.docker`. SMTP host is `smtp.gmail.com`, port `587`, scheme `smtp`; username and sender are `spmuacpmp@gmail.com`. App, queue and scheduler were recreated from existing images with live migrations disabled for this refresh, retaining the database and storage. The current application is at `http://localhost:8080`.

Gmail accepted authentication from the refreshed app. One actual verification-code email was accepted for the owner's explicitly authorized inbox using an isolated in-memory account, and internal OTP verification passed (1 test / 10 assertions). The owner confirmed receipt in the spmuacpmp@gmail.com Inbox. Auth/email regression: 28 tests / 186 assertions passed. No live registration was created; live user/inventory/request/migration counts are unchanged. For normal registration/Resend OTP, request a fresh code for the intended real account; the smoke-test code belongs only to the isolated account. Google OAuth acceptance remains a separate check.

Changing `.env.docker` does not update existing containers through a plain restart. Refresh the relevant services with the new environment, preserve the database/storage, and avoid rerunning live migrations/seeding for a mail-only configuration change. Never print or commit MAIL_PASSWORD.

Select an existing institutional/Hostinger SMTP mailbox or another supported provider. For an eligible Gmail account, an App Password can be used for development testing; this is distinct from the normal Google password and may be unavailable under account/organization restrictions. Do not invent a CSPC sender address: MAIL_FROM_ADDRESS must be authorized by the selected provider.

Set the provider's MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME and MAIL_TIMEOUT=15 privately. Gmail SMTP uses smtp.gmail.com with port 465 and MAIL_SCHEME=smtps, or the provider's documented STARTTLS settings. Institutional/Hostinger values must come from that mailbox's control panel, including SPF/DKIM settings for the actual sending domain.

Run php artisan spmu:auth-readiness --json. This prints only non-secret configuration checks. Add --check-smtp to authenticate/connect to the selected SMTP provider without sending email. To explicitly send a harmless diagnostic, the owner runs php artisan spmu:auth-readiness --send-test-to=their-authorized-test-address. Transport acceptance alone does not prove inbox delivery; check the actual inbox/spam folder and timestamp. Do not use log/array or a fallback-to-log transport for real OTP/reset testing.

Manual flow: Sign up → choose CSPC Employee or External Borrower → complete form → submit → Verify Email → enter emailed 6-digit code → SAO queue. Codes expire in 10 minutes, allow 5 incorrect attempts, and have a 60-second resend cooldown. Borrowing access remains unavailable until SAO approval.

Password recovery: submit an active verified account email → receive the existing expiring Laravel reset link → choose a strong local password. Unknown and ineligible accounts receive a neutral response. A reset never approves registration or changes roles.

## Hosting handoff and live acceptance

Use the same environment keys on Laravel Cloud or the selected compatible Hostinger PHP application plan. Verify PHP/Laravel requirements, MariaDB 11.4, HTTPS/proxy trust, persistent shared sessions/cache, private uploads, correct APP_URL, and real mail transport. Do not assume any Hostinger plan or Cloud database matches MariaDB 11.4 without checking the chosen service. Existing deployment-readiness and ICTU gates still apply.

- VERIFIED COMPLETE (configuration scope): existing private .env.docker provider keys were reused in an ignored checkout .env for the updated preview, on 14 October 2026. The original Docker environment was preserved. Do not copy the Docker database credentials/runtime into this preview.
- VERIFIED COMPLETE (connection scope): Gmail SMTP accepted connection/authentication through the actual Laravel mail transport. --check-smtp sends no email and does not prove inbox delivery.
- BLOCKED: real Google employee/external consent and callback. The actual Google request returned Error 400 redirect_uri_mismatch; the intended preview callback must be added to the existing Web client.
- BLOCKED: actual manual OTP inbox receipt, expiry/resend, and password-reset delivery until an authorized test recipient/inbox interaction is provided. SMTP configuration is now present; delivery remains unverified.
- NOT STARTED: live existing-account linking and changed/inactive account checks.
- NOT READY: hosting/ICTU approval and full deployment acceptance.

Configuration files and secrets remain on the owner's machine/provider environment. No provider account, DNS record, paid resource, or production deployment has been created by this increment.

## Exact current callback correction

1. Sign in to [Google Cloud Console — Clients](https://console.cloud.google.com/auth/clients) with the owner account and select the existing project. The prepared browser tab currently requires owner sign-in; never send its password or client secret in chat.
2. Open the existing Web application client matching the private GOOGLE_CLIENT_ID. Add `http://127.0.0.1:8765/auth/google/callback` to **Authorized redirect URIs**, preserving the existing localhost:8080 callback and other approved entries. Save. This is a redirect URI, not merely an Authorized JavaScript origin. Google requires an exact callback match: [Web server OAuth documentation](https://developers.google.com/identity/protocols/oauth2/web-server).
3. The current preview already uses that exact URI, persistent sessions, and openid/email/profile scopes. Return to `http://127.0.0.1:8765/login`, click Continue with Google, and use the intended test account. Provider account/consent policy must also permit that account; do not weaken organizational access controls.
4. Confirm the test inbox before actual application mail tests. Verify the diagnostic in inbox/spam, then test manual OTP → SAO queue and password-reset link → one-use local password change. The owner enters received OTP/reset credentials privately and performs the final browser password-change submission.

The current private .env is a **local integration configuration**: it points to the preserved SQLite preview and preserves its demonstration key. It is not a production environment or proof of MariaDB deployment. Production needs its own protected key, approved MariaDB 11.4 connection/storage, HTTPS callback and separate hosting/ICTU acceptance. Do not deploy this local .env.
