<?php

namespace App\Http\Controllers;

use App\Models\HrisNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $status = (string) $request->query('status', 'all');

        $query = HrisNotification::query()
            ->where('recipient_user_id', $user->id)
            ->visible()
            ->latest('created_at');

        if ($status === 'unread') {
            $query->unread();
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($request->filled('module')) {
            $query->where('module', (string) $request->query('module'));
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'notifications' => collect($page->items())->map(fn (HrisNotification $notification) => $this->serialize($notification))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'unread_count' => $this->notifications->unreadCount($user),
            'module_counts' => $this->notifications->moduleCounts($user),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    public function moduleCounts(Request $request): JsonResponse
    {
        return response()->json($this->notifications->moduleCounts($request->user()));
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->findForUser($request, $id);
        $this->notifications->markRead($notification);

        return response()->json([
            'notification' => $this->serialize($notification->refresh()),
            'unread_count' => $this->notifications->unreadCount($request->user()),
            'module_counts' => $this->notifications->moduleCounts($request->user()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->notifications->markAllRead(
            $request->user(),
            $request->filled('module') ? (string) $request->input('module') : null,
        );

        return response()->json([
            'updated' => $updated,
            'unread_count' => 0,
            'module_counts' => $this->notifications->moduleCounts($request->user()),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = $this->findForUser($request, $id);
        $this->notifications->dismiss($notification);

        return response()->json([
            'dismissed' => true,
            'unread_count' => $this->notifications->unreadCount($request->user()),
            'module_counts' => $this->notifications->moduleCounts($request->user()),
        ]);
    }

    private function findForUser(Request $request, string $id): HrisNotification
    {
        return HrisNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->visible()
            ->whereKey($id)
            ->firstOrFail();
    }

    private function serialize(HrisNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'module' => $notification->module,
            'entity_id' => $notification->entity_id,
            'entity_type' => $notification->entity_type,
            'action_url' => $notification->action_url,
            'recipient_user_id' => $notification->recipient_user_id,
            'recipient_role' => $notification->recipient_role,
            'company_id' => $notification->company_id,
            'department_id' => $notification->department_id,
            'priority' => $notification->priority,
            'read_at' => optional($notification->read_at)->toISOString(),
            'created_at' => optional($notification->created_at)->toISOString(),
            'data' => $notification->data ?? [],
        ];
    }
}
