<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\SuperAdminSwitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SuperAdmin login chooser (user's request, 2026-10-20): right after a
 * SuperAdmin signs in (LoginController), "Go to the dashboard" or "Enter as the
 * admin of a company". Entering switches this browser's session to that admin
 * (like the POS PIN switch) and remembers the SuperAdmin (App\Support\SuperAdminSwitch)
 * for "Back to SuperAdmin" and the Network security exemption.
 *
 *  GET  /login/choose   the chooser (SuperAdmin only)
 *  POST /login/choose   {target: dashboard | user id}
 *  POST /login/back     admin session started by a SuperAdmin -> back to that SuperAdmin
 */
class LoginChooseController extends Controller
{
    public function show(Request $request)
    {
        $user = Auth::guard('web')->user();
        if (! $user || $user->type !== 'SuperAdmin') {
            return redirect('/');
        }

        $companies = DB::table('companies')->whereNull('deleted_at')->where('id', '!=', 999)->orderBy('name')->get(['id', 'name', 'logo', 'business_type']);
        $admins = User::whereIn('company_id', $companies->pluck('id'))->where('type', 'admin')
            ->whereNull('deleted_at')->where('statut', 1)->orderBy('id')
            ->get(['id', 'company_id', 'username', 'firstname', 'lastname', 'email'])->groupBy('company_id');
        $list = $companies->map(fn ($c) => (object) [
            'id' => $c->id,
            'name' => $c->name,
            'logo' => $this->logoUrl($c),
            'type' => \App\Support\BusinessTypes::LABELS[$c->business_type ?? ''] ?? null,
            'admins' => ($admins[$c->id] ?? collect())->map(fn ($u) => (object) [
                'id' => $u->id,
                'name' => trim(($u->firstname ?? '').' '.($u->lastname ?? '')) ?: $u->username,
                'email' => $u->email,
            ])->values(),
        ]);

        return response()->view('auth.choose-login', ['user' => $user, 'companies' => $list])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function enter(Request $request)
    {
        $super = Auth::guard('web')->user();
        abort_unless($super && $super->type === 'SuperAdmin', 403);
        $target = (string) $request->validate(['target' => 'required|string|max:20'])['target'];

        if ($target === 'dashboard') {
            SuperAdminSwitch::stop();

            return redirect()->intended('/');
        }

        $admin = User::whereKey((int) $target)->where('type', 'admin')->whereNull('deleted_at')->where('statut', 1)->first();
        abort_unless($admin && $admin->company_id && (int) $admin->company_id !== 999
            && DB::table('companies')->where('id', $admin->company_id)->whereNull('deleted_at')->exists(), 404);

        $this->switchTo($request, $admin);
        SuperAdminSwitch::start($super, $admin);
        Log::info('SuperAdmin entered as company admin', [
            'superadmin_id' => $super->id, 'superadmin' => $super->email,
            'admin_id' => $admin->id, 'admin' => $admin->email, 'company_id' => $admin->company_id, 'ip' => $request->ip(),
        ]);

        // The admin's own start page, not the SuperAdmin's pending "intended" URL.
        $request->session()->forget('url.intended');

        return redirect('/');
    }

    public function back(Request $request)
    {
        $current = Auth::guard('web')->user();
        $super = SuperAdminSwitch::superAdminFor($current, $request);
        if (! $super) {
            return $request->expectsJson() ? response()->json(['message' => 'Not working as a SuperAdmin.'], 403) : redirect('/');
        }
        $this->switchTo($request, $super);
        SuperAdminSwitch::stop();

        return $request->expectsJson() ? response()->json(['redirect' => url('/login/choose')]) : redirect('/login/choose');
    }

    /** This browser's session becomes $user's (new session id, tracked like a sign-in). */
    private function switchTo(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        try {
            UserLoginSession::query()->updateOrCreate(
                ['user_id' => (int) $user->id, 'session_id' => $request->session()->getId()],
                [
                    'access_token_id' => $request->session()->getId(),
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) ($request->userAgent() ?? ''),
                    'logged_in_at' => now(),
                    'last_activity_at' => now(),
                    'revoked_at' => null,
                ]
            );
        } catch (\Throwable $e) {
            // Tracking never blocks the switch.
        }
    }

    private function logoUrl(object $c): ?string
    {
        $logo = $c->logo ?: DB::table('settings')->where('company_id', $c->id)->whereNull('deleted_at')->value('logo');
        if (! $logo) {
            return null;
        }
        $path = str_starts_with($logo, 'files/') || str_starts_with($logo, 'images/') ? $logo : 'images/'.$logo;

        return is_file(public_path($path)) ? asset($path) : null;
    }
}
