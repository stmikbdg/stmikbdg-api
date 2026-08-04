<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\NotificationService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, NotificationService $notifications)
    {
        try {
            $role = $roleResolver->resolve($request);
            $filters = $request->validate([
                'unread_only' => ['sometimes', 'in:true,false,1,0'],
                'type' => ['sometimes', 'string', 'max:100'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            ]);

            $page = (int) ($filters['page'] ?? 1);
            $perPage = (int) ($filters['per_page'] ?? 15);
            $paginator = $notifications->queryForUser(auth()->user(), $role, [
                'type' => $filters['type'] ?? null,
                'unread' => isset($filters['unread_only']) ? filter_var($filters['unread_only'], FILTER_VALIDATE_BOOL) : null,
            ])->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'notifications' => $paginator->getCollection()->values()->toArray(),
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function unreadCount(Request $request, RoleResolverService $roleResolver, NotificationService $notifications)
    {
        try {
            $role = $roleResolver->resolve($request);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'unread_count' => $notifications->unreadCount(auth()->user(), $role),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function markRead(Request $request, int $notification_id, RoleResolverService $roleResolver, NotificationService $notifications)
    {
        try {
            $role = $roleResolver->resolve($request);
            $notification = $notifications->markRead(auth()->user(), $role, $notification_id);

            if (! $notification) {
                abort(404, 'Notifikasi tidak ditemukan.');
            }

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'notification' => $notification->toArray(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function markAllRead(Request $request, RoleResolverService $roleResolver, NotificationService $notifications)
    {
        try {
            $role = $roleResolver->resolve($request);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => [
                    'updated_count' => $notifications->markAllRead(auth()->user(), $role),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
