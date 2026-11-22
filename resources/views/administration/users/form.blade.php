@extends('layouts.app', ['title' => $user->exists ? ($isExternalAccount ? 'Edit external borrower account' : 'Edit institutional account') : 'Register a CSPC account'])
@section('content')
{{-- This is the User Account form for SPMU Admin. --}}
@php
    $isSelfProtected = auth()->check() && auth()->id() === $user->id;
    $selectedUnitId = old('organizational_unit_id', $user->organizational_unit_id);
    $newUnitName = old('new_organizational_unit_name', '');
    $addingNewUnit = filled($newUnitName) || old('organizational_unit_id') === '__NEW__';
    $historicalPrimaryUnit = $historicalPrimaryUnit ?? null;
    $historicalAdditionalUnits = $historicalAdditionalUnits ?? collect();
    // The SPMU Admin may remove an unused pending registration and resend its OTP.
    // The controller rechecks record/session preservation before deleting the account.
    $canMaintainAccount = $user->exists && Gate::allows('deletePendingRegistration', $user);
    $isPendingUnverified = $canMaintainAccount && $user->exists && !$user->hasVerifiedInstitutionalEmail();
    $canDeletePending = $isPendingUnverified && $pendingRegistrationDeletionBlocker === null;
    $selectedAdditionalUnitIds = collect(old('additional_organizational_unit_ids', $additionalUnitIds ?? []))
        ->map(fn ($id) => (int) $id)->unique()->values();
@endphp

<section class="page-heading ictu-form-page-heading">
    <div>
        <p class="eyebrow">Account administration</p>
        <h1>{{ $user->exists ? ($isExternalAccount ? 'Edit external borrower account' : 'Edit institutional account') : 'Register a CSPC account' }}</h1>
        @if($isExternalAccount)
            <p>Maintain the external borrower's account and contact details. SAO-reviewed registration details are retained. Email verification is required before sign-in.</p>
        @else
            <p>Register eligible employees, faculty, and staff. Institutional email verification is required before sign-in, and the selected classification controls borrowing eligibility and portal access.</p>
        @endif
    </div>
</section>

<section class="content-area ictu-user-form-area">
    <form method="post" action="{{ $user->exists ? route('administration.users.update', $user) : route('administration.users.store') }}" class="ictu-user-form">
        @csrf
        @if($user->exists) @method('PUT') @endif

        <section class="ictu-form-card" aria-labelledby="ictu-identity-heading">
            <header class="ictu-form-section-header ictu-identity-section-header">
                <div class="ictu-identity-heading-row">
                    <h2 id="ictu-identity-heading">Account Identity</h2>
                    @if($user->exists && !$user->hasVerifiedInstitutionalEmail())
                        <span class="ictu-email-verification-status">Pending OTP verification</span>
                    @endif
                </div>
                <p>{{ $user->exists ? 'Saved official account details are loaded automatically.' : 'Enter the employee\'s official CSPC account details.' }}</p>
            </header>
            <div class="ictu-field-grid ictu-field-grid-two">
                @unless($isExternalAccount)
                <label class="ictu-field" for="ictu-employee-number">
                    <span>Employee number</span>
                    <input id="ictu-employee-number" name="employee_no" value="{{ old('employee_no', $user->employee_no) }}" placeholder="Enter employee number" autocomplete="off" required>
                    @error('employee_no') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                @endunless
                <label class="ictu-field" for="ictu-full-name">
                    <span>Full name</span>
                    <input id="ictu-full-name" name="full_name" value="{{ old('full_name', $user->full_name) }}" placeholder="Enter full name" autocomplete="name" required>
                    @error('full_name') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                @unless($isExternalAccount)
                <label class="ictu-field" for="ictu-designation">
                    <span>Designation</span>
                    <input id="ictu-designation" name="designation" value="{{ old('designation', $user->designation) }}" placeholder="Enter official designation / position" autocomplete="organization-title" required>
                    @error('designation') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                @endunless
                <div class="ictu-field ictu-email-field">
                    <div class="ictu-email-label-row">
                        <label for="ictu-official-email">{{ $isExternalAccount ? 'Email' : 'Official CSPC email' }}</label>
                        @if($canMaintainAccount && $user->exists && !$user->hasVerifiedInstitutionalEmail())
                            <button type="submit" form="ictu-resend-email-verification-form" class="ictu-email-verification-link" title="Send a new 6-digit OTP to the currently saved email address: {{ $user->email }}">
                                <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path d="M3 7l9 6 9-6"></path>
                                </svg>
                                <span>Send OTP</span>
                            </button>
                        @endif
                    </div>
                    <input id="ictu-official-email" type="email" name="email" value="{{ old('email', $user->exists ? $user->email : '') }}"
                           placeholder="{{ $isExternalAccount ? 'Enter email address' : 'Enter official CSPC email' }}" autocomplete="off" autocapitalize="none" spellcheck="false"
                           data-lpignore="true" data-1p-ignore="true"
                           @if(!$user->exists) readonly onfocus="this.removeAttribute('readonly')" onpointerdown="this.removeAttribute('readonly')" @endif required>
                    @error('email') <small class="field-error">{{ $message }}</small> @enderror
                    @if(!$user->exists)
                        <small class="field-note">A 6-digit OTP is sent automatically after saving. Sign-in remains blocked until the user verifies the registered CSPC email.</small>
                    @elseif($user->hasVerifiedInstitutionalEmail())
                        <small class="field-note">Email verified {{ $user->email_verified_at?->format('M j, Y g:i A') }}. Changing the email requires a new OTP verification.</small>
                    @endif
                </div>
            </div>
        </section>

        @if($isExternalAccount)
        <section class="ictu-form-card" aria-labelledby="ictu-external-registration-heading">
            <header class="ictu-form-section-header">
                <h2 id="ictu-external-registration-heading">External Registration</h2>
                <p>Saved registration details are read-only. Account edits do not approve a registration or replace its evidence.</p>
            </header>
            <div class="ictu-field-grid ictu-field-grid-two">
                <label class="ictu-field" for="ictu-reviewed-company">
                    <span>Company / Agency / Institution</span>
                    <input id="ictu-reviewed-company" value="{{ $externalRegistration->company_name }}" title="{{ $externalRegistration->company_name }}" readonly>
                </label>
                <label class="ictu-field" for="ictu-registration-status">
                    <span>SAO registration status</span>
                    <input id="ictu-registration-status" value="{{ $externalRegistration->status->value }}" readonly>
                </label>
                <label class="ictu-field" for="ictu-registration-type">
                    <span>Account type</span>
                    <input id="ictu-registration-type" value="External Borrower" readonly>
                </label>
                <label class="ictu-field" for="ictu-registration-id">
                    <span>Saved Valid ID reference</span>
                    <input id="ictu-registration-id" value="{{ $externalRegistration->valid_id_file_id ? 'Recorded' : 'No recorded file' }}" readonly>
                </label>
            </div>
        </section>
        @endif

        @unless($isExternalAccount)
        <section class="ictu-form-card" aria-labelledby="ictu-organization-heading">
            <header class="ictu-form-section-header">
                <h2 id="ictu-organization-heading">Organizational Assignment</h2>
            </header>
            <div class="ictu-field-grid ictu-field-grid-two">
                <label class="ictu-field" for="ictu-division">
                    <span>Division</span>
                    <select name="division_code" id="ictu-division" @required(!$historicalPrimaryUnit)>
                        <option value="">Select Division</option>
                        @foreach($divisionOptions as $code => $label)
                            <option value="{{ $code }}" @selected(old('division_code', $selectedDivisionCode) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('division_code') <small class="field-error">{{ $message }}</small> @enderror
                </label>

                <label class="ictu-field" for="ictu-organizational-unit">
                    <span>Office / College / Unit</span>
                    <select name="organizational_unit_id" id="ictu-organizational-unit" required>
                        <option value="">Select Office / College / Unit</option>
                        @if($historicalPrimaryUnit)
                            <option value="{{ $historicalPrimaryUnit->id }}" data-division="{{ $historicalPrimaryUnit->divisionCode() }}"
                                    @selected(!$addingNewUnit && (string) $selectedUnitId === (string) $historicalPrimaryUnit->id)>
                                {{ $historicalPrimaryUnit->unit_name }} — Historical / inactive assignment
                            </option>
                        @endif
                        @foreach($units as $unit)
@php $unitDivision = $unit->divisionCode(); @endphp
                            @if(!$unitDivision) @continue @endif
                            <option value="{{ $unit->id }}" data-division="{{ $unitDivision }}"
                                    @selected(!$addingNewUnit && (string) $selectedUnitId === (string) $unit->id)>{{ $unit->unit_name }}</option>
                        @endforeach
                        <option value="__NEW__" data-new-unit @selected($addingNewUnit)>Other / Not listed</option>
                    </select>
                    @error('organizational_unit_id') <small class="field-error">{{ $message }}</small> @enderror
                    @if($historicalPrimaryUnit)
                        <small class="field-note">Historical / inactive assignment retained. Select an active unit to intentionally reassign this account.</small>
                    @endif
                </label>

                <div class="ictu-field ictu-new-unit-field" id="ictu-new-unit-field" @if(!$addingNewUnit) hidden @endif>
                    <label for="ictu-new-unit-name">Add Office / College / Unit</label>
                    <input type="text" name="new_organizational_unit_name" id="ictu-new-unit-name" value="{{ $newUnitName }}" maxlength="255" placeholder="Enter the official Office / College / Unit name">
                    <small class="field-note">New units are created under the selected Division (Borrower classification only).</small>
                    @error('new_organizational_unit_name') <small class="field-error">{{ $message }}</small> @enderror
                </div>

                <label class="ictu-field" for="ictu-personnel-type">
                    <span>Personnel Type</span>
                    <select name="employment_type" id="ictu-personnel-type" required>
                        <option value="">Select personnel type</option>
                        @foreach($employmentTypes as $type)
@php
                                $personnelTypeLabel = match ($type->value) {
                                    'FACULTY' => 'Faculty', 'EMPLOYEE' => 'Employee', 'STAFF' => 'Staff', default => $type->value,
                                };
                            @endphp
                            <option value="{{ $type->value }}" @selected(old('employment_type', $user->employment_type?->value) === $type->value)>{{ $personnelTypeLabel }}</option>
                        @endforeach
                    </select>
                    @error('employment_type') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="ictu-field" for="ictu-employment-status">
                    <span>Employment Status</span>
                    <select name="employment_status" id="ictu-employment-status" required>
                        <option value="">Select employment status</option>
                        <option value="FULL_TIME" @selected(old('employment_status', $user->employment_status) === 'FULL_TIME')>Full-time</option>
                        <option value="PART_TIME" @selected(old('employment_status', $user->employment_status) === 'PART_TIME')>Part-time</option>
                    </select>
                    @error('employment_status') <small class="field-error">{{ $message }}</small> @enderror
                </label>
            </div>
        </section>

        <section class="ictu-form-card admin-additional-assignment" id="ictu-additional-assignment"
                 aria-labelledby="ictu-additional-heading"
                 @if(old('access_classification', $user->access_classification?->value ?? 'BORROWER_ONLY') !== 'BORROWER_ONLY') hidden @endif>
            <header class="ictu-form-section-header">
                <h2 id="ictu-additional-heading">Additional Authorized Requesting Unit <span class="ictu-optional-badge">Optional</span></h2>
                <p>Specify other units this borrower is expressly authorized to represent.</p>
            </header>
            {{-- Independent, matched Division + Unit selectors. The backend still receives only authorized unit IDs. --}}
            <div class="ictu-field-grid ictu-field-grid-two ictu-additional-selection-grid">
                <label class="ictu-field" for="ictu-additional-division">
                    <span>Division</span>
                    <select id="ictu-additional-division">
                        <option value="">Select Division</option>
                        @foreach($divisionOptions as $additionalDivisionCode => $additionalDivisionLabel)
                            <option value="{{ $additionalDivisionCode }}">{{ $additionalDivisionLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ictu-field" for="ictu-additional-unit-picker">
                    <span>Office / College / Unit</span>
                    <select id="ictu-additional-unit-picker" disabled>
                        <option value="">Select Office / College / Unit</option>
                        @foreach($units as $additionalUnit)
@php $additionalDivisionCode = $additionalUnit->divisionCode(); @endphp
                            @if(!$additionalDivisionCode) @continue @endif
                            <option value="{{ $additionalUnit->id }}"
                                data-division="{{ $additionalDivisionCode }}"
                                data-unit-name="{{ $additionalUnit->unit_name }}"
                                data-division-label="{{ $additionalUnit->divisionLabel() }}">
                                {{ $additionalUnit->unit_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="ictu-additional-picker-actions">
                <button class="button secondary ictu-add-unit-button" type="button" id="ictu-add-additional-unit" disabled>
                    <x-icon name="plus" size="16" /> Add Unit
                </button>
            </div>
            <div class="admin-additional-unit-list" id="ictu-additional-unit-list" aria-live="polite">
                @foreach($units as $additionalUnit)
                    @if($selectedAdditionalUnitIds->contains((int) $additionalUnit->id))
                        <div class="admin-additional-unit-item" data-additional-unit-item data-unit-id="{{ $additionalUnit->id }}">
                            <div><strong>{{ $additionalUnit->unit_name }}</strong><small>{{ $additionalUnit->divisionLabel() }}</small></div>
                            <button type="button" class="button secondary" data-remove-additional-unit aria-label="Remove {{ $additionalUnit->unit_name }}">Remove</button>
                            <input type="hidden" name="additional_organizational_unit_ids[]" value="{{ $additionalUnit->id }}">
                        </div>
                    @endif
                @endforeach
                @foreach($historicalAdditionalUnits as $historicalAdditionalUnit)
                    <div class="admin-additional-unit-item" data-additional-unit-item data-unit-id="{{ $historicalAdditionalUnit->id }}">
                        <div><strong>{{ $historicalAdditionalUnit->unit_name }}</strong><small>{{ $historicalAdditionalUnit->divisionLabel() }} — Historical / inactive assignment</small></div>
                        <button type="button" class="button secondary" data-remove-additional-unit aria-label="Remove {{ $historicalAdditionalUnit->unit_name }}">Remove</button>
                        <input type="hidden" name="additional_organizational_unit_ids[]" value="{{ $historicalAdditionalUnit->id }}">
                    </div>
                @endforeach
            </div>
            @error('additional_organizational_unit_ids') <small class="field-error">{{ $message }}</small> @enderror
            @error('additional_organizational_unit_ids.*') <small class="field-error">{{ $message }}</small> @enderror
        </section>

        @endunless

        <section class="ictu-form-card" aria-labelledby="ictu-access-heading">
            <header class="ictu-form-section-header"><h2 id="ictu-access-heading">Classification, Status, and Contact</h2></header>
            <div class="ictu-field-grid ictu-field-grid-three">
                <div class="ictu-field">
                    <label for="ictu-access-classification">Access classification</label>
                    @if($isExternalAccount)
                        <input id="ictu-access-classification" value="{{ $user->access_classification->label() }}" readonly>
                        <input type="hidden" name="access_classification" value="{{ $user->access_classification->value }}">
                    @else
                    <select name="access_classification" id="ictu-access-classification" required @disabled($isSelfProtected)>
                        @foreach($classifications as $classification)
                            <option value="{{ $classification->value }}" @selected(old('access_classification', $user->access_classification?->value ?? 'BORROWER_ONLY') === $classification->value)>{{ $classification->label() }}</option>
                        @endforeach
                    </select>
                    @if($isSelfProtected)
                        <input type="hidden" name="access_classification" value="{{ $user->access_classification?->value }}">
                        <small class="field-note">You cannot change your own access classification.</small>
                    @endif
                    @endif
                    @error('access_classification') <small class="field-error">{{ $message }}</small> @enderror
                </div>
                <div class="ictu-field">
                    <label for="ictu-account-status">Account status</label>
                    <select name="account_status" id="ictu-account-status" required @disabled($isSelfProtected)>
                        @foreach($accountStatuses as $status)
@php
                                $accountStatusLabel = match ($status->value) {
                                    'ACTIVE' => 'Active', 'INACTIVE' => 'Inactive', 'SUSPENDED' => 'Suspended', default => $status->value,
                                };
                            @endphp
                            <option value="{{ $status->value }}" @selected(old('account_status', $user->account_status?->value ?? 'ACTIVE') === $status->value)>{{ $accountStatusLabel }}</option>
                        @endforeach
                    </select>
                    @if($isSelfProtected)
                        <input type="hidden" name="account_status" value="{{ $user->account_status?->value }}">
                        <small class="field-note">You cannot change your own account status.</small>
                    @endif
                    @error('account_status') <small class="field-error">{{ $message }}</small> @enderror
                </div>
                <div class="ictu-field">
                    <label for="ictu-mobile">Mobile number</label>
                    <input type="tel" name="mobile_no" id="ictu-mobile" value="{{ old('mobile_no', $user->mobile_no) }}" placeholder="Enter mobile number" autocomplete="tel">
                    @error('mobile_no') <small class="field-error">{{ $message }}</small> @enderror
                </div>
            </div>
        </section>

        <section class="ictu-form-card" aria-labelledby="ictu-administrative-heading">
            <header class="ictu-form-section-header"><h2 id="ictu-administrative-heading">Administrative Actions</h2></header>
            <div class="ictu-field-grid ictu-field-grid-two">
                <label class="ictu-field" for="ictu-new-password">
                    <span>{{ $user->exists ? 'New password (leave blank to retain)' : 'Password' }}</span>
                    <span class="ictu-password-input-wrap">
                        <input type="password" name="password" id="ictu-new-password" autocomplete="new-password" @required(!$user->exists)>
                        <button type="button" class="ictu-password-toggle" data-password-toggle data-password-target="ictu-new-password" aria-label="Show new password" title="Show password">
                            <svg class="ictu-password-eye" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"></path><circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg class="ictu-password-eye-off" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" hidden>
                                <path d="M3 3l18 18"></path><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"></path><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c7 0 11 8 11 8a18.4 18.4 0 0 1-3 4.2"></path><path d="M6.6 6.6C3.2 8.8 1 12 1 12s4 8 11 8a10.8 10.8 0 0 0 5.4-1.4"></path>
                            </svg>
                        </button>
                    </span>
                    @error('password') <small class="field-error">{{ $message }}</small> @enderror
                </label>
                <label class="ictu-field" for="ictu-confirm-password">
                    <span>Confirm password</span>
                    <span class="ictu-password-input-wrap">
                        <input type="password" name="password_confirmation" id="ictu-confirm-password" autocomplete="new-password" @required(!$user->exists)>
                        <button type="button" class="ictu-password-toggle" data-password-toggle data-password-target="ictu-confirm-password" aria-label="Show confirm password" title="Show password">
                            <svg class="ictu-password-eye" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"></path><circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg class="ictu-password-eye-off" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" hidden>
                                <path d="M3 3l18 18"></path><path d="M10.6 10.7a2 2 0 0 0 2.7 2.7"></path><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c7 0 11 8 11 8a18.4 18.4 0 0 1-3 4.2"></path><path d="M6.6 6.6C3.2 8.8 1 12 1 12s4 8 11 8a10.8 10.8 0 0 0 5.4-1.4"></path>
                            </svg>
                        </button>
                    </span>
                    @error('password_confirmation') <small class="field-error">{{ $message }}</small> @enderror
                </label>
            </div>

            @if($user->exists)
                <div class="ictu-form-actions ictu-administrative-actions-row">
                    <div class="ictu-form-action-left">
                        @if($canDeletePending)
                            <button type="submit" form="ictu-delete-pending-registration-form"
                                    class="button secondary ictu-delete-account-button"
                                    id="ictu-delete-pending-registration-button"
                                    aria-describedby="ictu-pending-deletion-warning"
                                    aria-label="Delete unverified account for {{ $user->full_name }}">
                                <x-icon name="trash" size="16" /> Delete Account
                            </button>
                            <small id="ictu-pending-deletion-warning" class="ictu-account-action-note is-danger">
                                <x-icon name="information" size="13" />
                                <span>Deleting this pending account cannot be undone.</span>
                            </small>
                        @elseif($canMaintainAccount)
                            <small class="ictu-account-action-note is-danger">
                                <x-icon name="information" size="13" />
                                <span>{{ $pendingRegistrationDeletionMessage }}</span>
                            </small>
                        @endif
                    </div>
                    <div class="ictu-form-action-right">
                        <a class="button secondary ui-pressable" href="{{ route('administration.users.index') }}">Cancel</a>
                        <button class="button primary ui-pressable" type="submit"><x-icon name="save" size="17" /> Save Changes</button>
                    </div>
                </div>
            @endif
        </section>

        @unless($user->exists)
            <div class="ictu-form-actions">
                <div class="ictu-form-action-right">
                    <a class="button secondary ui-pressable" href="{{ route('administration.users.index') }}">Cancel</a>
                    <button class="button primary ui-pressable" type="submit"><x-icon name="save" size="17" /> Create Account</button>
                </div>
            </div>
        @endunless
    </form>

    @if($canDeletePending)
        {{-- Separate form: Delete must never submit the unsaved changes in the Edit User form. --}}
        <form id="ictu-delete-pending-registration-form" method="post"
              action="{{ route('administration.users.delete-pending', $user) }}"
              data-preserve-submit-label="true" hidden>
            @csrf
            @method('DELETE')
        </form>
    @endif

    @if($canMaintainAccount && $user->exists && !$user->hasVerifiedInstitutionalEmail())
        <form id="ictu-resend-email-verification-form" method="post" action="{{ route('administration.users.email-verification.resend', $user) }}" data-preserve-submit-label="true" hidden>@csrf</form>
    @endif
</section>

@include('administration.users.partials.ui-styles')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const division = document.getElementById('ictu-division');
    const unit = document.getElementById('ictu-organizational-unit');
    const newUnitField = document.getElementById('ictu-new-unit-field');
    const newUnitName = document.getElementById('ictu-new-unit-name');
    const classification = document.getElementById('ictu-access-classification');
    const additionalAssignment = document.getElementById('ictu-additional-assignment');
    const additionalDivision = document.getElementById('ictu-additional-division');
    const additionalPicker = document.getElementById('ictu-additional-unit-picker');
    const additionalList = document.getElementById('ictu-additional-unit-list');
    const addAdditionalButton = document.getElementById('ictu-add-additional-unit');
    document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
        const input = document.getElementById(toggle.dataset.passwordTarget || '');
        if (!input) return;

        const eye = toggle.querySelector('.ictu-password-eye');
        const eyeOff = toggle.querySelector('.ictu-password-eye-off');
        toggle.addEventListener('click', () => {
            const willShow = input.type === 'password';
            input.type = willShow ? 'text' : 'password';
            toggle.classList.toggle('is-revealing', willShow);
            toggle.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
            toggle.setAttribute('title', willShow ? 'Hide password' : 'Show password');
            if (eye) eye.hidden = willShow;
            if (eyeOff) eyeOff.hidden = !willShow;
        });
    });

    const deleteButton = document.getElementById('ictu-delete-pending-registration-button');
    deleteButton?.addEventListener('click', event => {
        if (!window.confirm('Delete this pending or unverified account? This cannot be undone. If the mailbox is valid but mistyped, editing the email and sending a new OTP is safer.')) {
            event.preventDefault();
        }
    });

    if (!division || !unit) return;

    const selectedAdditionalIds = () => Array.from(additionalList?.querySelectorAll('[data-additional-unit-item]') || [])
        .map(item => String(item.dataset.unitId || ''));

    const syncNewUnitField = () => {
        const adding = unit.value === '__NEW__';
        if (newUnitField) newUnitField.hidden = !adding;
        if (newUnitName) newUnitName.required = adding;
    };
    const syncAdditionalUnitOptions = () => {
        if (!additionalPicker) return;
        const primaryId = /^\d+$/.test(unit.value) ? String(unit.value) : '';
        const selectedIds = selectedAdditionalIds();
        const selectedDivision = additionalDivision?.value || '';

        Array.from(additionalPicker.options).forEach(option => {
            if (!option.value) return;
            const matches = Boolean(selectedDivision) && option.dataset.division === selectedDivision;
            option.hidden = !matches;
            option.disabled = !matches || option.value === primaryId || selectedIds.includes(option.value);
        });
        additionalPicker.disabled = !selectedDivision || additionalAssignment?.hidden === true;
        if (additionalPicker.selectedOptions?.[0]?.disabled || additionalPicker.disabled) additionalPicker.value = '';
        if (addAdditionalButton) addAdditionalButton.disabled = additionalPicker.disabled || !additionalPicker.value;
    };
    const syncAdditionalAssignments = () => {
        if (!additionalAssignment) return;
        const isBorrower = !classification || classification.value === 'BORROWER_ONLY';
        additionalAssignment.hidden = !isBorrower;

        const newUnitOption = unit.querySelector('[data-new-unit]');
        if (newUnitOption) {
            newUnitOption.hidden = !isBorrower;
            newUnitOption.disabled = !isBorrower;
            if (!isBorrower && unit.value === '__NEW__') {
                unit.value = '';
                if (newUnitName) newUnitName.value = '';
                syncNewUnitField();
            }
        }
        const primaryId = /^\d+$/.test(unit.value) ? String(unit.value) : '';
        Array.from(additionalList?.querySelectorAll('[data-additional-unit-item]') || []).forEach(item => {
            if (String(item.dataset.unitId || '') === primaryId) item.remove();
        });
        syncAdditionalUnitOptions();
    };
    const syncUnits = () => {
        const selectedDivision = division.value;
        const currentValue = unit.value;
        Array.from(unit.options).forEach(option => {
            if (!option.value || option.dataset.newUnit !== undefined) {
                option.hidden = false;
                option.disabled = false;
                return;
            }
            const matches = option.dataset.division === selectedDivision;
            option.hidden = !matches;
            option.disabled = !matches;
        });
        if (unit.selectedOptions?.[0]?.disabled) unit.value = '';
        else if (currentValue && unit.value === '') {
            const match = Array.from(unit.options).find(option => option.value === currentValue && !option.disabled);
            if (match) unit.value = currentValue;
        }
        syncNewUnitField();
        syncAdditionalAssignments();
    };
    const removeAdditionalUnit = item => { item?.remove(); syncAdditionalAssignments(); };
    const addAdditionalUnit = () => {
        if (!additionalPicker?.value || !additionalList) return;
        const selected = additionalPicker.selectedOptions?.[0];
        if (!selected || selected.disabled) return;
        const unitId = String(selected.value);
        if (selectedAdditionalIds().includes(unitId)) return;
        const unitName = selected.dataset.unitName || selected.textContent?.trim() || '';
        const divisionLabel = selected.dataset.divisionLabel || selected.parentElement?.label || '';
        const item = document.createElement('div');
        item.className = 'admin-additional-unit-item';
        item.dataset.additionalUnitItem = '';
        item.dataset.unitId = unitId;
        const copy = document.createElement('div');
        const strong = document.createElement('strong');
        const small = document.createElement('small');
        strong.textContent = unitName;
        small.textContent = divisionLabel;
        copy.append(strong, small);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button secondary';
        remove.dataset.removeAdditionalUnit = '';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', `Remove ${unitName}`);
        remove.addEventListener('click', () => removeAdditionalUnit(item));
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'additional_organizational_unit_ids[]';
        hidden.value = unitId;
        item.append(copy, remove, hidden);
        additionalList.appendChild(item);
        additionalPicker.value = '';
        syncAdditionalAssignments();
    };
    additionalList?.querySelectorAll('[data-remove-additional-unit]').forEach(button => {
        button.addEventListener('click', () => removeAdditionalUnit(button.closest('[data-additional-unit-item]')));
    });
    division.addEventListener('change', syncUnits);
    unit.addEventListener('change', () => { syncNewUnitField(); syncAdditionalAssignments(); });
    classification?.addEventListener('change', syncAdditionalAssignments);
    additionalDivision?.addEventListener('change', () => {
        if (additionalPicker) additionalPicker.value = '';
        syncAdditionalUnitOptions();
    });
    additionalPicker?.addEventListener('change', syncAdditionalUnitOptions);
    addAdditionalButton?.addEventListener('click', addAdditionalUnit);
    syncUnits();

});
</script>
<style>

.ictu-password-input-wrap {
    position: relative !important;
    display: block !important;

    width: 100% !important;
    min-width: 0 !important;
}

.ictu-password-input-wrap > input {
    width: 100% !important;
    padding-right: 42px !important;

    transition:
        border-color .16s ease,
        box-shadow .16s ease,
        background-color .16s ease !important;
}

.ictu-password-input-wrap > .ictu-password-toggle {
    position: absolute !important;

    top: 50% !important;
    right: 8px !important;

    z-index: 3 !important;

    display: grid !important;
    place-items: center !important;

    width: 28px !important;
    height: 28px !important;
    min-width: 28px !important;
    min-height: 28px !important;

    margin: 0 !important;
    padding: 0 !important;

    border: 0 !important;
    border-radius: 6px !important;

    background: transparent !important;
    color: #788b9e !important;

    box-shadow: none !important;

    transform: translateY(-50%) !important;

    cursor: pointer !important;
}

.ictu-password-input-wrap > .ictu-password-toggle:hover {
    background: #eef5ff !important;
    color: #0969e8 !important;
}

.ictu-password-input-wrap > .ictu-password-toggle:focus-visible {
    outline: none !important;

    background: #eef5ff !important;
    color: #0969e8 !important;

    box-shadow:
        0 0 0 2px rgba(9, 105, 232, .14) !important;
}

.ictu-password-input-wrap > .ictu-password-toggle svg {
    width: 15px !important;
    height: 15px !important;

    pointer-events: none;
}

.ictu-password-input-wrap > .ictu-password-toggle svg[hidden] {
    display: none !important;
}

.ictu-password-input-wrap.password-is-invalid > input {
    border-color: #dc3545 !important;

    background: rgba(220, 53, 69, .025) !important;

    box-shadow:
        0 0 0 2px rgba(220, 53, 69, .08) !important;
}

.ictu-password-input-wrap.password-is-invalid
> .ictu-password-toggle {
    color: #dc3545 !important;
}

.ictu-password-input-wrap.password-is-valid > input {
    border-color: #1a9b65 !important;

    background: rgba(26, 155, 101, .025) !important;

    box-shadow:
        0 0 0 2px rgba(26, 155, 101, .08) !important;
}

.ictu-password-input-wrap.password-is-valid
> .ictu-password-toggle {
    color: #1a9b65 !important;
}

.password-guidance-block {
    display: grid;

    gap: 5px;

    margin-top: 5px;
}

.password-main-feedback {
    display: inline-flex;
    align-items: center;

    gap: 5px;

    width: fit-content;

    margin: 0;

    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    line-height: 1.35;
}

.password-main-feedback.is-error {
    color: #dc3545;
}

.password-main-feedback.is-success {
    color: #1a9b65;
}

.password-main-feedback::before {
    content: "!";

    display: grid;
    place-items: center;

    width: 13px;
    height: 13px;

    flex: 0 0 13px;

    border: 1.5px solid currentColor;
    border-radius: 50%;

    font-size: 8px;
    font-weight: 800;
    line-height: 1;
}

.password-main-feedback.is-success::before {
    content: "✓";

    border: 0;

    font-size: 12px;
}

.password-requirements {
    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, max-content));

    gap: 3px 16px;

    margin: 1px 0 0;
    padding: 0;

    list-style: none;
}

.password-requirement {
    display: flex;
    align-items: center;

    gap: 5px;

    margin: 0;

    color: #7b8c9f;

    font-family: inherit;
    font-size: 9.5px;
    font-weight: 600;
    line-height: 1.3;
}

.password-requirement::before {
    content: "✓";

    display: grid;
    place-items: center;

    width: 12px;
    height: 12px;

    flex: 0 0 12px;

    border: 1px solid #c4d0dc;
    border-radius: 50%;

    color: #c4d0dc;

    font-size: 8px;
    font-weight: 800;
}

.password-requirement.is-met {
    color: #159957;
}

.password-requirement.is-met::before {
    border-color: #19b866;

    color: #19b866;
}

.password-confirm-feedback {
    display: inline-flex;
    align-items: center;

    gap: 5px;

    width: fit-content;

    margin-top: 4px;

    font-family: inherit;
    font-size: 10px;
    font-weight: 650;
    line-height: 1.35;
}

.password-confirm-feedback.is-error {
    color: #dc3545;
}

.password-confirm-feedback.is-success {
    color: #1a9b65;
}

.password-confirm-feedback::before {
    content: "";

    width: 5px;
    height: 5px;

    flex: 0 0 5px;

    border-radius: 50%;

    background: currentColor;
}

.password-live-hidden {
    display: none !important;
}

@media (max-width: 720px) {
    .password-requirements {
        grid-template-columns: 1fr;
        gap: 3px;
    }
}

</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const passwordInput = document.querySelector(
        '#ictu-new-password'
    );

    const confirmInput = document.querySelector(
        '#ictu-confirm-password'
    );

    if (!passwordInput) {
        return;
    }

    const passwordWrap = passwordInput.closest(
        '.ictu-password-input-wrap'
    );

    const passwordField = passwordInput.closest(
        'label.ictu-field'
    );

    const confirmWrap = confirmInput?.closest(
        '.ictu-password-input-wrap'
    );

    const confirmField = confirmInput?.closest(
        'label.ictu-field'
    );

    if (
        !passwordWrap ||
        !passwordField
    ) {
        return;
    }

    passwordInput.minLength = 12;
    passwordInput.removeAttribute('maxlength');

    if (confirmInput) {
        confirmInput.minLength = 12;
        confirmInput.removeAttribute('maxlength');
    }

    passwordField
        .querySelectorAll('[data-password-guidance]')
        .forEach((element) => element.remove());

    confirmField
        ?.querySelectorAll('[data-password-confirm-feedback]')
        .forEach((element) => element.remove());

    const guidance = document.createElement('div');

    guidance.className =
        'password-guidance-block';

    guidance.dataset.passwordGuidance = '';

    guidance.innerHTML = `
        <div
            class="password-main-feedback password-live-hidden"
            data-password-main-feedback
            aria-live="polite"
        ></div>

        <ul class="password-requirements">
            <li
                class="password-requirement"
                data-password-rule="length"
            >
                At least 12 characters
            </li>

            <li
                class="password-requirement"
                data-password-rule="letter"
            >
                Contains a letter
            </li>

            <li
                class="password-requirement"
                data-password-rule="number"
            >
                Contains number
            </li>

            <li
                class="password-requirement"
                data-password-rule="symbol"
            >
                Contains special character
            </li>
        </ul>
    `;

    passwordWrap.insertAdjacentElement(
        'afterend',
        guidance
    );

    const mainFeedback = guidance.querySelector(
        '[data-password-main-feedback]'
    );

    let confirmFeedback = null;

    if (
        confirmInput &&
        confirmWrap &&
        confirmField
    ) {
        confirmFeedback =
            document.createElement('div');

        confirmFeedback.className =
            'password-confirm-feedback password-live-hidden';

        confirmFeedback.dataset.passwordConfirmFeedback = '';

        confirmFeedback.setAttribute(
            'aria-live',
            'polite'
        );

        confirmWrap.insertAdjacentElement(
            'afterend',
            confirmFeedback
        );
    }

    const rules = {
        length: {
            valid: (value) =>
                Array.from(value).length >= 12,

            error: (value) => {
                if (Array.from(value).length < 12) {
                    return 'Password must be at least 12 characters.';
                }

                return null;
            },
        },

        letter: {
            valid: (value) =>
                /\p{L}/u.test(value),

            error: () =>
                'Password must contain a letter.',
        },

        number: {
            valid: (value) =>
                /\p{N}/u.test(value),

            error: () =>
                'Password must contain a number.',
        },

        symbol: {
            valid: (value) =>
                /[\p{Z}\p{S}\p{P}]/u.test(value),

            error: () =>
                'Password must contain a special character.',
        },
    };

    const clearFieldState = (wrap) => {
        wrap?.classList.remove(
            'password-is-invalid',
            'password-is-valid'
        );
    };

    const setFieldState = (
        wrap,
        valid
    ) => {
        if (!wrap) {
            return;
        }

        wrap.classList.remove(
            'password-is-invalid',
            'password-is-valid'
        );

        wrap.classList.add(
            valid
                ? 'password-is-valid'
                : 'password-is-invalid'
        );
    };

    const updateRequirement = (
        ruleName,
        met
    ) => {
        const element = guidance.querySelector(
            `[data-password-rule="${ruleName}"]`
        );

        element?.classList.toggle(
            'is-met',
            met
        );
    };

    const hideFeedback = (element) => {
        if (!element) {
            return;
        }

        element.textContent = '';

        element.classList.remove(
            'is-error',
            'is-success'
        );

        element.classList.add(
            'password-live-hidden'
        );
    };

    const showFeedback = (
        element,
        message,
        type
    ) => {
        if (!element) {
            return;
        }

        element.textContent = message;

        element.classList.remove(
            'password-live-hidden',
            'is-error',
            'is-success'
        );

        element.classList.add(
            type === 'success'
                ? 'is-success'
                : 'is-error'
        );
    };

    const validateConfirmation = () => {

        if (
            !confirmInput ||
            !confirmFeedback
        ) {
            return;
        }

        const confirmation =
            confirmInput.value;

        const password =
            passwordInput.value;

        if (confirmation === '') {
            clearFieldState(confirmWrap);
            hideFeedback(confirmFeedback);
            return;
        }

        if (
            confirmation !== password ||
            password === ''
        ) {
            setFieldState(
                confirmWrap,
                false
            );

            showFeedback(
                confirmFeedback,
                'Passwords do not match.',
                'error'
            );

            return;
        }

        setFieldState(
            confirmWrap,
            true
        );

        showFeedback(
            confirmFeedback,
            'Passwords match.',
            'success'
        );
    };

    const validatePassword = () => {

        const value =
            passwordInput.value;

        if (value === '') {

            clearFieldState(passwordWrap);

            hideFeedback(mainFeedback);

            Object.keys(rules).forEach(
                (ruleName) => {
                    updateRequirement(
                        ruleName,
                        false
                    );
                }
            );

            validateConfirmation();

            return;
        }

        const results = {};

        Object.entries(rules).forEach(
            ([ruleName, rule]) => {

                results[ruleName] =
                    rule.valid(value);

                updateRequirement(
                    ruleName,
                    results[ruleName]
                );
            }
        );

        const valid =
            Object.values(results)
                .every(Boolean);

        setFieldState(
            passwordWrap,
            valid
        );

        if (valid) {

            showFeedback(
                mainFeedback,
                'Password meets all requirements.',
                'success'
            );
        }
        else {

            const firstInvalid =
                Object.keys(rules)
                    .find(
                        (ruleName) =>
                            !results[ruleName]
                    );

            const message =
                firstInvalid
                    ? rules[firstInvalid].error(value)
                    : 'Password does not meet the requirements.';

            showFeedback(
                mainFeedback,
                message,
                'error'
            );
        }

        validateConfirmation();
    };

    passwordInput.addEventListener(
        'input',
        validatePassword
    );

    confirmInput?.addEventListener(
        'input',
        validateConfirmation
    );

    validatePassword();
});
</script>

@endsection
