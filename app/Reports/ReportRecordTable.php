<?php

namespace App\Reports;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The on-screen records table for a report preview.
 *
 * It only arranges rows the report already produced: search narrows what is
 * shown, sorting reorders it and pagination slices it. It never queries and
 * never changes the report scope, so print and export still carry every
 * record in the period and filters the preview was generated for.
 */
final class ReportRecordTable
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 10;

    /** Query keys owned by the table view; everything else is report scope. */
    public const VIEW_KEYS = ['search', 'sort', 'direction', 'per_page', 'page'];

    /**
     * @param  list<array{key:string, label:string, align?:string}>  $columns
     * @param  array<string, mixed>  $scopeQuery
     */
    private function __construct(
        public readonly array $columns,
        public readonly LengthAwarePaginator $records,
        public readonly int $scopeTotal,
        public readonly string $search,
        public readonly ?string $sort,
        public readonly string $direction,
        public readonly int $perPage,
        private readonly string $path,
        private readonly array $scopeQuery,
    ) {}

    /**
     * @param  list<array{key:string, label:string, align?:string}>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows  every record in the report scope
     * @param  array<string, mixed>  $scopeQuery  query that reproduces the report scope
     */
    public static function fromRequest(
        Request $request,
        array $columns,
        Collection $rows,
        string $path,
        array $scopeQuery
    ): self {
        $keys = array_column($columns, 'key');

        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $sort = in_array($request->query('sort'), $keys, true) ? (string) $request->query('sort') : null;
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;

        $visible = $rows->values();

        if ($search !== '') {
            $needle = mb_strtolower($search);

            $visible = $visible->filter(function (array $row) use ($keys, $needle): bool {
                $text = implode(' ', array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $keys));

                return str_contains(mb_strtolower($text), $needle);
            })->values();
        }

        if ($sort !== null) {
            $sign = $direction === 'desc' ? -1 : 1;

            /* Blank cells stay at the bottom whichever way the column is sorted. */
            $visible = $visible->sort(function (array $a, array $b) use ($sort, $sign): int {
                $left = $a['_sort_'.$sort] ?? ($a[$sort] ?? null);
                $right = $b['_sort_'.$sort] ?? ($b[$sort] ?? null);
                $left = self::blank($left) ? null : (string) $left;
                $right = self::blank($right) ? null : (string) $right;

                if ($left === null || $right === null) {
                    return ($left === null) <=> ($right === null);
                }

                return $sign * self::compare($left, $right);
            })->values();
        }

        $lastPage = max(1, (int) ceil($visible->count() / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $lastPage);

        $scopeQuery = array_diff_key($scopeQuery, array_flip(self::VIEW_KEYS));

        $table = new self($columns, new LengthAwarePaginator([], 0, $perPage), $rows->count(), $search, $sort, $direction, $perPage, $path, $scopeQuery);

        $records = new LengthAwarePaginator(
            $visible->forPage($page, $perPage)->values(),
            $visible->count(),
            $perPage,
            $page,
            ['path' => $path, 'query' => $table->state()]
        );

        return new self($columns, $records, $rows->count(), $search, $sort, $direction, $perPage, $path, $scopeQuery);
    }

    /** Whether the search box is narrowing the records on screen. */
    public function isSearching(): bool
    {
        return $this->search !== '';
    }

    /**
     * True when at least one shown row offers a record drilldown, or had one
     * withheld because the viewer may not open that record.
     */
    public function hasRecordLinks(): bool
    {
        return collect($this->records->items())->contains(
            static fn (array $row): bool => filled($row['_link'] ?? null) || ($row['_link_restricted'] ?? false)
        );
    }

    /** Scope + table state, for hidden inputs and links that keep the view. */
    public function state(array $overrides = []): array
    {
        $state = array_merge($this->scopeQuery, [
            'search' => $this->search !== '' ? $this->search : null,
            'sort' => $this->sort,
            'direction' => $this->sort !== null ? $this->direction : null,
            'per_page' => $this->perPage !== self::DEFAULT_PER_PAGE ? $this->perPage : null,
        ], $overrides);

        return array_filter($state, static fn ($value): bool => $value !== null && $value !== '');
    }

    /** Report scope only — what Print and Export must receive. */
    public function scopeQuery(): array
    {
        return $this->scopeQuery;
    }

    /** The page the table lives on, without a query string. */
    public function path(): string
    {
        return $this->path;
    }

    public function url(array $overrides = []): string
    {
        $query = http_build_query($this->state($overrides));

        return $this->path.($query !== '' ? '?'.$query : '');
    }

    /** Clicking a header sorts ascending, then descending, back on page one. */
    public function sortUrl(string $key): string
    {
        $direction = $this->sort === $key && $this->direction === 'asc' ? 'desc' : 'asc';

        return $this->url(['sort' => $key, 'direction' => $direction, 'page' => null]);
    }

    /** aria-sort value for a header. */
    public function ariaSort(string $key): string
    {
        if ($this->sort !== $key) {
            return 'none';
        }

        return $this->direction === 'desc' ? 'descending' : 'ascending';
    }

    /**
     * Report builders tone a cell with `_tone_<key>`; map that onto the
     * application's status badge palette.
     */
    public static function badgeTone(?string $tone): ?string
    {
        return match ($tone) {
            null, '' => null,
            'positive', 'success' => 'success',
            'critical', 'danger' => 'danger',
            'attention', 'warning' => 'warning',
            'progress', 'info' => 'info',
            default => 'neutral',
        };
    }

    private static function blank(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value === '' || $value === '—' || $value === '-';
    }

    /** Numbers as numbers, report dates as dates, everything else naturally. */
    private static function compare(string $left, string $right): int
    {
        $leftNumber = ReportSummaryFormatter::numeric($left);
        $rightNumber = ReportSummaryFormatter::numeric($right);

        if ($leftNumber !== null && $rightNumber !== null) {
            return $leftNumber <=> $rightNumber;
        }

        $leftDate = self::date($left);
        $rightDate = self::date($right);

        if ($leftDate !== null && $rightDate !== null) {
            return $leftDate <=> $rightDate;
        }

        return strnatcasecmp($left, $right);
    }

    private static function date(string $value): ?int
    {
        foreach (['!d/m/Y, g:i A', '!d/m/Y', '!H:i:s', '!d M Y, g:i A', '!d M Y', '!Y-m-d H:i:s', '!Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);

            if ($date !== false && $date->format(ltrim($format, '!')) === $value) {
                return $date->getTimestamp();
            }
        }

        return null;
    }
}
