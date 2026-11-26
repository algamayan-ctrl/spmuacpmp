@props(['borrower', 'version'])

<div class="borrower-record-identity">
    <dl class="detail-list">
        <dt>Borrower</dt>
        <dd>{{ $borrower?->full_name ?: 'Not recorded' }}</dd>
        <dt>Borrower Type</dt>
        <dd>{{ $version?->affiliationTypeLabel() ?: 'Not recorded' }}</dd>
        <dt>{{ $version?->isExternalAffiliation() ? 'Company / Agency / Institution' : 'Office / College / Unit' }}</dt>
        <dd>{{ $version?->affiliationLabel() ?: 'Not recorded' }}</dd>
    </dl>
    <x-borrower-contact-details :borrower="$borrower" />
</div>
