<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ConsoleNotification;
use App\Models\Reseller;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsoleNotificationController extends Controller
{
    public function index(Request $request, string $audience): JsonResponse
    {
        [$type, $id] = $this->recipient($request, $audience);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $query = ConsoleNotification::query()->where('recipient_type', $type)->where('recipient_id', $id)->latest();
        return response()->json(['unread_count' => (clone $query)->whereNull('read_at')->count(), 'notifications' => $query->paginate($perPage)]);
    }

    public function read(Request $request, string $audience, ConsoleNotification $notification): JsonResponse
    {
        [$type, $id] = $this->recipient($request, $audience);
        abort_unless($notification->recipient_type === $type && (int) $notification->recipient_id === $id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);
        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function readAll(Request $request, string $audience): JsonResponse
    {
        [$type, $id] = $this->recipient($request, $audience);
        ConsoleNotification::where('recipient_type', $type)->where('recipient_id', $id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function clear(Request $request, string $audience): JsonResponse
    {
        [$type, $id] = $this->recipient($request, $audience);
        ConsoleNotification::where('recipient_type', $type)->where('recipient_id', $id)->delete();
        return response()->json(['message' => 'Notifications cleared.']);
    }

    private function recipient(Request $request, string $audience): array
    {
        $actor = $request->user();
        return match ($audience) {
            'admin' => $actor instanceof Admin ? ['admin', $actor->id] : abort(403),
            'seller' => $actor instanceof Seller ? ['seller', $actor->id] : abort(403),
            'reseller' => $actor instanceof Reseller ? ['reseller', $actor->id] : abort(403),
            default => abort(404),
        };
    }
}
