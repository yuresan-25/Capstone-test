<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /admin/notifications
     * Latest 20 notifications for the bell dropdown, plus the unread count
     * for the badge. Uses Laravel's built-in database notifications table
     * (Notifiable trait on User already provides notifications()/
     * unreadNotifications()).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()->latest()->take(20)->get();

        // Receipt images are re-resolved from the proof itself on every load
        // instead of trusting the full URL saved when the notification was
        // created — that URL breaks if the site's address changes (localhost
        // vs. XAMPP subfolder vs. Railway) or the proof was later replaced.
        $proofIds = $notifications->filter(fn ($n) => ($n->data['type'] ?? null) === 'payment_proof_submitted')
            ->pluck('data.payment_id')->filter()->unique();
        $proofs = \App\Models\TuitionPaymentProof::whereIn('id', $proofIds)->pluck('proof_of_payment', 'id');

        // Older notifications (from before one installment could hold several
        // receipts) stored the installment's id, not a proof id — for those,
        // rebuild the URL from the file path inside the saved URL instead.
        $receiptUrl = function (array $data) use ($proofs): ?string {
            $path = $proofs[$data['payment_id'] ?? 0] ?? null;
            if (! $path && ! empty($data['proof_of_payment']) && str_contains($data['proof_of_payment'], '/storage/')) {
                $path = rawurldecode(\Illuminate\Support\Str::after(parse_url($data['proof_of_payment'], PHP_URL_PATH), '/storage/'));
            }

            return $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($path) ? asset('storage/' . $path) : null;
        };

        return response()->json([
            'notifications' => $notifications->map(fn ($n) => [
                'id'         => $n->id,
                'data'       => ($n->data['type'] ?? null) === 'payment_proof_submitted'
                    ? array_merge($n->data, ['proof_of_payment' => $receiptUrl($n->data)])
                    : $n->data,
                'read'       => $n->read_at !== null,
                'created_at' => $n->created_at->diffForHumans(),
            ]),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * POST /admin/notifications/{notification}/read
     * Marks a single notification read (called when the admin clicks it).
     */
    public function markRead(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * POST /admin/notifications/read-all
     * "Mark all as read" — clears the badge without opening each one.
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}