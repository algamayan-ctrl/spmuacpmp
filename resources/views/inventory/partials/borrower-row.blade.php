@php
    $balance = $balances[$item->id] ?? [];

    $available = max(
        0,
        (int) floor((float) (
            $balance['borrower_available']
            ?? $balance['available']
            ?? 0
        ))
    );

    $categoryName = $item->category?->category_name ?: 'Uncategorized';
    $unitName = $item->unit?->unit_name ?: '—';
    $itemUiId = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
    $description = trim((string) ($item->specification ?? ''));
    $hasLongDescription = mb_strlen($description) > 120;
    $descriptionPreview = $hasLongDescription
        ? \Illuminate\Support\Str::limit($description, 120)
        : $description;

    $searchText = strtolower(
        $itemUiId.' '.
        $item->unique_description.' '.
        $description.' '.
        $categoryName.' '.
        $unitName
    );
@endphp

<tr
    data-borrower-inventory-row
    data-search="{{ $searchText }}"
    data-category="{{ strtolower($categoryName) }}"
    data-premises="{{ $item->off_campus_allowed ? 'off-campus' : 'on-campus' }}"
>
    <td class="col-number" data-label="No." data-row-number></td>

    <td class="col-id" data-label="Item ID">
        <span class="borrower-item-id">{{ $itemUiId }}</span>
    </td>

    <td class="col-description" data-label="Description">
        <span class="borrower-item-title">{{ $item->unique_description }}</span>

        <span class="borrower-description" data-description>
            @if($description !== '')
                <span data-description-preview>{{ $descriptionPreview }}</span>

                @if($hasLongDescription)
                    <span data-description-full hidden>{{ $description }}</span>

                    <button
                        class="borrower-description-more"
                        type="button"
                        data-description-toggle
                        aria-expanded="false"
                    >
                        More
                    </button>
                @endif
            @else
                No additional description.
            @endif
        </span>
    </td>

    <td class="col-category" data-label="Category">{{ $categoryName }}</td>

    <td class="col-unit" data-label="Unit">{{ $unitName }}</td>

    <td class="col-quantity is-numeric" data-label="Available">
        <span class="borrower-quantity">{{ $available }}</span>
    </td>

    <td class="col-premises" data-label="Premises">
        <span class="borrower-premises">
            {{ $item->off_campus_allowed ? 'Off-campus eligible' : 'On-campus only' }}
        </span>
    </td>
</tr>
