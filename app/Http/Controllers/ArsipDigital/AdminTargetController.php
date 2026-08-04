<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\Users\DosenView;
use App\Models\Users\MahasiswaView;
use App\Models\Users\User;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminTargetController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);

            $filters = $request->validate([
                'role' => ['required', 'in:mahasiswa,dosen'],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'angkatan' => ['sometimes', 'array', 'max:20'],
                'angkatan.*' => ['integer'],
                'status' => ['sometimes', 'array', 'max:20'],
                'status.*' => ['string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
                'has_account' => ['sometimes', 'boolean'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $targets = $filters['role'] === 'mahasiswa'
                ? $this->mahasiswaTargets($filters)
                : $this->dosenTargets($filters);

            return $this->successfulResponseJSON([
                'targets' => $targets->items(),
                'meta' => [
                    'current_page' => $targets->currentPage(),
                    'last_page' => $targets->lastPage(),
                    'per_page' => $targets->perPage(),
                    'total' => $targets->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function mahasiswaTargets(array $filters)
    {
        $query = MahasiswaView::query()
            ->select(['nim', 'nm_mhs', 'masuk_tahun', 'sts_mhs'])
            ->whereNotNull('nim');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('nim', 'ilike', $search)
                    ->orWhere('nm_mhs', 'ilike', $search);
            });
        }

        if (! empty($filters['angkatan'])) {
            $query->whereIn('masuk_tahun', $filters['angkatan']);
        }

        $this->applyStatusFilter($query, $filters['status'] ?? [], 'sts_mhs');

        if (array_key_exists('has_account', $filters)) {
            $accountIdentifiers = $this->accountIdentifiers('MHS-');
            $expected = filter_var($filters['has_account'], FILTER_VALIDATE_BOOL);
            $expected ? $query->whereIn('nim', $accountIdentifiers) : $query->whereNotIn('nim', $accountIdentifiers);
        }

        $targets = $query->orderByDesc('masuk_tahun')
            ->orderBy('nim')
            ->paginate($filters['per_page'] ?? 25);

        $accounts = $this->accountMapForIdentifiers('MHS-', collect($targets->items())->pluck('nim'));

        return $targets->through(function ($item) use ($accounts): array {
            $identifier = trim((string) $item->nim);
            $accountKey = 'MHS-'.$identifier;

            return [
                'role' => 'mahasiswa',
                'identifier' => $identifier,
                'name' => trim((string) $item->nm_mhs),
                'angkatan' => $item->masuk_tahun,
                'status' => trim((string) $item->sts_mhs),
                'has_account' => $accounts->has($accountKey),
                'user_id' => $accounts->get($accountKey),
            ];
        });
    }

    private function dosenTargets(array $filters)
    {
        $query = DosenView::query()
            ->select(['kd_dosen', 'nm_dosen', 'sts_dosen'])
            ->whereNotNull('kd_dosen');

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('kd_dosen', 'ilike', $search)
                    ->orWhere('nm_dosen', 'ilike', $search);
            });
        }

        $this->applyStatusFilter($query, $filters['status'] ?? [], 'sts_dosen');

        if (array_key_exists('has_account', $filters)) {
            $accountIdentifiers = $this->accountIdentifiers('DSN-');
            $expected = filter_var($filters['has_account'], FILTER_VALIDATE_BOOL);
            $expected ? $query->whereIn('kd_dosen', $accountIdentifiers) : $query->whereNotIn('kd_dosen', $accountIdentifiers);
        }

        $targets = $query->orderBy('kd_dosen')
            ->paginate($filters['per_page'] ?? 25);

        $accounts = $this->accountMapForIdentifiers('DSN-', collect($targets->items())->pluck('kd_dosen'));

        return $targets->through(function ($item) use ($accounts): array {
            $identifier = trim((string) $item->kd_dosen);
            $accountKey = 'DSN-'.$identifier;

            return [
                'role' => 'dosen',
                'identifier' => $identifier,
                'name' => trim((string) $item->nm_dosen),
                'angkatan' => null,
                'status' => trim((string) $item->sts_dosen),
                'has_account' => $accounts->has($accountKey),
                'user_id' => $accounts->get($accountKey),
            ];
        });
    }

    private function applyStatusFilter(Builder $query, array $statuses, string $column): void
    {
        $statuses = array_values(array_unique(array_map(fn ($status): string => trim((string) $status), $statuses)));
        if ($statuses === [] || (in_array('active', $statuses, true) && in_array('inactive', $statuses, true))) {
            return;
        }

        if ($statuses === ['active']) {
            $query->where($column, 'A');

            return;
        }

        if ($statuses === ['inactive']) {
            $query->where(function (Builder $query) use ($column): void {
                $query->whereNull($column)->orWhere($column, '!=', 'A');
            });

            return;
        }

        $query->whereIn($column, array_map('strtoupper', $statuses));
    }

    private function accountIdentifiers(string $prefix)
    {
        return User::where('kd_user', 'like', $prefix.'%')
            ->pluck('kd_user')
            ->map(fn (string $kdUser): string => substr($kdUser, strlen($prefix)))
            ->all();
    }

    private function accountMapForIdentifiers(string $prefix, $identifiers)
    {
        return User::whereIn('kd_user', collect($identifiers)->map(fn ($identifier): string => $prefix.trim((string) $identifier)))
            ->pluck('id', 'kd_user');
    }
}
