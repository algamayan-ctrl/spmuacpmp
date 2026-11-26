<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AnalyticsFilters
{
    /** Reject arrays/oversized input before controllers cast filter values. */
    public static function validate(Request $request): void
    {
        $rules = [];
        foreach (['section' => 40, 'academic_period' => 40, 'group' => 100, 'unit' => 255,
            'detail' => 80, 'for' => 255, 'metric' => 80, 'q' => 100, 'item' => 80,
            'item_name' => 255, 'item_unit' => 80,
            'state' => 40, 'bucket' => 40, 'level' => 40, 'segment' => 100, 'borrower_type' => 40] as $field => $length) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.$length];
        }
        foreach (['borrower', 'selected_borrower'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:0'];
        }
        $rules['borrower_options'] = ['sometimes', 'nullable', 'boolean'];
        $request->validate($rules);
    }
}
