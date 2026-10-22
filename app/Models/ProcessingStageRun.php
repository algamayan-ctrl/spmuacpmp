<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessingStageRun extends Model
{
    protected $fillable = [
        'request_id', 'request_version_id', 'stage_code',
        'started_by_user_id', 'started_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }
}
