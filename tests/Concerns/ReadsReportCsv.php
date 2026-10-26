<?php

namespace Tests\Concerns;

trait ReadsReportCsv
{
    /** CSV records may contain quoted line breaks in equipment and affiliation cells. */
    protected function reportCsvRows(string $csv): array
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
