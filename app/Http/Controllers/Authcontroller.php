<?php

namespace App\Http\Controllers;

use App\Models\Parents;
use App\Models\User;
use App\Rules\Recaptcha;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class Authcontroller extends Controller
{
    public function landingpage()
    {
        return view('index');
    }

    public function login(Request $request)
    {
        $activeTab = old('role') === 'admin' || $request->query('tab') === 'admin' ? 'admin' : 'parent';
        return view('auth.login', compact('activeTab'));
    }

    public function admission()
    {
        return view('auth.admission');
    }

    public function studentportal()
    {
        $user     = Auth::guard('parent')->user();
        $fullName = $user->first_name . ' ' . $user->last_name;
        $initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));
        $popupAnnouncement = \App\Models\Announcement::currentPopup();

        return view('parent.dashboard', compact('user', 'fullName', 'initials', 'popupAnnouncement'));
    }

    public function adminportal()
    {
      $user     = Auth::guard('web')->user();
    $fullName = $user->first_name . ' ' . $user->last_name;
    $initials = strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1));

    if ($user->role === 'superadmin') {
        return view('super_admin', compact('user', 'fullName', 'initials'));
    }

    return view('admin', compact('user', 'fullName', 'initials'));
    }

    public function logout(Request $request)
    {
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        if (Auth::guard('parent')->check()) {
            Auth::guard('parent')->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been logged out.');
    }

    public function showForgotPasswordForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'email'                => 'required|email',
            'g-recaptcha-response' => ['required', new Recaptcha],
        ]);

        $email   = $request->input('email');
        $account = $this->findAccountByEmail($email);

        if ($account) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('password_reset_tokens')->insert([
                'email'      => $email,
                'token'      => Hash::make($token),
                'created_at' => now(),
            ]);

            $resetUrl = route('password.reset', $token) . '?email=' . urlencode($email);

            Mail::raw(
                "You requested a password reset for your PHLCI account.\n\n"
                . "Click the link below to choose a new password (valid for 60 minutes):\n{$resetUrl}\n\n"
                . "If you did not request this, you can safely ignore this email.",
                function ($message) use ($email) {
                    $message->to($email)->subject('Reset Your PHLCI Password');
                }
            );
        }

        // Same response whether or not the email exists — prevents user
        // enumeration via this form.
        return back()->with('success', 'If an account exists for that email, a password reset link has been sent.');
    }

    public function showResetPasswordForm(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (! $record || ! Hash::check($request->token, $record->token)) {
            return back()->withErrors(['email' => 'This password reset link is invalid.'])->withInput();
        }

        if (now()->diffInMinutes(\Carbon\Carbon::parse($record->created_at)) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return back()->withErrors(['email' => 'This password reset link has expired. Please request a new one.'])->withInput();
        }

        $account = $this->findAccountByEmail($request->email);

        if (! $account) {
            return back()->withErrors(['email' => 'We could not find an account for that email.'])->withInput();
        }

        if (Hash::check($request->password, $account->password)) {
            return back()
                ->withErrors(['password' => 'Your new password must be different from your current password.'])
                ->withInput();
        }

        $account->password = Hash::make($request->password);
        $account->save();

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return redirect()->route('login')->with('success', 'Your password has been reset. You can now log in.');
    }

    /**
     * Looks up an account by email across both auth tables (parents and
     * admin/superadmin users) since this app has two separate guards
     * sharing one password-reset flow.
     */
    private function findAccountByEmail(string $email)
    {
        return Parents::where('email', $email)->first()
            ?? User::where('email', $email)->first();
    }

    public function Showregister()
    {
        return view('auth.admission');
    }

    public function register(Request $request)
    {
        $request->validate([
            'first_name'          => 'required|string|max:255',
            'last_name'           => 'required|string|max:255',
            'email'               => 'required|string|email|max:255|unique:parents,email',
            'password'            => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'g-recaptcha-response' => ['required', new Recaptcha],
             'terms_accepted' => ['required', 'accepted'], 
        ]);

        $parent = Parents::create([
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'role'       => 'parent',
            'terms_accepted'    => true,
            'terms_accepted_at' => now(),
            'terms_version'     => config('app.terms_version', '1.0'),
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        Auth::guard('parent')->login($parent);
        $request->session()->regenerate();

        return redirect('/parent')->with('success', 'Registration successful! Welcome, ' . $parent->first_name . '!');
    }

    public function loginUser(Request $request)
    {
        $request->validate([
            'email'                => 'required|string|email',
            'password'             => 'required|string',
            'g-recaptcha-response' => ['required', new Recaptcha],
        ]);

        $credentials = $request->only('email', 'password');
        $role        = $request->input('role');

        if ($role === 'admin') {
            if (Auth::guard('web')->attempt($credentials)) {
                $user = Auth::guard('web')->user();

                if (in_array($user->role, ['admin', 'superadmin'])) {
                    $request->session()->regenerate();
                    $user->update(['last_login_at' => now()]);
                    return redirect('/admin')
                        ->with('success', 'Welcome back, ' . $user->first_name . '!');
                }

                Auth::guard('web')->logout();
                return back()
                    ->with('error', 'You do not have permission to access the admin panel.')
                    ->withInput($request->only('email', 'role')); // ← role included
            }

            return back()
                ->withErrors(['email' => 'Invalid admin credentials. Please try again.'])
                ->withInput($request->only('email', 'role')); // ← role included
        }

        // Parent tab: parent accounts only. There's deliberately no fallback
        // to the admin guard here — admin and superadmin accounts must sign
        // in through the Admin tab. The error stays generic so this form
        // can't be used to check whether an email belongs to an admin.
        if (Auth::guard('parent')->attempt($credentials)) {
            $request->session()->regenerate();
            $user = Auth::guard('parent')->user();

            return redirect('/parent')
                ->with('success', 'Welcome back, ' . $user->first_name . '!');
        }

        return back()
            ->withErrors(['email' => 'The provided credentials do not match our records.'])
            ->withInput($request->only('email'));
    }

    public function updatePassword(Request $request)
    {
        $parent = Auth::guard('parent')->user();

        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        if (!Hash::check($request->current_password, $parent->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current password is incorrect.',
            ], 422);
        }

        if (Hash::check($request->new_password, $parent->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your new password must be different from your current password.',
            ], 422);
        }

        $parent->password = Hash::make($request->new_password);
        $parent->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    public function uploadProfilePic(Request $request)
    {
        $request->validate([
            'profile_pic' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $parent = Auth::guard('parent')->user();

        if ($parent->profile_pic && Storage::disk('public')->exists($parent->profile_pic)) {
            Storage::disk('public')->delete($parent->profile_pic);
        }

        $path = \App\Support\ImageUploadStorer::store($request->file('profile_pic'), 'profile-pictures', 'public', maxWidth: 500);

        $parent->profile_pic = $path;
        $parent->save();

        return response()->json([
    'success' => true,
    'message' => 'Profile picture updated successfully.',
    'url'     => asset('storage/' . $path),
]);
    }

    public function removeProfilePic(Request $request)
    {
        $parent = Auth::guard('parent')->user();

        if ($parent->profile_pic && Storage::disk('public')->exists($parent->profile_pic)) {
            Storage::disk('public')->delete($parent->profile_pic);
        }

        $parent->profile_pic = null;
        $parent->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile picture removed.',
        ]);
    }
}