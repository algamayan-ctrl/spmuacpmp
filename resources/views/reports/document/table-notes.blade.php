@if(! empty($dataset->meta['table_notes']))
    <div class="doc-table-notes">
        @foreach($dataset->meta['table_notes'] as $note)
            <p>{{ $note }}</p>
        @endforeach
    </div>
@endif
