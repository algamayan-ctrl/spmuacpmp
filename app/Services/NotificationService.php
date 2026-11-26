<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\BorrowerRegistration;
use App\Models\BorrowerRestriction;
use App\Models\BillingStatement;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OverdueCase;
use App\Models\Sanction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    public function __construct(
        private readonly OperationalCalendarService $operationalCalendar,
    ) {}

    /** @param iterable<User> $recipients */
    public function send(
        string $eventCode,
        iterable $recipients,
        string $message,
        ?Model $source = null,
        array $channels = ['SYSTEM', 'EMAIL'],
        array $requiredChannels = [],
        bool $deferEmailUntilCommit = false
    ): NotificationEvent {
        $event = NotificationEvent::query()->create([
            'event_code' => $eventCode,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'created_by_user_id' => auth()->id(),
            'payload_snapshot_json' => [
                'message' => $message,
            ],
            'occurred_at' => now(),
        ]);

        /*
         * The event and its in-system records are ordinary rows: inside a
         * business transaction they commit or roll back with it. An email
         * cannot be recalled, so it is only handed to the mail transport
         * once that transaction has committed. With no open transaction the
         * attempt runs immediately, exactly as before. A rolled-back action
         * therefore sends nothing and leaves no delivery record.
         */
        $insideTransaction = DB::transactionLevel() > 0;

        foreach (collect($recipients)->filter()->unique(fn (User $recipient) => $recipient->id) as $recipient) {
            $eventChannels = collect($channels)
                ->map(fn ($channel) => strtoupper((string) $channel))
                ->unique()
                ->values();

            foreach ($eventChannels as $channel) {
                /*
                 * Respect the recipient's own notification preferences
                 * (Account Settings > Notification preferences). Defaults
                 * match that form exactly (system/email on) so nothing
                 * changes for a recipient who never touched them.
                 */
                $preferenceKey = strtolower($channel);
                $channelRequired = in_array($channel, $requiredChannels, true);
                $channelEnabled = $channelRequired || (bool) data_get(
                    $recipient->notification_preferences,
                    $preferenceKey,
                    true
                );

                if (! $channelEnabled) {
                    continue;
                }

                $address = match ($channel) {
                    'EMAIL' => $recipient->email,
                    default => (string) $recipient->id,
                };

                if ($deferEmailUntilCommit && $channel === 'EMAIL') {
                    // Persist all notification records before any irreversible
                    // mail attempt. Rollback discards this commit callback.
                    $delivery = NotificationDelivery::query()->create([
                        'notification_event_id' => $event->id,
                        'recipient_user_id' => $recipient->id,
                        'channel' => $channel,
                        'address_snapshot' => $address,
                        'attempt_no' => 1,
                        'attempted_at' => null,
                        'delivery_status' => 'PENDING',
                        'provider_response' => 'Waiting for the approval transaction to commit.',
                    ]);
                    DB::afterCommit(function () use ($delivery, $channel, $address, $message, $eventCode, $recipient, $source): void {
                        try {
                            [$status, $provider, $response] = $this->deliver($channel, $address, $message, $eventCode, $recipient, $source);
                            $delivery->update([
                                'attempted_at' => now(),
                                'delivery_status' => $status,
                                'provider' => $provider,
                                'provider_response' => $response,
                            ]);
                        } catch (Throwable $exception) {
                            // The approval is already committed. Leave its
                            // pending delivery truthful for technical review.
                            Log::error('Committed approval notification could not record its delivery outcome.', [
                                'notification_delivery_id' => $delivery->id,
                                'error' => $exception::class,
                            ]);
                        }
                    });
                    continue;
                }

                $attempt = function () use ($event, $recipient, $channel, $address, $message, $eventCode, $source): void {
                    [$status, $provider, $providerResponse] = $this->deliver(
                        $channel,
                        $address,
                        $message,
                        $eventCode,
                        $recipient,
                        $source
                    );

                    NotificationDelivery::query()->create([
                        'notification_event_id' => $event->id,
                        'recipient_user_id' => $recipient->id,
                        'channel' => $channel,
                        'address_snapshot' => $address,
                        'attempt_no' => 1,
                        'provider' => $provider,
                        'attempted_at' => now(),
                        'delivery_status' => $status,
                        'provider_response' => $providerResponse,
                    ]);
                };

                if ($channel !== 'EMAIL') {
                    $attempt();

                    continue;
                }

                DB::afterCommit(function () use ($attempt, $insideTransaction, $eventCode): void {
                    if (! $insideTransaction) {
                        $attempt();

                        return;
                    }

                    /*
                     * The business action is already committed here. A fault
                     * while recording the attempt is reported, never turned
                     * into an error for an action that actually succeeded.
                     */
                    try {
                        $attempt();
                    } catch (Throwable $exception) {
                        report($exception);

                        Log::warning('SPMU email delivery could not be recorded after commit', [
                            'event' => $eventCode,
                        ]);
                    }
                });
            }
        }

        Log::info("SPMU notification {$eventCode}: {$message}");

        return $event;
    }

    /**
     * @return array{string, ?string, string}
     */
    private function deliver(
        string $channel,
        ?string $address,
        string $message,
        string $eventCode,
        User $recipient,
        ?Model $source
    ): array {
        if ($channel === 'SYSTEM') {
            return [
                'SENT',
                'system',
                'Stored in the authenticated in-system notification record.',
            ];
        }

        if (blank($address)) {
            return [
                'FAILED',
                strtolower($channel),
                'Recipient address is not configured.',
            ];
        }

        if ($channel === 'EMAIL') {
            try {
                $email = $this->buildEmail(
                    $eventCode,
                    $message,
                    $recipient,
                    $source
                );

                Mail::html($email['html'], function (Message $mail) use ($address, $email): void {
                    $mail->to($address)->subject($email['subject']);
                });

                return [
                    'SENT',
                    (string) config('mail.default'),
                    'Accepted by the configured Laravel mail transport using the professional institutional email template.',
                ];
            } catch (Throwable $exception) {
                // Keep the transport error for diagnostics, without account names or secrets.
                $error = NotificationDelivery::sanitizeDiagnostic($exception->getMessage());

                Log::warning('SPMU email delivery failed', [
                    'event' => $eventCode,
                    'error' => $error,
                ]);

                return [
                    'FAILED',
                    (string) config('mail.default'),
                    $error !== '' ? $error : 'The mail transport reported an error without a message.',
                ];
            }
        }

        return [
            'FAILED',
            strtolower($channel),
            'Unsupported notification channel.',
        ];
    }

    /**
     * @return array{
     *     subject:string,
     *     html:string
     * }
     */
    private function buildEmail(
        string $eventCode,
        string $message,
        User $recipient,
        ?Model $source
    ): array {
        $profile = $this->eventProfile($eventCode);
        $data = $this->sourceData($eventCode, $recipient, $source);

        $reference = $data['reference'];

        $subject = '[CSPC SPMU] '.$profile['title'];
        if ($reference) {
            $subject .= ' | '.$reference;
        }

        $recipientName = e($recipient->full_name ?: 'Recipient');
        $title = e($profile['title']);
        $summary = nl2br(e(
            $this->emailSummary(
                $eventCode,
                $message,
                $recipient,
                $source
            )
        ));
        $referenceLine = $reference
            ? '<p style="margin:8px 0 0;font-size:13px;line-height:1.6;color:#486581;"><strong>Reference:</strong> '.e($reference).'</p>'
            : '';

        // The reference is already shown below the email heading. Do not
        // repeat that same value inside the details table.
        $detailsRows = '';
        foreach ($data['details'] as $label => $value) {
            if ($reference && trim((string) $value) === trim((string) $reference)) {
                continue;
            }

            $detailsRows .=
                '<tr>'
                .'<td style="padding:8px 0 8px 0;width:220px;vertical-align:top;font-size:14px;line-height:1.6;color:#486581;"><strong>'.e($label).'</strong></td>'
                .'<td style="padding:8px 0 8px 14px;vertical-align:top;font-size:14px;line-height:1.6;color:#243b53;">'.e($value).'</td>'
                .'</tr>';
        }

        $detailsHeading = $data['detailsHeading'];

        $detailsBlock = $detailsRows === ''
            ? ''
            : '
                <h2 style="margin:28px 0 10px;font-size:15px;line-height:1.4;color:#102a43;font-weight:700;">'.e($detailsHeading).'</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                    '.$detailsRows.'
                </table>
            ';

        // Keep the email readable for larger requests. The complete record
        // remains available through the authorized transaction link.
        $visibleItems = array_slice($data['items'], 0, 5);
        $additionalItemCount = max(0, count($data['items']) - count($visibleItems));
        $itemsRows = '';
        foreach ($visibleItems as $item) {
            $itemsRows .=
                '<tr>'
                .'<td style="padding:10px 8px 10px 0;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#243b53;">'.e($item['name']).'</td>'
                .'<td style="padding:10px 8px;text-align:right;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#243b53;">'.e($item['quantity']).'</td>'
                .'<td style="padding:10px 8px;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#486581;">'.e($item['unit'] ?: '—').'</td>'
                .'<td style="padding:10px 0 10px 8px;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#486581;">'.e($item['context'] ?: '—').'</td>'
                .'</tr>';
        }

        $moreItemsNote = $additionalItemCount > 0
            ? '<p style="font-size:13px;line-height:1.6;color:#486581;">And '.$additionalItemCount.' more item(s). View the full list in SPMU-ACPMP.</p>'
            : '';

        $itemsBlock = $itemsRows === ''
            ? ''
            : '
                <h2 style="margin:24px 0 10px;font-size:15px;line-height:1.4;color:#102a43;font-weight:700;">'.e($data['itemsHeading']).'</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th align="left" style="padding:9px 8px 9px 0;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">Item</th>
                            <th align="right" style="padding:9px 8px;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">'.e($data['quantityLabel']).'</th>
                            <th align="left" style="padding:9px 8px;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">Unit</th>
                            <th align="left" style="padding:9px 0 9px 8px;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">'.e($data['contextLabel']).'</th>
                        </tr>
                    </thead>
                    <tbody>
                        '.$itemsRows.'
                    </tbody>
                </table>
                '.$moreItemsNote.'
            ';

        // Each event summary already gives its applicable next instruction.
        // A generic second instruction paragraph repeated that same guidance.

        $actionBlock = '';
        if ($data['actionUrl'] && $data['actionLabel']) {
            $actionBlock = '
                <p style="margin:28px 0 0;font-size:14px;line-height:1.75;color:#243b53;">
                    <strong>View details:</strong>
                    <a href="'.e($data['actionUrl']).'" style="color:#1769e0;text-decoration:underline;font-weight:700;">'.e($data['actionLabel']).'</a>
                </p>
            ';
        }

        $footerTimestamp = now()
            ->timezone(config('app.timezone') ?: 'Asia/Manila')
            ->format('d F Y, g:i A');

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$this->escape($subject)}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#172033;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:760px;border-collapse:collapse;">
                    <tr>
                        <td style="padding:0 0 18px 0;border-bottom:3px solid #0b2854;">
                            <div style="font-size:25px;line-height:1.15;font-weight:800;color:#0b2854;">Camarines Sur Polytechnic Colleges</div>
                            <div style="margin-top:6px;font-size:14px;line-height:1.55;font-weight:700;color:#243b53;">
                                Supply and Property Management Unit
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 0 0 0;">
                            <div style="font-size:13px;line-height:1.5;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#486581;">
                                Official Notification
                            </div>

                            <h1 style="margin:10px 0 0;font-size:31px;line-height:1.25;font-weight:800;color:#102a43;">
                                {$title}
                            </h1>

                            {$referenceLine}

                            <p style="margin:28px 0 0;font-size:16px;line-height:1.8;color:#243b53;">
                                Dear {$recipientName},
                            </p>

                            <p style="margin:10px 0 0;font-size:16px;line-height:1.85;color:#243b53;">
                                {$summary}
                            </p>

                            {$detailsBlock}

                            {$itemsBlock}

                            {$actionBlock}

                            <p style="margin:34px 0 0;font-size:12px;line-height:1.65;color:#829ab1;">
                                Sent {$this->escape($footerTimestamp)}.
                            </p>

                            <p style="margin:28px 0 0;padding-top:18px;border-top:1px solid #d9e2ec;font-size:12px;line-height:1.75;color:#627d98;">
                                This is an automated notice from CSPC SPMU. Please do not reply to this email.
                                For assistance, contact the Supply and Property Management Unit through official institutional channels.
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

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    /**
     * @return array{
     *     title:string
     * }
     */
    private function eventProfile(string $eventCode): array
    {
        $title = match ($eventCode) {
            'BORROWER_REGISTRATION_SUBMITTED' => 'Borrower Registration Awaiting Review',
            'BORROWER_REGISTRATION_APPROVED' => 'Borrower Registration Approved',
            'BORROWER_REGISTRATION_REJECTED' => 'Borrower Registration Not Approved',
            'REQUEST_SUBMITTED' => 'Borrowing Request Submitted',
            'REQUEST_VERIFIED' => 'Request Ready for Head Review',
            'REQUEST_APPROVED' => 'Borrowing Request Approved',
            'REQUEST_RETURNED_FOR_REVISION' => 'Request Needs Revision',
            'REQUEST_REJECTED' => 'Borrowing Request Not Approved',
            'REQUEST_CANCELLED' => 'Borrowing Request Cancelled',
            'REQUEST_EXPIRED' => 'Borrowing Request Expired',
            'PICKUP_SCHEDULED' => 'Pickup Schedule Updated',
            'RETURN_DUE_TODAY' => 'Return Due Today',
            'RETURN_DUE_TOMORROW' => 'Return Due Tomorrow',
            'RETURN_SCHEDULE_ADJUSTED' => 'Return Schedule Updated',
            'EARLY_RETURN_REQUESTED' => 'Early Return Request Received',
            'PICKUP_EXPIRED' => 'Pickup Schedule Missed',
            'PICKUP_HELD_PREPARATION_ISSUE' => 'Pickup on Hold',
            'PICKUP_RESCHEDULE_REQUESTED' => 'Pickup Reschedule Requested',
            'PREPARATION_ISSUE_REPORTED' => 'Item Preparation Issue Reported',
            'PREPARATION_ISSUE_RESOLVED' => 'Item Preparation Review Completed',
            'PREPARATION_UNABLE_TO_FULFILL' => 'Approved Request Could Not Be Fulfilled',
            'ITEMS_RELEASED' => 'Borrowed Items Released',
            'LINEN_FOR_LAUNDRY' => 'Laundry Form Required',
            'LAUNDRY_USED_LINEN_RECEIVED' => 'Linen Return Received',
            'LAUNDRY_READY_FOR_PICKUP', 'LAUNDRY_PROCESSING_COMPLETED' => 'Laundry Processing Completed',
            'LAUNDRY_FORM_PENDING_SPMU_VERIFICATION' => 'Laundry Form Ready for Review',
            'LAUNDRY_INTERNAL_QUEUE' => 'Laundry Return Recorded',
            'LAUNDRY_FINAL_FORM_ARCHIVED' => 'Laundry Form Recorded',
            'RETURN_RECORDED' => 'Return Recorded',
            'RETURN_INSPECTED' => 'Return Inspection Recorded',
            'TRANSACTION_CLOSED' => 'Borrowing Transaction Completed',
            'OVERDUE', 'RETURN_OVERDUE', 'BORROWING_OVERDUE' => 'Return Overdue',
            'LATE_RETURN_FOR_HEAD_APPROVAL' => 'Late Return Ready for Review',
            'LATE_RETURN_NOTICE_ISSUED' => 'Late Return Notice',
            'LATE_RETURN_BILLING_STATEMENT_ISSUED' => 'Late Return Billing Statement',
            'ACCOUNTABILITY_OPENED', 'INCIDENT_RECORDED' => 'Property Accountability Case Opened',
            'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED' => 'Property Accountability Billing Recorded',
            'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' => 'Property Accountability — In-Person Processing Required',
            'PROPERTY_ACCOUNTABILITY_OFFICIAL_DISPOSITION_RECORDED' => 'Property Accountability Next Step Updated',
            'PROPERTY_ACCOUNTABILITY_COMPLIANCE_VERIFIED' => 'Required Action Verified',
            'PROPERTY_ACCOUNTABILITY_COMPLIANCE_NOT_ACCEPTED' => 'Required Action Needs Correction',
            'PROPERTY_ACCOUNTABILITY_PAYMENT_REQUIRED' => 'Property Accountability Payment Required',
            'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED' => 'Property Accountability Resolved',
            'LATE_RETURN_RESOLVED_WITHOUT_CHARGE' => 'Late Return Case Resolved — No Charge',
            'PAYMENT_RECORDED', 'RECEIPT_RECORDED' => 'Payment Record Received',
            'PAYMENT_VERIFIED', 'RECEIPT_VERIFIED' => 'Payment Confirmed',
            'EVIDENCE_VERIFIED' => 'Supporting Evidence Verified',
            'EVIDENCE_REJECTED' => 'Supporting Evidence Needs Replacement',
            'ADMINISTRATIVE_SANCTION_RECORDED' => 'Administrative Sanction Recorded',
            'BORROWING_SUSPENSION_LIFTED' => 'Borrowing Suspension Lifted',
            'ACCOUNT_ACCESS_DISABLED' => 'Account Access Disabled',
            default => $this->humanize($eventCode),
        };

        return ['title' => $title];
    }

    /**
     * @return array{
     *     reference:?string,
     *     details:array<string,string>,
     *     items:array<int,array{
     *         name:string,
     *         quantity:string,
     *         unit:string,
     *         context:string
     *     }>,
     *     quantityLabel:string,
     *     actionLabel:?string,
     *     actionUrl:?string,
     *     detailsHeading:string,
     *     itemsHeading:string,
     *     contextLabel:string
     * }
     */
    private function sourceData(
        string $eventCode,
        User $recipient,
        ?Model $source
    ): array {
        $data = [
            'reference' => null,
            'details' => [],
            'items' => [],
            'quantityLabel' => 'Quantity',
            'actionLabel' => null,
            'actionUrl' => null,
            'detailsHeading' => match ($eventCode) {
                'ADMINISTRATIVE_SANCTION_RECORDED' => 'Sanction Details',
                'BORROWING_OVERDUE', 'OVERDUE', 'RETURN_OVERDUE' => 'Return Details',
                'LATE_RETURN_NOTICE_ISSUED', 'LATE_RETURN_RESOLVED_WITHOUT_CHARGE' => 'Late Return Details',
                'RETURN_SCHEDULE_ADJUSTED' => 'Return Schedule Details',
                'LATE_RETURN_BILLING_STATEMENT_ISSUED' => 'Late Return Billing Details',
                'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED', 'PAYMENT_VERIFIED', 'RECEIPT_VERIFIED' => 'Billing Details',
                'ACCOUNTABILITY_OPENED', 'INCIDENT_RECORDED', 'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED', 'PROPERTY_ACCOUNTABILITY_OFFICIAL_DISPOSITION_RECORDED', 'PROPERTY_ACCOUNTABILITY_COMPLIANCE_NOT_ACCEPTED', 'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED' => 'Accountability Details',
                default => 'Details',
            },
            'itemsHeading' => match ($eventCode) {
                'REQUEST_APPROVED', 'PICKUP_SCHEDULED', 'PICKUP_EXPIRED', 'PICKUP_RESCHEDULE_REQUESTED' => 'Approved Items',
                'ITEMS_RELEASED' => 'Released Items',
                'RETURN_DUE_TODAY', 'RETURN_DUE_TOMORROW' => 'Items to Return',
                'RETURN_RECORDED', 'RETURN_INSPECTED', 'TRANSACTION_CLOSED' => 'Returned Items',
                'OVERDUE', 'RETURN_OVERDUE', 'BORROWING_OVERDUE' => 'Outstanding Items',
                default => 'Items',
            },
            'contextLabel' => 'Use / Context',
        ];

        if (! $source) {
            return $data;
        }

        if ($source instanceof BorrowerRegistration) {
            $source->loadMissing('user');
            $data['detailsHeading'] = 'Borrower Registration';
            $data['details'] = [
                'Applicant' => $source->user->full_name,
                'Registration Type' => $source->registration_type->label(),
                'Review Status' => ucfirst(strtolower($source->status->value)),
            ];
            $data['actionLabel'] = $eventCode === 'BORROWER_REGISTRATION_SUBMITTED' ? 'Review registrations' : 'Sign in';
            $data['actionUrl'] = $eventCode === 'BORROWER_REGISTRATION_SUBMITTED' ? route('registrations.index') : route('login');

            return $data;
        }

        if ($source instanceof BorrowingRequest) {
            $source->loadMissing([
                'borrower.organizationalUnit',
                'accountableUnit',
                'currentVersion.items.inventoryItem.unit',
                'currentVersion.approvalSteps',
                'custody',
            ]);

            $version = $source->currentVersion;

            $data['detailsHeading'] = 'Request Details';
            $data['reference'] = (string) $source->request_no;
            $data['details']['Request Number'] = (string) $source->request_no;

            if ($recipient->id !== $source->borrower_user_id && $source->borrower?->full_name) {
                $data['details']['Borrower'] = (string) $source->borrower->full_name;
            }

            if ($version?->purpose_event) {
                $data['details']['Event Details'] = (string) $version->purpose_event;
            }

            if ($version?->location) {
                $data['details']['Location'] = (string) $version->location;
            }

            $schedule = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');
            $approvedReturn = $version?->getAttribute('return_date') ?: $version?->getAttribute('return_due_at');
            $effectiveReturn = $eventCode === 'REQUEST_APPROVED'
                ? ($source->custody?->due_at ?: $approvedReturn)
                : $approvedReturn;

            if ($schedule) {
                $data['details']['Date Needed'] = $this->date($schedule);
            }

            if (
                $eventCode === 'REQUEST_APPROVED'
                && $source->custody?->original_due_at
                && $source->custody?->due_at
                && ! $source->custody->original_due_at->isSameDay($source->custody->due_at)
            ) {
                $data['details']['Original Approved Return Date'] = $this->date($source->custody->original_due_at);
                $data['details']['Effective Return Date'] = $this->date($source->custody->due_at);

                if ($source->custody->due_adjustment_reason) {
                    $data['details']['Schedule Adjustment'] = (string) $source->custody->due_adjustment_reason;
                }
            } elseif ($effectiveReturn) {
                $data['details']['Expected Return Date'] = $this->date($effectiveReturn);
            }

            if (
                in_array($eventCode, ['BORROWING_OVERDUE', 'OVERDUE', 'RETURN_OVERDUE'], true)
                && $source->due_at
            ) {
                $dueDay = Carbon::parse($source->due_at)->startOfDay();
                $daysOverdue = $source instanceof \App\Models\CustodyTransaction
                    ? app(LateReturnService::class)->chargeableLateDays($source, now()->startOfDay())
                    : $this->operationalCalendar->countOpenTransactionDays(
                        OperationalCalendarService::RETURN,
                        $dueDay,
                        now()->startOfDay()
                    );
                $data['details']['Current Overdue Days'] = (string) $daysOverdue;
                $data['details']['Borrowing Status'] = 'Restricted while the outstanding return remains unresolved';
            }

            $unit = $source->accountableUnit?->unit_name ?: $source->borrower?->organizationalUnit?->unit_name;
            if ($unit) {
                $data['details']['Office / College / Unit'] = (string) $unit;
            }

            if ($version?->requiresPermissionToConduct()) {
                $data['details']['Student Activity'] = 'Yes';
            }

            if ($eventCode === 'REQUEST_APPROVED') {
                if ($source->custody?->scheduled_release_at) {
                    $data['details']['Pickup Schedule'] = $this->dateTime($source->custody->scheduled_release_at);
                }

                if ($source->custody?->pickup_expires_at) {
                    $data['details']['Pickup Deadline'] = $this->dateTime($source->custody->pickup_expires_at);
                }
            }

            $includeRequestItems = in_array(
                $eventCode,
                ['REQUEST_SUBMITTED', 'REQUEST_VERIFIED', 'REQUEST_APPROVED'],
                true
            );

            if ($includeRequestItems) {
                foreach ($version?->items ?? collect() as $item) {
                    $useApproved = $eventCode === 'REQUEST_APPROVED' && $item->approved_quantity !== null;
                    $quantity = $useApproved ? $item->approved_quantity : $item->requested_quantity;

                    $data['items'][] = [
                        'name' => (string) ($item->description_snapshot ?: $item->inventoryItem?->unique_description ?: 'Item'),
                        'quantity' => $this->qty($quantity),
                        'unit' => (string) ($item->unit_snapshot ?: ''),
                        'context' => $this->humanize((string) ($item->use_location ?: '')),
                    ];
                }

                $data['quantityLabel'] = $eventCode === 'REQUEST_APPROVED'
                    ? 'Approved Quantity'
                    : 'Requested Quantity';
            }

            if ($this->recipientCanOpenRequest($recipient, $source)) {
                $data['actionLabel'] = $eventCode === 'REQUEST_RETURNED_FOR_REVISION'
                    && (int) $recipient->id === (int) $source->borrower_user_id
                    ? 'Review and revise the request'
                    : 'View the request';
                $data['actionUrl'] = route('requests.show', $source);
            } else {
                /*
                 * Some request events are also delivered to staff for audit or
                 * awareness after their workflow authority has already ended.
                 * Never place a role-forbidden request URL in the email; the
                 * in-system notification list remains a safe destination.
                 */
                $data['actionLabel'] = 'Open notifications';
                $data['actionUrl'] = route('notifications.index');
            }

            return $data;
        }

        if ($source instanceof CustodyTransaction) {
            $source->loadMissing([
                'borrower.organizationalUnit',
                'request.currentVersion',
                'lines.requestItem.inventoryItem.unit',
                'returns.lines.custodyLine.requestItem.inventoryItem.unit',
                'incidents',
                'laundryJob',
            ]);

            $version = $source->request?->currentVersion;

            $data['detailsHeading'] = 'Transaction Details';
            $data['reference'] = (string) $source->custody_no;
            $data['details']['Custody Number'] = (string) $source->custody_no;

            if ($source->request?->request_no) {
                $data['details']['Request Number'] = (string) $source->request->request_no;
            }

            if ($recipient->id !== $source->borrower_user_id && $source->borrower?->full_name) {
                $data['details']['Borrower'] = (string) $source->borrower->full_name;
            }

            if ($version?->purpose_event) {
                $data['details']['Event Details'] = (string) $version->purpose_event;
            }

            if ($version?->location) {
                $data['details']['Location'] = (string) $version->location;
            }

            $schedule = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');

            if ($schedule) {
                $data['details']['Date Needed'] = $this->date($schedule);
            }

            if ($eventCode === 'RETURN_SCHEDULE_ADJUSTED') {
                if ($source->original_due_at) {
                    $data['details']['Original Approved Return Date'] = $this->date($source->original_due_at);
                }

                if ($source->due_at) {
                    $data['details']['Effective Return Date'] = $this->date($source->due_at);
                }

                if ($source->due_adjustment_reason) {
                    $data['details']['Adjustment Reason'] = (string) $source->due_adjustment_reason;
                }
            } else {
                /*
                 * Once custody exists, its due_at is the authoritative
                 * operational deadline. The request version keeps the original
                 * approved date for audit, but borrower/AO/SPMU notifications
                 * must use the effective calendar-adjusted date.
                 */
                $return = $source->due_at
                    ?: $version?->getAttribute('return_date')
                    ?: $version?->getAttribute('return_due_at');

                if ($return) {
                    $data['details']['Expected Return Date'] = $this->date($return);
                }
            }

            if (in_array($eventCode, ['RETURN_RECORDED', 'RETURN_INSPECTED'], true)) {
                $latestReturnForDetails = $source->returns
                    ->sortByDesc(fn ($record) => (int) $record->id)
                    ->first();

                if ($latestReturnForDetails?->received_at) {
                    $data['details']['Return Date & Time'] = $this->dateTime($latestReturnForDetails->received_at);
                }

                $accountability = $source->activeAccountabilityIndicator();

                if ($accountability) {
                    $data['details']['Return Status'] = 'Under accountability review';
                    $data['details']['Accountability'] = $accountability['key'] === 'INCIDENT_OPEN'
                        ? 'Awaiting Head Decision'
                        : (string) $accountability['label'];
                    $data['details']['Next Step'] = 'Check My Obligations for the required action.';
                } elseif (strtoupper((string) $source->status) === 'CLOSED') {
                    $data['details']['Return Status'] = 'Completed';
                    $data['details']['Next Step'] = 'No further action is required for this transaction.';
                } else {
                    $data['details']['Return Status'] = $this->humanize((string) $source->status);
                    $data['details']['Next Step'] = 'Complete any remaining return requirement shown in the system.';
                }
            }

            if (in_array($eventCode, ['PICKUP_SCHEDULED', 'PICKUP_EXPIRED', 'PICKUP_RESCHEDULE_REQUESTED'], true) && $source->scheduled_release_at) {
                $data['details']['Pickup Schedule'] = $this->dateTime($source->scheduled_release_at);
            }

            if (in_array($eventCode, ['PICKUP_SCHEDULED', 'PICKUP_EXPIRED', 'PICKUP_RESCHEDULE_REQUESTED'], true) && $source->pickup_expires_at) {
                $data['details']['Pickup Deadline'] = $this->dateTime($source->pickup_expires_at);
            }

            if ($eventCode === 'ITEMS_RELEASED' && $source->released_at) {
                $data['details']['Release Date & Time'] = $this->dateTime($source->released_at);
            }

            $data['quantityLabel'] = match ($eventCode) {
                'PICKUP_SCHEDULED', 'PICKUP_EXPIRED', 'PICKUP_RESCHEDULE_REQUESTED' => 'Approved Quantity',
                'ITEMS_RELEASED', 'LINEN_FOR_LAUNDRY' => 'Issued Quantity',
                'RETURN_RECORDED', 'RETURN_INSPECTED' => 'Returned Quantity',
                'TRANSACTION_CLOSED' => 'Reconciled Quantity',
                'OVERDUE', 'RETURN_OVERDUE', 'BORROWING_OVERDUE' => 'Outstanding Quantity',
                default => 'Quantity',
            };

            if (in_array($eventCode, ['RETURN_RECORDED', 'RETURN_INSPECTED'], true)) {
                $data['contextLabel'] = 'Condition';
            }

            /*
             * A return notification must describe the quantities that were
             * just recorded, not the quantities still outstanding afterward.
             * This is especially important for mixed linen/non-linen custody:
             * after one complete branch is recorded, the remaining branch is
             * not the content of the RETURN_INSPECTED event.
             */
            if (in_array($eventCode, ['RETURN_RECORDED', 'RETURN_INSPECTED'], true)) {
                $latestReturn = $source->returns
                    ->sortByDesc(fn ($return) => (int) $return->id)
                    ->first();

                if ($latestReturn) {
                    $latestReturn->lines
                        ->groupBy('custody_line_id')
                        ->each(function ($returnLines) use (&$data): void {
                            $first = $returnLines->first();
                            $custodyLine = $first?->custodyLine;
                            $requestItem = $custodyLine?->requestItem;
                            $inventoryItem = $requestItem?->inventoryItem;
                            $quantity = (float) $returnLines->sum('quantity_received');

                            if ($quantity <= 0) {
                                return;
                            }

                            $conditions = $returnLines
                                ->groupBy(fn ($returnLine) => strtoupper((string) $returnLine->condition_code))
                                ->map(function ($conditionLines, $condition): string {
                                    $conditionQuantity = (float) $conditionLines->sum('quantity_received');

                                    return $this->humanize((string) $condition).': '.$this->qty($conditionQuantity);
                                })
                                ->values()
                                ->implode('; ');

                            $data['items'][] = [
                                'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Item'),
                                'quantity' => $this->qty($quantity),
                                'unit' => (string) ($requestItem?->unit_snapshot ?: ''),
                                'context' => $conditions ?: '—',
                            ];
                        });
                }
            }

            $includeCustodyItems = in_array(
                $eventCode,
                [
                    'PICKUP_SCHEDULED',
                    'ITEMS_RELEASED',
                    'LINEN_FOR_LAUNDRY',
                    'RETURN_DUE_TODAY',
                    'RETURN_DUE_TOMORROW',
                    'OVERDUE',
                    'RETURN_OVERDUE',
                    'BORROWING_OVERDUE',
                ],
                true
            );

            if ($includeCustodyItems) {
                foreach ($source->lines as $line) {
                    $requestItem = $line->requestItem;
                    $inventoryItem = $requestItem?->inventoryItem;

                    if ($eventCode === 'LINEN_FOR_LAUNDRY' && ! $inventoryItem?->laundry_required) {
                        continue;
                    }

                    $outstanding = max(
                        0,
                        (float) ($line->actual_released_quantity ?? 0) - (float) ($line->returned_quantity ?? 0)
                    );

                    $quantity = match ($eventCode) {
                        'PICKUP_SCHEDULED' => $line->approved_quantity,
                        'ITEMS_RELEASED', 'LINEN_FOR_LAUNDRY' => $line->actual_released_quantity,
                        'OVERDUE', 'RETURN_OVERDUE', 'BORROWING_OVERDUE' => $outstanding,
                        default => $line->actual_released_quantity ?: $line->approved_quantity,
                    };

                    if (
                        (float) $quantity <= 0
                        && in_array(
                            $eventCode,
                            ['ITEMS_RELEASED', 'LINEN_FOR_LAUNDRY', 'BORROWING_OVERDUE', 'OVERDUE', 'RETURN_OVERDUE'],
                            true
                        )
                    ) {
                        continue;
                    }

                    $data['items'][] = [
                        'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Item'),
                        'quantity' => $this->qty($quantity),
                        'unit' => (string) ($requestItem?->unit_snapshot ?: ''),
                        'context' => $this->humanize((string) ($requestItem?->use_location ?: '')),
                    ];
                }
            }

            if (
                $eventCode === 'LINEN_FOR_LAUNDRY'
                && $source->laundryJob
                && $recipient->access_classification === AccessClassification::SpmuOfficer
            ) {
                $data['actionLabel'] = 'Open the laundry request';
                $data['actionUrl'] = route('laundry.show', $source->laundryJob);
            } else {
                /*
                 * Laundry operations are Action-Officer-only. Borrowers and
                 * the SPMU Head still need the context, so send them to the
                 * shared custody detail instead of a route that will 403.
                 */
                $data['actionLabel'] = 'View the borrowing transaction';
                $data['actionUrl'] = route('custody.show', $source);
            }

            return $data;
        }

        if ($source instanceof Incident) {
            $source->loadMissing([
                'borrower',
                'custody.request',
                'lines.custodyLine.requestItem.inventoryItem.unit',
            ]);

            $custody = $source->custody;

            $data['reference'] = (string) $source->incident_no;
            $data['detailsHeading'] = 'Accountability Details';
            $data['itemsHeading'] = 'Affected Property';
            $data['quantityLabel'] = 'Affected Quantity';
            $data['contextLabel'] = 'Finding';
            if ($custody?->custody_no) {
                $data['details']['Custody Number'] = (string) $custody->custody_no;
            }

            if ($custody?->request?->request_no) {
                $data['details']['Request Number'] = (string) $custody->request->request_no;
            }

            if ($source->incident_type) {
                $data['details']['Case Type'] = $this->humanize((string) $source->incident_type);
            }

            $caseStatus = match ($eventCode) {
                'ACCOUNTABILITY_OPENED', 'INCIDENT_RECORDED' => 'Awaiting SPMU Review',
                'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' => 'Pending In-Person Resolution',
                'PROPERTY_ACCOUNTABILITY_OFFICIAL_DISPOSITION_RECORDED' => 'Next Step Updated',
                'PROPERTY_ACCOUNTABILITY_COMPLIANCE_VERIFIED' => 'For Final SPMU Review',
                'PROPERTY_ACCOUNTABILITY_COMPLIANCE_NOT_ACCEPTED' => 'Correction Required',
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED' => 'Resolved',
                default => null,
            };

            if ($caseStatus) {
                $data['details']['Case Status'] = $caseStatus;
            }

            if (in_array($eventCode, ['ACCOUNTABILITY_OPENED', 'INCIDENT_RECORDED'], true)) {
                $hasActiveRestriction = BorrowerRestriction::query()
                    ->where('incident_id', $source->id)
                    ->where('status', 'ACTIVE')
                    ->exists();

                if ($hasActiveRestriction) {
                    $data['details']['Borrowing Status'] = 'Restricted while this case is unresolved';
                }
            }

            foreach ($source->lines as $line) {
                $requestItem = $line->custodyLine?->requestItem;
                $inventoryItem = $requestItem?->inventoryItem;
                $finding = (string) ($line->observed_condition ?: $source->incident_type ?: 'Accountability finding');

                $data['items'][] = [
                    'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Property item'),
                    'quantity' => $this->qty($line->quantity),
                    'unit' => (string) ($requestItem?->unit_snapshot ?: $inventoryItem?->unit?->unit_name ?: ''),
                    'context' => $this->humanize($finding),
                ];
            }

            $data['actionLabel'] = $this->accountabilityActionLabel($recipient);
            $data['actionUrl'] = route('accountability.index');

            return $data;
        }

        if ($source instanceof OverdueCase) {
            $source->loadMissing([
                'borrower',
                'custody.request',
                'custody.lines.requestItem.inventoryItem.unit',
            ]);

            $custody = $source->custody;

            $data['reference'] = (string) ($custody?->custody_no ?: 'Late Return #'.$source->id);
            $data['detailsHeading'] = 'Late Return Details';
            $data['itemsHeading'] = 'Returned Property';
            $data['contextLabel'] = 'Assessment';

            if ($custody?->request?->request_no) {
                $data['details']['Request Number'] = (string) $custody->request->request_no;
            }

            if ($source->grace_expires_at) {
                $data['details']['Expected Return Date'] = $this->date($source->grace_expires_at);
            }

            if ($source->actual_return_date) {
                $data['details']['Actual Return Date'] = $this->date($source->actual_return_date);
            }

            /*
             * Before the physical return is recorded, late_days/accrued_amount
             * are only a running snapshot from today's date, not a finalized
             * figure - never surface them as "final" in a pre-return email.
             */
            if ($source->actual_return_date) {
                $data['details']['Final Late Days'] = (string) ((int) $source->late_days);

                if ($eventCode === 'LATE_RETURN_RESOLVED_WITHOUT_CHARGE') {
                    $data['details']['Assessment'] = 'Resolved — No Charge';
                } elseif ($eventCode === 'LATE_RETURN_NOTICE_ISSUED') {
                    $data['details']['Official Daily Late-Return Fee'] = $source->rate_snapshot !== null
                        ? 'PHP '.number_format((float) $source->rate_snapshot, 2).' per day'
                        : 'Not configured';
                    $data['details']['Billing'] = (float) $source->accrued_amount > 0
                        ? 'Total amount is stated separately in the Late Return Billing Statement'
                        : 'No separate billing required';
                } else {
                    $data['details']['Assessment'] = (float) $source->accrued_amount > 0
                        ? 'Billing Required — PHP '.number_format((float) $source->accrued_amount, 2)
                        : 'No Charge';
                }
            } elseif ($eventCode === 'LATE_RETURN_NOTICE_ISSUED') {
                $data['details']['Official Daily Late-Return Fee'] = $source->rate_snapshot !== null
                    ? 'PHP '.number_format((float) $source->rate_snapshot, 2).' per day'
                    : 'Not configured';
                $data['details']['Final Late Days & Total'] = 'Determined once the item is physically returned';
            }

            foreach ($custody?->lines ?? collect() as $line) {
                $requestItem = $line->requestItem;
                $inventoryItem = $requestItem?->inventoryItem;

                $data['items'][] = [
                    'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Item'),
                    'quantity' => $this->qty($line->actual_released_quantity),
                    'unit' => (string) ($requestItem?->unit_snapshot ?: ''),
                    'context' => 'Confirmed late return',
                ];
            }

            $data['quantityLabel'] = 'Returned Quantity';
            $data['actionLabel'] = $this->accountabilityActionLabel($recipient);
            $data['actionUrl'] = route('accountability.index');

            return $data;
        }

        if ($source instanceof BillingStatement) {
            $source->loadMissing([
                'borrower',
                'payments',
                'lines.penalty.overdueCase.custody.request',
                'lines.penalty.overdueCase.custody.lines.requestItem.inventoryItem.unit',
                'lines.incident.custody.request',
                'lines.incident.lines.custodyLine.requestItem.inventoryItem.unit',
            ]);

            $line = $source->lines->first();
            $lateCase = $line?->penalty?->overdueCase;
            $incident = $line?->incident;
            $custody = $lateCase?->custody ?: $incident?->custody;

            $data['reference'] = (string) $source->billing_no;
            if ($incident) {
                $data['detailsHeading'] = 'Accountability & Billing Details';
                $data['itemsHeading'] = 'Affected Property';
                $data['quantityLabel'] = 'Affected Quantity';
                $data['contextLabel'] = 'Finding';
                $data['details']['Case Reference'] = (string) $incident->incident_no;

                foreach ($incident->lines as $incidentLine) {
                    $requestItem = $incidentLine->custodyLine?->requestItem;
                    $inventoryItem = $requestItem?->inventoryItem;
                    $finding = (string) ($incidentLine->observed_condition ?: $incident->incident_type ?: 'Accountability finding');

                    $data['items'][] = [
                        'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Property item'),
                        'quantity' => $this->qty($incidentLine->quantity),
                        'unit' => (string) ($requestItem?->unit_snapshot ?: $inventoryItem?->unit?->unit_name ?: ''),
                        'context' => $this->humanize($finding),
                    ];
                }
            } elseif ($lateCase) {
                $data['detailsHeading'] = 'Late Return Billing Details';
                $data['itemsHeading'] = 'Returned Property';
                $data['quantityLabel'] = 'Returned Quantity';
                $data['contextLabel'] = 'Assessment';

                if ($lateCase->grace_expires_at) {
                    $data['details']['Expected Return Date'] = $this->date($lateCase->grace_expires_at);
                }

                if ($lateCase->actual_return_date) {
                    $data['details']['Actual Return Date'] = $this->date($lateCase->actual_return_date);
                }

                $data['details']['Final Late Days'] = (string) ((int) $lateCase->late_days);

                foreach ($custody?->lines ?? collect() as $custodyLine) {
                    $requestItem = $custodyLine->requestItem;
                    $inventoryItem = $requestItem?->inventoryItem;

                    $data['items'][] = [
                        'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Item'),
                        'quantity' => $this->qty($custodyLine->actual_released_quantity),
                        'unit' => (string) ($requestItem?->unit_snapshot ?: $inventoryItem?->unit?->unit_name ?: ''),
                        'context' => 'Late Return',
                    ];
                }
            }

            if ($custody?->custody_no) {
                $data['details']['Custody Number'] = (string) $custody->custody_no;
            }

            if ($custody?->request?->request_no) {
                $data['details']['Request Number'] = (string) $custody->request->request_no;
            }

            $data['details']['Amount Due'] = 'PHP '.number_format((float) $source->total_amount, 2);
            $data['details']['Billing Status'] = $this->humanize((string) $source->status);

            if ($source->due_at) {
                $data['details']['Payment Due Date'] = $this->date($source->due_at);
            }

            if (in_array($eventCode, ['PAYMENT_VERIFIED', 'RECEIPT_VERIFIED'], true)) {
                $verifiedPayment = $source->payments
                    ->where('status', 'VERIFIED')
                    ->sortByDesc(fn ($payment) => optional($payment->verified_at)->getTimestamp() ?: 0)
                    ->first();

                if ($verifiedPayment) {
                    if ($verifiedPayment->official_receipt_no) {
                        $data['details']['Official Receipt No.'] = (string) $verifiedPayment->official_receipt_no;
                    }

                    if ($verifiedPayment->receipt_date) {
                        $data['details']['Receipt Date'] = $this->date($verifiedPayment->receipt_date);
                    }

                    $data['details']['Amount Confirmed'] = 'PHP '.number_format((float) $verifiedPayment->amount, 2);
                }

                $confirmedTotal = (float) $source->payments
                    ->where('status', 'VERIFIED')
                    ->sum('amount');
                $remainingBalance = max(0, (float) $source->total_amount - $confirmedTotal);
                $data['details']['Remaining Balance'] = 'PHP '.number_format($remainingBalance, 2);
            }

            $data['actionLabel'] = $this->accountabilityActionLabel($recipient);
            $data['actionUrl'] = route('accountability.index');

            return $data;
        }

        if ($source instanceof LaundryJob) {
            $source->loadMissing([
                'custody.borrower',
                'custody.request.currentVersion',
                'lines.custodyLine.requestItem',
            ]);

            $custody = $source->custody;

            $data['reference'] = $custody?->custody_no;

            if ($custody?->request?->request_no) {
                $data['details']['Request Number'] = (string) $custody->request->request_no;
            }

            if ($custody?->borrower?->full_name) {
                $data['details']['Borrower'] = (string) $custody->borrower->full_name;
            }

            if ($custody?->request?->currentVersion?->purpose_event) {
                $data['details']['Event Details'] = (string) $custody->request->currentVersion->purpose_event;
            }

            if ($source->status) {
                $data['details']['Laundry Status'] = $this->humanize((string) $source->status);
            }

            if ($source->worker_received_at) {
                $data['details']['Received by Laundry'] = $this->dateTime($source->worker_received_at);
            }

            if ($source->worker_completed_at) {
                $data['details']['Laundry Completed'] = $this->dateTime($source->worker_completed_at);
            }

            foreach ($source->lines as $line) {
                $requestItem = $line->custodyLine?->requestItem;
                $quantity = $line->completed_quantity ?? $line->received_quantity ?? $line->issued_quantity;

                $data['items'][] = [
                    'name' => (string) ($requestItem?->description_snapshot ?: 'Linen item'),
                    'quantity' => $this->qty($quantity),
                    'unit' => (string) ($requestItem?->unit_snapshot ?: ''),
                    'context' => 'Laundry',
                ];
            }

            $data['quantityLabel'] = 'Laundry Quantity';

            $isBorrower = $custody && (int) $recipient->id === (int) $custody->borrower_user_id;
            $isActionOfficer = $recipient->access_classification === AccessClassification::SpmuOfficer;

            if ($isActionOfficer) {
                $data['actionLabel'] = 'View the laundry request';
                $data['actionUrl'] = route('laundry.show', $source);
            } elseif ($custody) {
                $data['actionLabel'] = 'View the borrowing transaction';
                $data['actionUrl'] = route('custody.show', $custody);
            }

            return $data;
        }

        if ($source instanceof Sanction) {
            $source->loadMissing([
                'borrower',
                'violation.custody.request',
            ]);

            $custody = $source->violation?->custody;
            $request = $custody?->request;
            $referenceParts = array_values(array_filter([
                $custody?->custody_no,
                $request?->request_no,
            ]));

            $data['reference'] = $referenceParts !== []
                ? implode(' / ', $referenceParts)
                : 'Sanction #'.$source->id;

            $data['details']['Reason'] = $this->sanctionReasonText($source);
            $data['details']['Offense'] = $this->offenseLabel((int) $source->offense_no);
            $data['details']['Sanction'] = (string) ($source->sanction_label ?: $this->humanize((string) $source->sanction_code));

            if (
                strtoupper((string) $source->sanction_code) === 'BORROWING_SUSPENSION'
                && $source->effective_to
            ) {
                $data['details']['Effective Until'] = $this->date($source->effective_to);
            }

            $data['actionLabel'] = $this->accountabilityActionLabel($recipient);
            $data['actionUrl'] = route('accountability.index');

            return $data;
        }

        foreach (['request_no', 'custody_no', 'incident_no', 'billing_no', 'statement_no'] as $field) {
            if ($source->getAttribute($field)) {
                $data['reference'] = (string) $source->getAttribute($field);
                $data['details']['Reference'] = $data['reference'];
                break;
            }
        }

        if ($source->getAttribute('status')) {
            $data['details']['Status'] = $this->humanize((string) $source->getAttribute('status'));
        }

        return $data;
    }

    /**
     * Email-only institutional wording.
     *
     * Existing SYSTEM messages remain concise and unchanged.
     */
    private function emailSummary(
        string $eventCode,
        string $fallbackMessage,
        User $recipient,
        ?Model $source
    ): string {
        $isBorrower = false;

        if ($source instanceof BorrowingRequest) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        } elseif ($source instanceof CustodyTransaction) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        } elseif ($source instanceof LaundryJob) {
            $isBorrower =
                $source->custody
                && (int) $recipient->id
                    ===
                    (int) $source->custody->borrower_user_id;
        } elseif ($source instanceof Incident) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        } elseif ($source instanceof OverdueCase) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        } elseif ($source instanceof BillingStatement) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        }

        $reason =
            $this->extractNotificationReason(
                $fallbackMessage
            );

        return match ($eventCode) {
            /*
             * -----------------------------------------------------
             * BORROWING REQUEST
             * -----------------------------------------------------
             */

            'REQUEST_SUBMITTED' =>
                $isBorrower
                    ? 'Your borrowing request has been received by SPMU. You will be notified of the decision or any required revision.'
                    : match ($recipient->access_classification) {
                        AccessClassification::SpmuOfficer => 'A new borrowing request requiring Gate Pass verification has been submitted. Please review the request.',
                        AccessClassification::SpmuHead => 'A new borrowing request has been submitted for your review and decision.',
                        default => 'A new borrowing request has been submitted for SPMU review.',
                    },

            'REQUEST_VERIFIED' =>
                "The SPMU Action Officer verified the request. It is now ready for the SPMU Head's decision.",

            'REQUEST_APPROVED' =>
                $isBorrower
                    ? 'Your borrowing request has been approved. Please follow the pickup schedule below and bring your Borrower Slip and other applicable forms.'
                    : 'The borrowing request has been approved. Prepare the approved items for the pickup schedule shown below.',

            'REQUEST_RETURNED_FOR_REVISION' =>
                'Your borrowing request has been returned for revision. Please address the SPMU remarks and resubmit the request.'
                .$this->reasonParagraph($reason),

            'REQUEST_REJECTED' =>
                'Your borrowing request was not approved. Please review the SPMU remarks below.'
                .$this->reasonParagraph($reason),

            'REQUEST_CANCELLED' =>
                $isBorrower
                    ? 'Your borrowing request has been cancelled. No further action is required. If you still need the items, please submit a new borrowing request.'
                    : 'The borrowing request has been cancelled. No further pickup or release action is required.',

            /*
             * -----------------------------------------------------
             * PICKUP / RELEASE
             * -----------------------------------------------------
             */

            'PICKUP_SCHEDULED' =>
                $isBorrower
                    ? 'Your pickup schedule has been updated. Please check your borrowing record for the schedule and required documents.'
                    : 'The pickup schedule has been updated. Prepare the approved items for the new schedule shown below.',

            'PICKUP_EXPIRED' =>
                $isBorrower
                    ? 'Your pickup schedule was not completed. SPMU will review your request and notify you of the next step. No action is required from you at this time.'
                    : 'The scheduled pickup was not completed. Review whether the approved release can still continue within the Items Needed From boundary or cancel the borrowing through the authorized SPMU action.',

            'PICKUP_HELD_PREPARATION_ISSUE' =>
                $isBorrower
                    ? 'Your scheduled pickup could not proceed because SPMU is reviewing an item preparation issue. No action is required from you while the review is ongoing.'
                    : 'The approved release is on hold because an item preparation issue was reported. Review the affected item before release continues.',

            'PICKUP_RESCHEDULE_REQUESTED' =>
                $isBorrower
                    ? 'A pickup reschedule request was recorded for this transaction. SPMU will notify you if a new pickup schedule is assigned.'
                    : 'A pickup reschedule request was recorded. Review the transaction and set the next allowed pickup schedule if applicable.',

            'PREPARATION_ISSUE_REPORTED' =>
                $isBorrower
                    ? 'SPMU found an item preparation issue while preparing your approved request. No action is required from you while SPMU reviews it.'
                    : 'An Action Officer reported an item preparation issue. Review the affected item and determine whether the approved request can still be fulfilled.',

            'PREPARATION_ISSUE_RESOLVED' =>
                $isBorrower
                    ? 'SPMU completed the item preparation review. The Action Officer will recheck the item before release continues.'
                    : 'The item preparation review is complete. Recheck the affected item before confirming that all approved items are prepared.',

            'PREPARATION_UNABLE_TO_FULFILL' =>
                $isBorrower
                    ? 'SPMU could not prepare all approved items, so the borrowing request was cancelled before release. No items were issued. If you still need the items, submit a new request.'
                    : 'The approved request was cancelled before release because SPMU could not prepare the full approved quantity.',

            'ITEMS_RELEASED' =>
                $isBorrower
                    ? 'Your borrowed items have been released to you. Please return them on or before the Expected Return Date shown below.'
                    : 'The listed items have been released to the borrower.',

            'RETURN_DUE_TOMORROW' =>
                'Your borrowed items are due for return tomorrow. Please return them to SPMU on or before the return date in your borrowing record.',

            'RETURN_DUE_TODAY' =>
                'Your borrowed items are due for return today. Please return them to SPMU during the designated return hours.',

            'RETURN_SCHEDULE_ADJUSTED' =>
                $isBorrower
                    ? 'Your return date was adjusted because SPMU is unavailable on the original return date. Use the updated return date shown below. This change will not be treated as a late return.'
                    : 'The return date for this transaction was adjusted because SPMU is unavailable on the original return date.',

            /*
             * -----------------------------------------------------
             * RETURN
             * -----------------------------------------------------
             */

            'RETURN_RECORDED' =>
                $this->returnRecordedSummary(
                    $source
                ),

            'RETURN_INSPECTED' =>
                $this->returnInspectedSummary(
                    $source
                ),

            'TRANSACTION_CLOSED' =>
                'Your borrowing transaction has been completed. No further action is required for this transaction.',

            'EARLY_RETURN_REQUESTED' =>
                $isBorrower
                    ? 'Your early return request has been received. Bring the items to SPMU at the proposed return schedule. The return will be recorded when the items are received and inspected.'
                    : 'An early return was requested. Review the proposed schedule and receive the items through the normal return process.',

            /*
             * -----------------------------------------------------
             * OVERDUE
             * -----------------------------------------------------
             */

            'OVERDUE',
            'RETURN_OVERDUE',
            'BORROWING_OVERDUE' =>
                $isBorrower
                    ? 'Your borrowed items are overdue. Please return the outstanding items to SPMU as soon as possible.'
                    : 'One or more borrowed items are overdue. Please follow up on the outstanding return.',

            'LATE_RETURN_NOTICE_ISSUED' =>
                $isBorrower
                    ? 'A Late Return Notice is available in My Obligations. Any payable amount will be specified in a separate Billing Statement, if issued.'
                    : 'The Late Return Notice is now available for this transaction.',

            'LATE_RETURN_BILLING_STATEMENT_ISSUED' =>
                $isBorrower
                    ? 'A Late Return Billing Statement has been issued. Please review the amount in My Obligations, settle it through the CSPC Cashier, and present the official receipt to SPMU.'
                    : 'A Late Return Billing Statement has been issued and is ready for payment processing.',

            'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED' =>
                $isBorrower
                    ? 'A Billing Statement has been issued for this case. Please review the amount in My Obligations, settle it through the CSPC Cashier, and present the official receipt to SPMU.'
                    : 'A billing statement has been recorded for this property accountability case.',

            'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' =>
                $isBorrower
                    ? 'The SPMU Head/Admin has confirmed the property finding. Please visit SPMU to complete the required in-person resolution. The related borrowing restriction remains in effect until this case is resolved.'
                    : 'The SPMU Head/Admin confirmed this property accountability case. Review the case for the required next step.',

            'PROPERTY_ACCOUNTABILITY_OFFICIAL_DISPOSITION_RECORDED' =>
                $isBorrower
                    ? 'SPMU has recorded the approved accountability requirement following in-person processing. Check My Obligations for the applicable next step.'
                    : 'SPMU recorded the approved accountability requirement following in-person processing.',

            'PROPERTY_ACCOUNTABILITY_COMPLIANCE_NOT_ACCEPTED' =>
                $isBorrower
                    ? 'The SPMU Action Officer did not accept the presented requirement for this property accountability case. Review the remarks below and present the corrected requirement.'
                    : 'A compliance verification for this property accountability case was not accepted.',

            'LATE_RETURN_RESOLVED_WITHOUT_CHARGE' =>
                'Your late-return case has been resolved without a charge. The restriction linked to this case has been lifted. Any separate active restriction remains in effect.',

            'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED' =>
                $isBorrower
                    ? 'Your property accountability case has been resolved. The restriction linked to this case has been lifted; any separate active restriction remains in effect.'
                    : 'This property accountability case has been resolved. The affected property, finding, and final status are shown below.',

            /*
             * -----------------------------------------------------
             * LAUNDRY
             * -----------------------------------------------------
             */

            'LINEN_FOR_LAUNDRY' =>
                $isBorrower
                    ? 'Your linen items have been released. On return, bring the linen and the same Laundry Form to the Laundry Area first.'
                    : 'Linen items have been released. On return, Laundry Personnel will complete the Laundry Form before SPMU records the linen findings.',

            'LAUNDRY_USED_LINEN_RECEIVED' =>
                $isBorrower
                    ? 'Laundry Personnel received the returned linen. Your linen return is complete unless SPMU contacts you about another requirement.'
                    : 'Laundry Personnel received the returned linen. Internal laundry processing can continue.',

            'LAUNDRY_PROCESSING_COMPLETED',
            'LAUNDRY_READY_FOR_PICKUP' =>
                'Laundry processing is complete for the serviceable linen. No further borrower return step is required.',

            'LAUNDRY_FORM_PENDING_SPMU_VERIFICATION' =>
                'The completed Laundry Form is ready for SPMU review.',

            'LAUNDRY_FORM_VERIFIED',
            'LAUNDRY_COMPLETED' =>
                'SPMU has completed the required Laundry Form verification and final linen processing requirements for this transaction.',

            /*
             * -----------------------------------------------------
             * SUPPORTING EVIDENCE
             * -----------------------------------------------------
             */

            'EVIDENCE_VERIFIED' =>
                'The supporting evidence submitted for this transaction has been reviewed and verified by SPMU.',

            'EVIDENCE_REJECTED' =>
                'The submitted document or evidence could not be accepted. Review the reason below and submit the correct replacement.'
                .$this->reasonParagraph($reason),

            /*
             * -----------------------------------------------------
             * INCIDENT / ACCOUNTABILITY
             * -----------------------------------------------------
             */

            'INCIDENT_RECORDED',
            'ACCOUNTABILITY_OPENED' =>
                $isBorrower
                    ? 'A property accountability case has been recorded following your return inspection. The SPMU Head/Admin will review the finding. Please see My Obligations for details.'
                    : 'A property accountability case has been opened and is waiting for SPMU Head/Admin review.',

            'ACCOUNTABILITY_RESOLVED' =>
                'The recorded property accountability concern for this transaction has been reviewed and resolved in accordance with the applicable SPMU process.',

            'ADMINISTRATIVE_SANCTION_RECORDED' =>
                'An administrative sanction has been recorded. The reason and sanction are indicated below. Any applicable official notice is available in My Obligations.',

            /*
             * -----------------------------------------------------
             * PAYMENT / SETTLEMENT EVIDENCE
             * -----------------------------------------------------
             */

            'PAYMENT_RECORDED',
            'RECEIPT_RECORDED' =>
                'SPMU received your payment record. You will be notified after it is confirmed.',

            'PAYMENT_VERIFIED',
            'RECEIPT_VERIFIED' =>
                $isBorrower
                    ? 'Your Cashier payment has been confirmed. Please see the payment details and remaining balance, if any, below.'
                    : 'The payment record has been confirmed. The updated details are shown below.',

            'REQUEST_EXPIRED' =>
                $isBorrower
                    ? 'This borrowing request has expired. If you still need the items, submit a new request with the required dates.'
                    : 'This borrowing request has expired and no further action is required.',

            'LATE_RETURN_FOR_HEAD_APPROVAL' =>
                'A late-return assessment is ready for SPMU Head/Admin review.',

            'LAUNDRY_INTERNAL_QUEUE' =>
                'The accomplished Laundry Form was recorded. Continue the normal internal laundry process if another step remains.',

            'LAUNDRY_FINAL_FORM_ARCHIVED' =>
                'The final Laundry Form was recorded for this transaction.',

            'PROPERTY_ACCOUNTABILITY_COMPLIANCE_VERIFIED' =>
                $isBorrower
                    ? 'SPMU verified the required action for this property accountability case. The case is now waiting for final SPMU review.'
                    : 'The required action was verified. The case is ready for final SPMU review.',

            'PROPERTY_ACCOUNTABILITY_PAYMENT_REQUIRED' =>
                $isBorrower
                    ? 'Payment is required for this property accountability case. Please review the official Billing Statement under My Obligations.'
                    : 'Payment is now required for this property accountability case.',

            'BORROWING_SUSPENSION_LIFTED' =>
                'Your borrowing suspension has ended. Check My Obligations only if another active requirement remains.',

            'ACCOUNT_ACCESS_DISABLED' =>
                'Your account access has been disabled. Contact the SPMU Admin if you need assistance.',

            /*
             * -----------------------------------------------------
             * GENERIC FALLBACK
             * -----------------------------------------------------
             */

            default =>
                $this->cleanFallbackEmailMessage(
                    $fallbackMessage
                ),
        };
    }

    /**
     * Professional wording for a recorded physical return.
     */
    private function returnRecordedSummary(
        ?Model $source
    ): string {
        if (
            $source instanceof CustodyTransaction
            && strtoupper((string) $source->status)
                === 'CLOSED'
        ) {
            return 'SPMU has received and accepted all returned items. This borrowing transaction is now completed.';
        }

        return 'SPMU has received and inspected the returned items. Any remaining requirement will stay open until it is completed.';
    }

    /**
     * Professional wording for physical inspection.
     */
    private function returnInspectedSummary(
        ?Model $source
    ): string {
        if ($source instanceof CustodyTransaction) {
            if (strtoupper((string) $source->status) === 'CLOSED') {
                return 'SPMU has completed the return inspection. All borrowed items have been accounted for, and the transaction is complete.';
            }

            if ($source->activeAccountabilityIndicator()) {
                return 'SPMU has completed your return inspection. A finding remains under review, so this borrowing transaction is not yet complete.';
            }
        }

        return 'SPMU has completed the return inspection. The transaction is not yet complete because another return requirement is still open.';
    }

    /**
     * Extract an existing Reason: value from the concise operational
     * message so reviewer remarks are preserved in the email.
     */
    private function extractNotificationReason(
        string $message
    ): ?string {
        if (
            preg_match(
                '/\bReason:\s*(.+)$/isu',
                trim($message),
                $matches
            )
        ) {
            $reason =
                trim(
                    (string)
                    ($matches[1] ?? '')
                );

            return $reason !== ''
                ? $reason
                : null;
        }

        return null;
    }

    /**
     * Add reviewer remarks without exposing system-style wording.
     */
    private function reasonParagraph(
        ?string $reason
    ): string {
        if (! $reason) {
            return '';
        }

        return "\n\nSPMU review remarks: "
            .$reason;
    }

    /**
     * Improve unknown/future event wording without changing the
     * underlying notification event or audit record.
     */
    private function cleanFallbackEmailMessage(
        string $message
    ): string {
        $clean =
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $message
                )
                ?? $message
            );

        if ($clean === '') {
            return 'An update has been recorded for this SPMU-ACPMP transaction. Please review the transaction information below for the applicable details.';
        }

        return $clean;
    }
    /**
     * Borrower-facing reason for an administrative sanction. Property
     * findings include the affected item when the linked incident is known.
     */
    private function accountabilityActionLabel(User $recipient): string
    {
        return $recipient->access_classification === AccessClassification::BorrowerOnly
            ? 'View My Obligations'
            : 'Open Accountability';
    }

    /**
     * Email action links must obey the same role/state boundary as the request
     * screen itself. A notification can outlive the recipient's approval step,
     * so merely knowing about a request is not authority to open it.
     */
    private function recipientCanOpenRequest(User $recipient, BorrowingRequest $request): bool
    {
        if ((int) $request->borrower_user_id === (int) $recipient->id) {
            return true;
        }

        $request->loadMissing('currentVersion.approvalSteps');

        if ($recipient->access_classification === AccessClassification::SpmuOfficer) {
            if ($request->final_approved_at !== null) {
                return true;
            }

            if ($request->status !== RequestStatus::UnderSpmu) {
                return false;
            }

            $steps = $request->currentVersion?->approvalSteps ?? collect();
            $canVerify = $steps->contains(
                fn ($step) => (int) $step->sequence_no === 1
                    && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
            );
            $canDecideAsDelegate = $recipient->activeDelegationFor('SPMU') !== null
                && $steps->contains(
                    fn ($step) => (int) $step->sequence_no === 2
                        && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
                );

            return $canVerify || $canDecideAsDelegate;
        }

        if ($recipient->access_classification === AccessClassification::SpmuHead) {
            $steps = $request->currentVersion?->approvalSteps ?? collect();

            if ($request->status !== RequestStatus::UnderSpmu) {
                return $request->final_approved_at !== null
                    || $steps->contains(fn ($step) => (string) $step->stage_code === 'SPMU');
            }

            return $steps->contains(
                fn ($step) => (int) $step->sequence_no === 2
                    && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
            );
        }

        return false;
    }

    private function sanctionReasonText(Sanction $sanction): string
    {
        $sanction->loadMissing('violation');

        $details = is_array($sanction->violation?->details_json)
            ? $sanction->violation->details_json
            : [];
        $reasons = collect(is_array($details['reasons'] ?? null) ? $details['reasons'] : []);
        $parts = collect();

        if ($reasons->contains(fn ($reason) => strtoupper((string) $reason) === 'LATE_RETURN')) {
            $parts->push('Late Return');
        }

        $incidentIds = array_values(array_filter(
            is_array($details['incident_ids'] ?? null) ? $details['incident_ids'] : [],
            fn ($id) => is_numeric($id)
        ));

        if ($incidentIds !== []) {
            $incidents = Incident::query()
                ->with('lines.custodyLine.requestItem')
                ->whereIn('id', $incidentIds)
                ->get();

            foreach ($incidents as $incident) {
                foreach ($incident->lines as $line) {
                    $finding = $this->humanize((string) ($line->observed_condition ?: $incident->incident_type ?: 'Property Accountability'));
                    $item = trim((string) ($line->custodyLine?->requestItem?->description_snapshot ?? ''));
                    $quantity = (float) ($line->quantity ?? 0);

                    $text = $finding;
                    if ($item !== '') {
                        $text .= ' — '.$item;
                    }
                    if ($quantity > 1) {
                        $text .= ' ('.$this->qty($quantity).')';
                    }

                    $parts->push($text);
                }
            }
        }

        if ($parts->isEmpty()) {
            $reasons
                ->map(fn ($reason) => $this->humanize((string) $reason))
                ->filter()
                ->each(fn ($reason) => $parts->push($reason));
        }

        return $parts->filter()->unique()->implode('; ')
            ?: 'Confirmed borrowing accountability offense';
    }

    private function offenseLabel(int $offenseNo): string
    {
        return match ($offenseNo) {
            1 => '1st Offense',
            2 => '2nd Offense',
            3 => '3rd Offense',
            default => $offenseNo.'th Offense',
        };
    }

    private function date(mixed $value): string
    {
        return Carbon::parse(
            $value,
            config('app.timezone') ?: 'Asia/Manila'
        )->format('d F Y');
    }

    private function dateTime(mixed $value): string
    {
        return Carbon::parse(
            $value,
            config('app.timezone') ?: 'Asia/Manila'
        )->format('d F Y, g:i A');
    }

    private function qty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = number_format((float) $value, 3, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function humanize(string $value): string
    {
        return str($value)
            ->replace(['_', '-'], ' ')
            ->lower()
            ->title()
            ->toString();
    }

    private function escape(mixed $value): string
    {
        return e((string) $value);
    }
}
