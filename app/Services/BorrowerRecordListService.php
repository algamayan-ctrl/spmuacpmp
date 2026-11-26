<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\RequestVersion;
use App\Support\RequestRecordStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Both Borrower lists share ownership, search, status classification and pagination. */
class BorrowerRecordListService
{
    public const PER_PAGE_OPTIONS = [7, 15, 30, 50];

    private const STATUS_RELATIONS = [
        'custody.lines.requestItem.inventoryItem', 'custody.returns',
        'custody.laundryJob.latestEvidence.file', 'custody.incidents',
        'custody.gatePass', 'custody.overdueCase',
    ];

    public function query(int $borrowerId, bool $ongoing): Builder
    {
        $query = BorrowingRequest::query()->where('borrower_user_id', $borrowerId)
            ->with(['borrower.organizationalUnit', 'currentVersion.items', ...self::STATUS_RELATIONS]);
        if (Schema::hasTable('request_number_aliases')) {
            $query->with('numberAliases');
        }
        if (! $ongoing) {
            $query->where('status', '!=', RequestStatus::Draft);
        }

        return $query;
    }

    /** @return array{search:string,status:string,sort:string,per_page:int,active:bool} */
    public function filters(Request $request, bool $ongoing): array
    {
        $string = static function (string $key, string $default) use ($request): string {
            $value = $request->query($key, $default);

            return is_string($value) ? $value : $default;
        };
        $search = mb_substr(trim($string('search', '')), 0, 100);
        $options = $ongoing ? RequestRecordStatus::ONGOING_FILTERS : RequestRecordStatus::FILTERS;
        $status = strtoupper(trim($string('status', '')));
        $status = array_key_exists($status, $options) ? $status : 'all';
        $sort = $string('sort', 'newest');
        $sort = in_array($sort, ['newest', 'oldest'], true) ? $sort : 'newest';
        $perPage = (int) $string('per_page', '7');
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 7;

        return ['search' => $search, 'status' => $status, 'sort' => $sort, 'per_page' => $perPage,
            'active' => $search !== '' || $status !== 'all'];
    }

    public function apply(Builder $query, array $filters, bool $ongoing): void
    {
        $this->search($query, $filters['search']);
        $this->classify($query, $filters['status'], $ongoing);
        $direction = $filters['sort'] === 'oldest' ? 'asc' : 'desc';
        $submitted = RequestVersion::query()->select('submitted_at')
            ->whereColumn('request_versions.request_id', 'borrowing_requests.id')
            ->orderByDesc('version_no')->limit(1);
        $query->reorder()->orderByRaw(
            'COALESCE(('.$submitted->toSql().'), borrowing_requests.created_at) '.$direction,
            $submitted->getBindings()
        )->orderBy('borrowing_requests.id', $direction);
    }

    /** Use the same membership as My Requests, including unresolved cases on CLOSED custody rows. */
    public function ongoingCount(int $borrowerId): int
    {
        $query = $this->query($borrowerId, true);
        $this->classify($query, 'all', true);

        return $query->count();
    }

    private function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }
        $term = '%'.addcslashes($search, '%_\\').'%';
        $query->where(function ($match) use ($term): void {
            $match->where('borrowing_requests.request_no', 'like', $term)
                ->orWhereHas('custody', fn ($custody) => $custody->where('custody_no', 'like', $term))
                ->orWhereHas('currentVersion', fn ($version) => $version->where(fn ($field) => $field
                    ->where('purpose_event', 'like', $term)->orWhere('student_organization', 'like', $term)
                    ->orWhere('office_unit', 'like', $term)->orWhere('location', 'like', $term)
                    ->orWhere('external_company_name', 'like', $term)))
                ->orWhereHas('currentVersion.items', fn ($item) => $item->where('description_snapshot', 'like', $term));
            if (Schema::hasTable('request_number_aliases')) {
                $match->orWhereHas('numberAliases', fn ($alias) => $alias->where('alias', 'like', $term));
            }
        });
    }

    private function classify(Builder $query, string $status, bool $ongoing): void
    {
        if (! $ongoing && $status === 'all') {
            return;
        }
        // Classify before SQL pagination so counts, later pages and row badges agree.
        // Each candidate retains the original ownership, search and dashboard restrictions.
        $matching = [];
        (clone $query)->reorder()->setEagerLoads([])->with(self::STATUS_RELATIONS)
            ->lazyById(200, 'borrowing_requests.id', 'id')
            ->each(function (BorrowingRequest $record) use ($status, $ongoing, &$matching): void {
                if ((! $ongoing || RequestRecordStatus::isOngoing($record))
                    && ($status === 'all' || RequestRecordStatus::forRequest($record)['key'] === $status)) {
                    $matching[] = $record->id;
                }
            });
        $query->whereIn('borrowing_requests.id', $matching);
    }
}
