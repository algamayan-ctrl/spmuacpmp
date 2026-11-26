@props(['borrower'])

<details class="borrower-contact-disclosure">
    <summary>Contact details</summary>
    <dl class="detail-list">
        <dt>Email</dt>
        <dd>{{ $borrower?->email ?: 'Not recorded' }}</dd>
        <dt>Mobile Number</dt>
        <dd>{{ $borrower?->mobile_no ?: 'Not recorded' }}</dd>
    </dl>
</details>
