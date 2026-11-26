<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BorrowingRequest extends Model
{
    public function getRequestNoAttribute(?string $value): string
    {
        return str_starts_with($value ?? '', 'PENDING-') ? 'Control No. pending submission' : ($value ?? '');
    }

    use HasFactory;

    protected $fillable = [
        'request_no',
        'borrower_user_id',
        'accountable_unit_id',
        'current_version_no',
        'status',
        'final_approved_at',

        /* Historical download workflow field. */
        'download_deadline_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'final_approved_at' => 'datetime',
            'download_deadline_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function numberAliases(): HasMany
    {
        return $this->hasMany(RequestNumberAlias::class, 'request_id');
    }

    public function previousControlNumbers(): array
    {
        if ($this->relationLoaded('numberAliases')) return $this->numberAliases->pluck('alias')->all();
        return app(\App\Services\AcademicYearRequestNumbers::class)->previousNumbers($this);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RequestVersion::class, 'request_id');
    }

    public function accountableUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'accountable_unit_id');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(RequestVersion::class, 'request_id')->ofMany('version_no', 'max');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RequestStatusHistory::class, 'request_id')->latest('changed_at');
    }

    public function supportingDocuments(): HasMany
    {
        return $this->hasMany(RequestSupportingDocument::class, 'request_id');
    }

    public function custody(): HasOne
    {
        return $this->hasOne(CustodyTransaction::class, 'request_id');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(RequestCancellation::class, 'request_id');
    }

    /** The most recent recorded reason when this request was cancelled. */
    public function latestCancellation(): HasOne
    {
        return $this->hasOne(RequestCancellation::class, 'request_id')->latestOfMany('cancelled_at');
    }

    /** Shared with staff record badges and the My Requests server filter. */
    public function borrowerRecordStatus(): array
    {
        $status = \App\Support\RequestRecordStatus::forRequest($this);

        return ['key' => $status['key'], 'label' => $status['label']];
    }
}
