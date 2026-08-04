<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\AuditLog;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminAuditLogController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver)
    {
        try {
            $roleResolver->resolve($request, ['admin']);

            $filters = $request->validate([
                'action' => ['sometimes', 'nullable', 'string', 'max:120'],
                'entity_type' => ['sometimes', 'nullable', 'string', 'max:120'],
                'entity_id' => ['sometimes', 'nullable', 'string', 'max:120'],
                'actor_role' => ['sometimes', 'nullable', 'string', 'max:80'],
                'actor_user_id' => ['sometimes', 'nullable', 'integer'],
                'date_from' => ['sometimes', 'nullable', 'date'],
                'date_to' => ['sometimes', 'nullable', 'date'],
                'search' => ['nullable', 'string', 'max:255'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $query = AuditLog::query();

            foreach (['action', 'entity_type', 'entity_id', 'actor_role'] as $field) {
                if (! empty($filters[$field])) {
                    $query->where($field, 'ilike', '%'.$filters[$field].'%');
                }
            }

            if (! empty($filters['actor_user_id'])) {
                $query->where('actor_user_id', $filters['actor_user_id']);
            }

            if (! empty($filters['search'])) {
                $search = '%'.strtolower($filters['search']).'%';
                $query->where(function ($query) use ($search): void {
                    $query->whereRaw('LOWER(action) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(entity_type) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(CAST(entity_id AS TEXT)) LIKE ?', [$search]);
                });
            }

            if (! empty($filters['date_from'])) {
                $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
            }

            if (! empty($filters['date_to'])) {
                $query->where('created_at', '<', Carbon::parse($filters['date_to'])->startOfDay()->addDay());
            }

            $logs = $query->orderByDesc('created_at')
                ->orderByDesc('audit_log_id')
                ->paginate($filters['per_page'] ?? 25);

            return $this->successfulResponseJSON([
                'audit_logs' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
