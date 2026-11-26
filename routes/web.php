<?php

use App\Http\Controllers\AccountabilityController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AnalyticsRecordsController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\BorrowerRegistrationController;
use App\Http\Controllers\BorrowerRegistrationReviewController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SessionActivityController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BorrowingRequestController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConditionalProcessingController;
use App\Http\Controllers\CustodyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperationalAdvisoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GatePassController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LaundryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePictureController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserAdministrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('welcome');
 })->name('home');

// Public legal pages; Google's OAuth consent screen links to both.
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [BorrowerRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [BorrowerRegistrationController::class, 'store'])
        ->middleware('throttle:5,1')->name('register.store');
    /*
     * Pending institutional accounts verify mailbox ownership by OTP.
     * The SPMU Admin creates the account; the account holder supplies only the code
     * delivered to the registered CSPC email address.
     */
    Route::get('/account/verify', [EmailVerificationController::class, 'create'])
        ->name('account.verification.show');

    Route::post('/account/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('account.verification.verify');

    Route::post('/account/verify/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('account.verification.resend');

    Route::get('/login', [LoginController::class, 'create'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])
        ->name('password.request');

    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:3,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])
        ->name('password.reset');

    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.update');

    /*
     * Google proves identity only. The callback still requires an existing,
     * active local user before any session is created.
     */
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.google.redirect');

});

// Stateful Google login and eligible borrower registration callback.
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->middleware('throttle:10,1')->name('auth.google.callback');

Route::middleware(['auth', 'active', 'verified-email', 'approved-registration', 'idle'])->group(function (): void {
    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/registrations', [BorrowerRegistrationReviewController::class, 'index'])->name('registrations.index');
        Route::get('/registrations/{registration}', [BorrowerRegistrationReviewController::class, 'show'])->name('registrations.show');
        Route::post('/registrations/{registration}/decision', [BorrowerRegistrationReviewController::class, 'decide'])->name('registrations.decide');
    });

    // This is the route group for the Dashboard and authenticated session actions.

    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::get('/operational-advisory/status', OperationalAdvisoryController::class)
        ->middleware('throttle:30,1')
        ->name('operational-advisory.status');
// Read-only, authenticated role-filtered sidebar action badge totals.
    Route::get('/sidebar/actions/status', \App\Http\Controllers\SidebarActionBadgeController::class)
        ->middleware('throttle:30,1')
        ->name('sidebar.actions.status');

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->name('logout');
    /*
     * Refreshed only by actual browser interaction.
     * Background page requests do not keep the user signed in.
     */
    Route::post('/session/activity', SessionActivityController::class)
        ->middleware('throttle:20,1')
        ->name('session.activity');

    // This is the route group for system notification records and read actions.

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    /*
     * Opening a notification marks it read and forwards to the record it
     * refers to, so the list item itself is the link.
     */
    Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])
        ->name('notifications.open');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');

    // This is the route group for the current user's profile and signature settings.

    Route::get('/profile', [ProfileController::class, 'show'])
        ->name('profile.show');

    Route::put('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::post('/profile/signature', [ProfileController::class, 'signature'])
        ->name('profile.signature');

    Route::get('/profile/picture', [ProfilePictureController::class, 'show'])
        ->name('profile.picture.show');

    Route::get('/users/{user}/picture', [ProfilePictureController::class, 'showForUser'])
        ->name('users.picture.show');

    Route::post('/profile/picture', [ProfilePictureController::class, 'update'])
        ->name('profile.picture.update');

    Route::delete('/profile/picture', [ProfilePictureController::class, 'destroy'])
        ->name('profile.picture.destroy');

    Route::get('/protected-files/{file}/preview', [DocumentController::class, 'protectedFilePreview'])
        ->name('files.preview');

    Route::get('/protected-files/{file}', [DocumentController::class, 'protectedFile'])
        ->name('files.show');

    // This is the route group for Inventory pages for authorized Borrower and SPMU users.

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/inventory', [InventoryController::class, 'index'])
            ->name('inventory.index');

        Route::get('/inventory-availability', [InventoryController::class, 'availabilityData'])
            ->name('inventory.availability');
        Route::get('/inventory/{inventory}', [InventoryController::class, 'show'])
            ->whereNumber('inventory')
            ->name('inventory.show');

        Route::get('/inventory/{inventory}/stock-card/print', [InventoryController::class, 'printStockCard'])
            ->whereNumber('inventory')
            ->name('inventory.stock-card.print');

        Route::get('/inventory/{inventory}/stock-card/export', [InventoryController::class, 'exportStockCard'])
            ->whereNumber('inventory')
            ->name('inventory.stock-card.export');
    });

    // This is the route group for authorized SPMU inventory management.

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/inventory/create', [InventoryController::class, 'create'])
            ->name('inventory.create');

        Route::post('/inventory', [InventoryController::class, 'store'])
            ->name('inventory.store');

        Route::get('/inventory/{inventory}/edit', [InventoryController::class, 'edit'])
            ->name('inventory.edit');

        Route::put('/inventory/{inventory}', [InventoryController::class, 'update'])
            ->name('inventory.update');

        Route::delete('/inventory/{inventory}', [InventoryController::class, 'destroy'])
            ->whereNumber('inventory')
            ->name('inventory.destroy');

        Route::post('/inventory/{inventory}/adjustments', [InventoryController::class, 'adjust'])
            ->whereNumber('inventory')
            ->name('inventory.adjust');

    });

    // This is the route group for the borrowing and SPMU operations calendar.

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/calendar', [CalendarController::class, 'index'])
            ->name('calendar.index');
    });

    // This is the route group for creating and submitting a borrowing request.

    Route::middleware('workspace:BORROWER')->group(function (): void {

        Route::get('/requests/operational-date-availability', [BorrowingRequestController::class, 'operationalDateAvailability'])
            ->name('requests.operational-date-availability');

        Route::get('/requests/create', [BorrowingRequestController::class, 'create'])
            ->name('requests.create');

        Route::post('/requests', [BorrowingRequestController::class, 'store'])
            ->name('requests.store');
    });

    // This is the route group for active borrowing request records.

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/requests', [BorrowingRequestController::class, 'index'])
            ->name('requests.index');

        Route::get('/requests/{borrowingRequest}', [BorrowingRequestController::class, 'show'])
            ->name('requests.show');
    });

    // This is the route group for authorized borrower request actions.

    Route::middleware('workspace:BORROWER')->group(function (): void {

        Route::get('/requests/{borrowingRequest}/edit', [BorrowingRequestController::class, 'edit'])
            ->name('requests.edit');

        Route::put('/requests/{borrowingRequest}', [BorrowingRequestController::class, 'update'])
            ->name('requests.update');

        Route::post('/requests/{borrowingRequest}/submit', [BorrowingRequestController::class, 'submit'])
            ->name('requests.submit');
    });

    // This is the route group for authorized borrowing request cancellation.

    Route::post('/requests/{borrowingRequest}/cancel', [BorrowingRequestController::class, 'cancel'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('requests.cancel');

    // This is the route group for SPMU verification and final borrowing approval.

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/verifications', [ApprovalController::class, 'verificationIndex'])
            ->name('verifications.index');

        Route::post('/verifications/{borrowingRequest}', [ApprovalController::class, 'verify'])
            ->name('verifications.verify');

        Route::get('/approvals', [ApprovalController::class, 'index'])
            ->name('approvals.index');

        Route::post('/approvals/{borrowingRequest}', [ApprovalController::class, 'decide'])
            ->name('approvals.decide');
    });

    // This is the route group for authorized document previews, system records, and downloads.

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');

    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('documents.view');

    Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])
        ->name('documents.preview');

    Route::get('/documents/{document}/original', [DocumentController::class, 'originalPreview'])
        ->name('documents.original');

    Route::get('/documents/{document}/record', [DocumentController::class, 'record'])
        ->name('documents.record');

    // This is the route group for borrowing custody records, physical release, and return processing.

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/custody', [CustodyController::class, 'index'])
            ->name('custody.index');

        Route::get('/custody/{custody}', [CustodyController::class, 'show'])
            ->name('custody.show');
    });

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/release', [CustodyController::class, 'releaseIndex'])
            ->name('custody.release.index');

        Route::get('/release/{custody}', [CustodyController::class, 'releaseShow'])
            ->name('custody.release.show');

        Route::get('/return', [CustodyController::class, 'returnIndex'])
            ->name('custody.return.index');

        Route::get('/return/{custody}', [CustodyController::class, 'returnShow'])
            ->name('custody.return.show');
    });

    Route::post('/custody/{custody}/schedule-pickup', [CustodyController::class, 'schedulePickup'])
        ->middleware('workspace:SPMU')
        ->name('custody.schedule-pickup');

    Route::post('/custody/{custody}/reschedule-pickup', [CustodyController::class, 'reschedulePickup'])
        ->middleware('workspace:SPMU')
        ->name('custody.reschedule-pickup');

    Route::post('/custody/{custody}/request-pickup-reschedule', [CustodyController::class, 'requestPickupReschedule'])
        ->middleware('workspace:BORROWER')
        ->name('custody.request-pickup-reschedule');

    Route::post('/custody/{custody}/report-preparation-issue', [CustodyController::class, 'reportPreparationIssue'])
        ->middleware('workspace:SPMU')
        ->name('custody.report-preparation-issue');

    Route::post('/custody/{custody}/preparation-issues/{preparationIssue}/resolve', [CustodyController::class, 'resolvePreparationIssue'])
        ->middleware('workspace:SPMU')
        ->name('custody.resolve-preparation-issue');

    Route::post('/custody/{custody}/prepare', [CustodyController::class, 'prepare'])
        ->middleware('workspace:SPMU')
        ->name('custody.prepare');

    Route::post('/custody/{custody}/release', [CustodyController::class, 'release'])
        ->middleware('workspace:SPMU')
        ->name('custody.release');

    Route::post('/custody/{custody}/return', [CustodyController::class, 'receiveReturn'])
        ->middleware('workspace:SPMU')
        ->name('custody.return');

    // This is the route group for Gate Pass processing by SPMU Action Officer.

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/gate-passes', [GatePassController::class, 'index'])
            ->name('gate-passes.index');

        Route::get('/gate-passes/{gatePass}', [GatePassController::class, 'show'])
            ->name('gate-passes.show');

        Route::post(
            '/gate-passes/{gatePass}/verify',
            [ConditionalProcessingController::class, 'gatePass']
        )->name('gate-passes.verify');
    });

    // This is the route group for Laundry Operations managed by SPMU Action Officer.

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/laundry', [LaundryController::class, 'index'])
            ->name('laundry.index');

        Route::get('/laundry/completed', [LaundryController::class, 'completed'])
            ->name('laundry.completed');

        Route::get('/laundry/{laundryJob}', [LaundryController::class, 'show'])
            ->name('laundry.show');

    });

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/spmu/laundry', [LaundryController::class, 'spmuIndex'])
            ->name('laundry.spmu.index');

        Route::get('/spmu/laundry/{laundryJob}', [LaundryController::class, 'spmuShow'])
            ->name('laundry.spmu.show');

        Route::post('/spmu/laundry/{laundryJob}/upload-form', [LaundryController::class, 'upload'])
            ->name('laundry.spmu.upload-form');

        // This is the route to record the Action Officer's finding that linen was not returned.
        Route::post('/spmu/laundry/{laundryJob}/confirm-not-returned', [LaundryController::class, 'confirmNotReturned'])
            ->name('laundry.spmu.confirm-not-returned');

    });

    // This is the route group for borrower obligations and SPMU accountability actions.

    Route::get('/accountability', [AccountabilityController::class, 'index'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('accountability.index');

    Route::get('/accountability/borrowers/{borrower}', [AccountabilityController::class, 'showBorrowerWorkspace'])
        ->middleware('workspace:SPMU')
        ->name('accountability.borrower');

    Route::post('/incidents/{incident}/bill', [AccountabilityController::class, 'billIncident'])
        ->middleware('workspace:SPMU')
        ->name('incidents.bill');

    Route::post('/incidents/{incident}/resolve', [AccountabilityController::class, 'resolveIncident'])
        ->middleware('workspace:SPMU')
        ->name('incidents.resolve');

    Route::post('/incidents/{incident}/disposition', [AccountabilityController::class, 'recordOfficialDisposition'])
        ->middleware('workspace:SPMU')
        ->name('incidents.disposition.record');

    Route::post('/incidents/{incident}/disposition/verify', [AccountabilityController::class, 'verifyOfficialDispositionCompliance'])
        ->middleware('workspace:SPMU')
        ->name('incidents.disposition.verify');

    Route::post('/incidents/{incident}/rslddp/upload', [AccountabilityController::class, 'uploadAccomplishedRslddp'])
        ->middleware('workspace:SPMU')
        ->name('incidents.rslddp.upload');

    Route::post('/incidents/{incident}/rslddp/billing', [AccountabilityController::class, 'recordOfficialBillingStatement'])
        ->middleware('workspace:SPMU')
        ->name('incidents.rslddp.billing');

    Route::post('/incidents/{incident}/rslddp/resolve', [AccountabilityController::class, 'resolveRslddpSettlement'])
        ->middleware('workspace:SPMU')
        ->name('incidents.rslddp.resolve');

    Route::get('/restrictions/{restriction}/notice', [AccountabilityController::class, 'restrictionNotice'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('restrictions.notice');

    Route::post('/overdue/{overdue}/bill', [AccountabilityController::class, 'billOverdue'])
        ->middleware('workspace:SPMU')
        ->name('overdue.bill');

    Route::post('/overdue/{overdue}/resolve-without-charge', [AccountabilityController::class, 'resolveOverdueWithoutCharge'])
        ->middleware('workspace:SPMU')
        ->name('overdue.resolve-without-charge');

    Route::post('/billings/{billing}/payments', [AccountabilityController::class, 'recordPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.store');

    Route::post('/payments/{payment}/verify', [AccountabilityController::class, 'verifyPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.verify');

    Route::post('/billings/{billing}/waive', [AccountabilityController::class, 'waive'])
        ->middleware('workspace:SPMU')
        ->name('billings.waive');

    Route::post('/accountability/violations/{violation}/review', [AccountabilityController::class, 'reviewViolation'])
        ->middleware('workspace:SPMU')
        ->name('accountability.violations.review');

    // This is the route group for academic period configuration.

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/administration/policies', [PolicyController::class, 'index'])
            ->name('policies.index');

        Route::post(
            '/administration/policies/academic-periods',
            [PolicyController::class, 'storeAcademicPeriod']
        )->name('policies.academic-periods.store');

        Route::put(
            '/administration/policies/academic-periods/{period}',
            [PolicyController::class, 'updateAcademicPeriod']
        )->name('policies.academic-periods.update');

        Route::put(
            '/administration/policies/sanctions/{offenseNo}',
            [PolicyController::class, 'updateSanctionRule']
        )->whereNumber('offenseNo')->name('policies.sanctions.update');

        Route::put(
            '/administration/policies/offense-application',
            [PolicyController::class, 'updateOffenseApplication']
        )->name('policies.offense-application.update');

        Route::put(
            '/administration/policies/weekly-schedule',
            [PolicyController::class, 'updateWeeklyScheduleBatch']
        )->name('policies.weekly-schedule.batch-update');

        Route::put(
            '/administration/policies/weekly-schedule/{weekday}',
            [PolicyController::class, 'updateWeeklySchedule']
        )->whereNumber('weekday')->name('policies.weekly-schedule.update');

        Route::post(
            '/administration/policies/date-exceptions',
            [PolicyController::class, 'storeDateException']
        )->name('policies.date-exceptions.store');

        Route::delete(
            '/administration/policies/date-exceptions/{exception}',
            [PolicyController::class, 'destroyDateException']
        )->name('policies.date-exceptions.destroy');

    });

    // This is the route group for authorized reports and analytics.

    Route::middleware('workspace:SPMU')->group(function (): void {

        /*
         * Analytics and Reports are separate modules: Analytics summarises and
         * interprets, Reports produces the detailed records and exports.
         */
        Route::get('/analytics', AnalyticsController::class)
            ->name('analytics.index');

        Route::get('/reports/analytics-records', AnalyticsRecordsController::class)
            ->name('reports.analytics-records');

        Route::get('/reports', [ReportController::class, 'index'])
            ->name('reports.index');

        Route::get('/reports/export/{type}', [ReportController::class, 'export'])
            ->name('reports.export');

        /*
         * The printable copy resolves its scope exactly as the screen did,
         * so it prints the whole record set rather than the page in view.
         */
        Route::get('/reports/print/{type}', [ReportController::class, 'print'])
            ->name('reports.print');
    });

    Route::get('/reports/audit', [ReportController::class, 'audit'])
        ->middleware('workspace:SPMU')
        ->name('reports.audit');

    // Controlled recovery of one eligible change; the controller and service re-check audience, role and record state.
    Route::post('/reports/audit/{event}/revert', [ReportController::class, 'revertAuditEvent'])
        ->whereNumber('event')
        ->middleware('workspace:SPMU')
        ->name('reports.audit.revert');

    // Delivery Records: addresses, providers and provider responses. SPMU Admin/Head only; enforced in the controller.
    Route::get('/reports/notifications', [ReportController::class, 'notifications'])
        ->middleware('workspace:SPMU')
        ->name('reports.notifications');

    Route::post('/reports/notifications/{delivery}/resolve', [ReportController::class, 'resolveNotificationFailure'])
        ->middleware('workspace:SPMU')
        ->name('reports.notifications.resolve');

    Route::post('/reports/notifications/resolve-bulk', [ReportController::class, 'resolveBulkNotificationFailures'])
        ->middleware('workspace:SPMU')
        ->name('reports.notifications.resolveBulk');

    // This is the route group for user accounts and configuration authorized for SPMU Admin.

    Route::prefix('administration')
        ->name('administration.')
        ->middleware('workspace:SPMU')
        ->group(function (): void {

            Route::get('/', [AdministrationController::class, 'index'])
                ->name('index');

            Route::get('/settings', [SettingController::class, 'index'])
                ->name('settings.index');

            Route::put('/settings/{setting}', [SettingController::class, 'update'])
                ->name('settings.update');

            Route::resource('users', UserAdministrationController::class)
                ->except(['show', 'destroy']);

            Route::patch('/users/{user}/deactivate', [UserAdministrationController::class, 'deactivate'])
                ->name('users.deactivate');

            Route::patch('/users/{user}/reactivate', [UserAdministrationController::class, 'reactivate'])
                ->name('users.reactivate');

            // Correct only unused, unverified registration mistakes; never delete operational history.
            Route::delete('/users/{user}/pending', [UserAdministrationController::class, 'deletePendingRegistration'])
                ->name('users.delete-pending');

            Route::post('/users/{user}/resend-email-verification-otp', [UserAdministrationController::class, 'resendEmailVerification'])
                ->middleware('throttle:5,1')
                ->name('users.email-verification.resend');
        });
});
