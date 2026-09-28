<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Sends a dashboard-bell notification to every admin who manages the given
 * grade level (same canManageGrade() rule the admin routes enforce), plus
 * every superadmin. Never throws: a failed notification must not undo the
 * enrollment or payment that triggered it.
 */
class AdminNotifier
{
    public static function send(Notification $notification, ?string $gradeLevel): void
    {
        try {
            $admins = User::whereIn('role', ['admin', 'superadmin'])
                ->get()
                ->filter(fn (User $admin) => $admin->isSuperAdmin() || $admin->canManageGrade($gradeLevel));

            NotificationFacade::send($admins, $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
