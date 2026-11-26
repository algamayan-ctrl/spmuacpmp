@extends('layouts.app', ['title' => $item->exists ? 'Edit Inventory Details' : 'Add Inventory Item'])

@section('content')
{{-- This is the Inventory Item form for SPMU Admin. --}}
@include('inventory.partials.form-styles')

<div class="inventory-form-page">
    <section class="page-heading inventory-form-heading">
        <div>
            <p class="eyebrow">SPMU inventory administration</p>
            <h1>{{ $item->exists ? 'Edit inventory details' : 'Add inventory item' }}</h1>
        </div>

        <a class="inventory-form-back" href="{{ $item->exists ? route('inventory.show', $item) : route('inventory.index') }}">
            Back
        </a>
    </section>

    <section class="content-area">
        <form
            method="post"
            action="{{ $item->exists ? route('inventory.update', $item) : route('inventory.store') }}"
            class="inventory-form-card"
        >
            @csrf
            @if($item->exists)
                @method('PUT')
            @endif

            <div class="inventory-form-columns">
                <label>
                    Category
                    <select name="category_id" required>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id', $item->category_id) == $category->id)>
                                {{ $category->category_name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Unit
                    <select name="unit_id" required>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}" @selected(old('unit_id', $item->unit_id) == $unit->id)>
                                {{ $unit->unit_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label>
                Unique description
                <input name="unique_description" value="{{ old('unique_description', $item->unique_description) }}" required>
            </label>

            <label>
                Specification
<textarea name="specification">{{ old('specification', $item->specification) }}</textarea>
            </label>

            @if($item->exists)
                <section class="inventory-form-stock-control" aria-label="Physical stock control">
                    <div class="inventory-form-stock-control-head">
                        <div>
                            <span>Physical stock control</span>
                            <strong>Edit total quantity <small class="inventory-form-admin-badge">SPMU Admin only</small></strong>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('inventory.show', ['inventory' => $item->id, 'tab' => 'overview']) }}">
                            View Inventory Overview
                        </a>
                    </div>

                    <div class="inventory-form-stock-values">
                        <div>
                            <span>Current Total Quantity</span>
                            <strong>{{ (float) $item->total_quantity + 0 }}</strong>
                        </div>
                        <div>
                            <span>Master Condition</span>
                            <strong>{{ match($item->condition_code) {
                                'SERVICEABLE' => 'Good / Serviceable',
                                'DAMAGED_MAINTENANCE' => 'Damaged / Under Repair',
                                'CONDEMNED' => 'Condemned',
                                default => str($item->condition_code)->replace('_', ' ')->title(),
                            } }}</strong>
                        </div>
                    </div>

                </section>
            @else
                <div class="inventory-form-columns">
                    <label>
                        Total quantity
                        <input
                            type="number"
                            step="1"
                            min="0"
                            inputmode="numeric"
                            name="total_quantity"
                            value="{{ old('total_quantity', $item->total_quantity ?? 0) }}"
                            required
                        >
                    </label>

                    <label>
                        Initial condition
                        <select name="condition_code">
                            <option value="SERVICEABLE" @selected(old('condition_code', $item->condition_code) === 'SERVICEABLE')>Good / Serviceable</option>
                            <option value="DAMAGED_MAINTENANCE" @selected(old('condition_code', $item->condition_code) === 'DAMAGED_MAINTENANCE')>Damaged / Under Repair</option>
                            <option value="CONDEMNED" @selected(old('condition_code', $item->condition_code) === 'CONDEMNED')>Condemned</option>
                        </select>
                    </label>
                </div>
            @endif

            <fieldset>
                <legend>Operational flags</legend>

                <div class="inventory-form-flags">
                    <label>
                        <input type="checkbox" name="borrowable" value="1" @checked(old('borrowable', $item->exists ? $item->borrowable : true))>
                        Borrowable
                    </label>

                    <label>
                        <input type="checkbox" name="laundry_required" value="1" @checked(old('laundry_required', $item->laundry_required))>
                        Laundry required
                    </label>

                    <label>
                        <input type="checkbox" name="off_campus_allowed" value="1" @checked(old('off_campus_allowed', $item->off_campus_allowed))>
                        Off-campus allowed (Barricade only)
                    </label>

                    <label>
                        <input type="checkbox" name="provisional" value="1" @checked(old('provisional', $item->provisional))>
                        Provisional
                    </label>

                    <label>
                        <input type="checkbox" name="active" value="1" @checked(old('active', $item->exists ? $item->active : true))>
                        Active
                    </label>
                    @error('active')<small class="field-error inventory-form-flag-error">{{ $message }}</small>@enderror
                </div>
            </fieldset>

            @if($item->exists)
                <label>
                    Reason for Change *
<textarea
                        name="change_reason"
                        required
                        placeholder="Briefly state why the inventory details are being updated."
                    >{{ old('change_reason') }}</textarea>
                    @error('change_reason')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            @else
                <label>
                    Initial Stock Source / Reference <small>(Optional)</small>
<textarea
                        name="initial_stock_source"
                        placeholder="Example: Delivery Receipt, Purchase Order, property transfer, or opening inventory reference."
                    >{{ old('initial_stock_source') }}</textarea>
                    @error('initial_stock_source')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            @endif

            <div class="inventory-form-actions">
                <a class="button secondary ui-pressable inventory-form-cancel" href="{{ $item->exists ? route('inventory.show', $item) : route('inventory.index') }}">
                    Cancel
                </a>

                <button class="button primary ui-pressable inventory-form-save" type="submit">
                    {{ $item->exists ? 'Save Changes' : 'Add Inventory Item' }}
                </button>
            </div>
        </form>

        @if($item->exists)
@php
                $deleteErrors = $errors->getBag('inventoryDelete');
                $deletionBlockers = $deletionBlockers ?? [];
            @endphp

            {{-- Permanent delete: separate from Save Changes; the server re-checks everything. --}}
            <section class="inventory-delete-zone" aria-labelledby="inventory-delete-heading">
                <div>
                    <strong id="inventory-delete-heading">Delete inventory item</strong>
                    @if($deletionBlockers === [])
                        <p>Permanently removes this item. Allowed only because it has no related transaction records.</p>
                    @else
                        <p>This item cannot be deleted. To stop using it, uncheck <strong>Active</strong> above and save to archive it.</p>
                        <ul>
                            @foreach($deletionBlockers as $blocker)
                                <li>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if($deleteErrors->has('delete'))
                        <small class="field-error" role="alert">{{ $deleteErrors->first('delete') }}</small>
                    @endif
                </div>

                <button
                    type="button"
                    class="button danger ui-pressable"
                    data-inventory-delete-open
                    @disabled($deletionBlockers !== [])
                >
                    Delete Item
                </button>
            </section>

            @if($deletionBlockers === [])
                <dialog
                    class="inventory-delete-dialog"
                    id="inventory-delete-dialog"
                    aria-labelledby="inventory-delete-title"
                    data-reopen="{{ $deleteErrors->hasAny(['confirm_item_name', 'deletion_reason']) ? '1' : '0' }}"
                >
                    <form method="post" action="{{ route('inventory.destroy', $item) }}" data-inventory-delete-form>
                        @csrf
                        @method('DELETE')

                        <h2 id="inventory-delete-title">Delete Inventory Item?</h2>
                        <p>This action permanently deletes this inventory item. It is only allowed for items without related transaction records.</p>

                        <label>
                            Type the item name to confirm: <strong>{{ $item->unique_description }}</strong>
                            <input
                                name="confirm_item_name"
                                autocomplete="off"
                                required
                                data-expected-name="{{ $item->unique_description }}"
                                value="{{ old('confirm_item_name') }}"
                            >
                            @if($deleteErrors->has('confirm_item_name'))
                                <small class="field-error">{{ $deleteErrors->first('confirm_item_name') }}</small>
                            @endif
                        </label>

                        <label>
                            Reason for deletion
<textarea name="deletion_reason" required maxlength="1000">{{ old('deletion_reason') }}</textarea>
                            @if($deleteErrors->has('deletion_reason'))
                                <small class="field-error">{{ $deleteErrors->first('deletion_reason') }}</small>
                            @endif
                        </label>

                        <div class="inventory-delete-actions">
                            <button type="button" class="button secondary ui-pressable" data-inventory-delete-cancel>Cancel</button>
                            <button type="submit" class="button danger ui-pressable" data-inventory-delete-submit disabled>Delete Permanently</button>
                        </div>
                    </form>
                </dialog>
            @endif

            <style>
                .inventory-delete-zone { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
                .inventory-delete-zone strong { color: var(--heading); font-size: 13px; }
                .inventory-delete-zone p { margin: 4px 0 0; color: var(--text-muted); font-size: 12px; line-height: 1.45; }
                .inventory-delete-zone ul { margin: 6px 0 0; padding-left: 18px; color: var(--text-muted); font-size: 12px; line-height: 1.45; }
                .inventory-delete-zone .field-error { display: block; margin-top: 6px; color: var(--danger); }
                .inventory-delete-zone .button { flex: 0 0 auto; }
                .inventory-delete-dialog { width: min(480px, calc(100vw - 32px)); padding: 0; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-elevated); color: var(--text); }
                .inventory-delete-dialog::backdrop { background: rgba(8, 18, 33, .58); }
                .inventory-delete-dialog form { display: grid; gap: 12px; padding: 16px 16px; }
                .inventory-delete-dialog h2 { margin: 0; color: var(--heading); font-size: 18px; }
                .inventory-delete-dialog p { margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.5; }
                .inventory-delete-dialog label { display: grid; gap: 6px; font-size: 12px; font-weight: 650; }
                .inventory-delete-dialog .field-error { color: var(--danger); }
                .inventory-delete-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; }
                @media (max-width: 640px) {
                    .inventory-delete-zone { flex-direction: column; align-items: stretch; }
                }
            </style>

<script>
            document.addEventListener('DOMContentLoaded', () => {
                const dialog = document.getElementById('inventory-delete-dialog');
                const openButton = document.querySelector('[data-inventory-delete-open]');

                if (!dialog || !openButton || typeof dialog.showModal !== 'function') return;

                const form = dialog.querySelector('[data-inventory-delete-form]');
                const nameInput = form.querySelector('[name="confirm_item_name"]');
                const reasonInput = form.querySelector('[name="deletion_reason"]');
                const submitButton = form.querySelector('[data-inventory-delete-submit]');

                const sync = () => {
                    submitButton.disabled = nameInput.value.trim() !== nameInput.dataset.expectedName
                        || reasonInput.value.trim() === '';
                };

                nameInput.addEventListener('input', sync);
                reasonInput.addEventListener('input', sync);

                openButton.addEventListener('click', () => {
                    sync();
                    dialog.showModal();
                    nameInput.focus();
                });

                form.querySelector('[data-inventory-delete-cancel]').addEventListener('click', () => {
                    dialog.close();
                    form.reset();
                    sync();
                });

                if (dialog.dataset.reopen === '1') {
                    sync();
                    dialog.showModal();
                }
            });
            </script>
        @endif
    </section>
</div>
@endsection
