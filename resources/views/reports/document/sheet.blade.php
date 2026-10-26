@php
    /*
    | One formal report sheet.
    |
    | The preview, the print view and the PDF all compose the document from
    | this single partial, which is what keeps the three looking identical.
    | `$options` toggles only presentation â€” never the records.
    */
    $options = $options ?? [];
@endphp

<article class="doc-sheet">
    @if($options['header'] ?? true)
        @include('reports.document.header')
    @endif

    @include('reports.document.title')

    @if($options['metadata'] ?? true)
        @include('reports.document.metadata')
    @endif

    <div class="doc-table-scroll">
        @include('reports.document.table')
    </div>

    @include('reports.document.table-notes')

    @if($options['summary'] ?? true)
        @include('reports.document.summary')
    @endif

    @if($options['footer'] ?? true)
        @include('reports.document.footer')
    @endif
</article>
