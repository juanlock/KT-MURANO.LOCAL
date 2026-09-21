<?php

namespace App\Http\Controllers;

use App\Mail\StoreVerifyEmail;
use App\Models\Client;
use App\Models\EcommerceClient;
use App\Models\InviteCode;
use App\Models\StoreSetting;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAuthController extends BaseController
{
    /**
     * Only allow same-origin path redirects.
     */
    protected function safeRedirect(Request $request): string
    {
        $redir = (string) $request->input('redirect', '');
        if ($redir && Str::startsWith($redir, ['/']) && ! Str::startsWith($redir, ['//'])) {
            return $redir;
        }

        if ($intended = (string) session('url.intended')) {
            if (Str::startsWith($intended, ['/']) && ! Str::startsWith($intended, ['//'])) {
                return $intended;
            }
        }

        return route('checkout');
    }

    /** GET /store/login */
    public function showLogin(Request $request)
    {
        $s = StoreSetting::first();
        $redirect = $this->safeRedirect($request);

        return view('store.auth.login', compact('s', 'redirect'));
    }

    /** POST /store/login */
    public function login(Request $request)
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'redirect' => ['nullable', 'string'],
        ]);

        $s = StoreSetting::first();
        $ecomClient = EcommerceClient::where('email', $cred['email'])->first();

        if ($ecomClient && Hash::check($cred['password'], $ecomClient->password)) {
            if ($ecomClient->is_blocked) {
                return back()
                    ->withErrors(['email' => __('messages.AccountBlockedMessage')])
                    ->onlyInput('email');
            }

            if (($s->require_email_verification ?? true) && ! $ecomClient->email_verified_at) {
                return back()
                    ->withErrors(['email' => __('messages.VerifyEmailRequired')])
                    ->with('unverified_email', $ecomClient->email)
                    ->onlyInput('email');
            }

            if ((int) $ecomClient->status === 0) {
                return back()
                    ->withErrors(['email' => __('messages.AccountPendingApproval')])
                    ->onlyInput('email');
            }
        }

        $attempt = [
            'email' => $cred['email'],
            'password' => $cred['password'],
            'status' => 1,
            'is_blocked' => 0,
        ];

        if (Auth::guard('store')->attempt($attempt, (bool) ($cred['remember'] ?? false))) {
            $request->session()->regenerate();

            return redirect()->to($this->safeRedirect($request));
        }

        return back()
            ->withErrors(['email' => __('Invalid credentials')])
            ->onlyInput('email');
    }

    /** GET /store/register */
    public function showRegister(Request $request)
    {
        $s = StoreSetting::first();
        $redirect = $this->safeRedirect($request);

        $registrationEnabled = $s->registration_enabled ?? true;
        $requireInviteCode = $s->require_invite_code ?? false;

        return view('store.auth.register', compact(
            's', 'redirect', 'registrationEnabled', 'requireInviteCode'
        ));
    }

    /** POST /store/register */
    public function register(Request $request)
    {
        $s = StoreSetting::first();

        if (! ($s->registration_enabled ?? true)) {
            return back()->withErrors(['registration' => __('messages.RegistrationDisabled')]);
        }

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                Rule::unique('clients', 'email')->whereNull('deleted_at'),
                Rule::unique('ecommerce_clients', 'email')->whereNull('deleted_at'),
            ],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:190'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
            'terms' => ['accepted'],
        ];

        if ($s->require_invite_code ?? false) {
            $rules['invite_code'] = ['required', 'string', 'max:64'];
        }

        $data = $request->validate($rules, [
            'terms.accepted' => __('messages.TermsRequired'),
        ]);

        $inviteCode = null;
        if ($s->require_invite_code ?? false) {
            $inviteCode = InviteCode::where('code', $data['invite_code'])->first();

            if (! $inviteCode || ! $inviteCode->isValid()) {
                return back()
                    ->withErrors(['invite_code' => __('messages.InvalidInviteCode')])
                    ->withInput($request->except('password', 'password_confirmation'));
            }
        }

        $requireVerification = $s->require_email_verification ?? true;
        $requireApproval = $s->require_admin_approval ?? false;
        $initialStatus = $requireApproval ? 0 : 1;

        $user = DB::transaction(function () use ($data, $initialStatus, $inviteCode, $requireVerification) {
            $client = Client::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'code' => $this->getNumberOrder(),
                    'phone' => $data['phone'],
                    'adresse' => $data['address'],
                ]
            );

            $ecomClient = EcommerceClient::create([
                'client_id' => $client->id,
                'username' => Str::slug(Str::before($data['email'], '@')),
                'email' => $data['email'],
                'status' => $initialStatus,
                'password' => Hash::make($data['password']),
                'invite_code_id' => $inviteCode?->id,
                'email_verified_at' => $requireVerification ? null : now(),
                'terms_accepted_at' => now(),
            ]);

            if ($inviteCode) {
                $inviteCode->redeem();
            }

            return $ecomClient;
        });

        if ($requireVerification) {
            $this->sendVerificationEmail($user, $s);

            return redirect()->route('store.login.show', ['redirect' => $this->safeRedirect($request)])
                ->with('verify_email_sent', true);
        }

        if ($requireApproval) {
            return redirect()->route('store.login.show', ['redirect' => $this->safeRedirect($request)])
                ->with('pending_approval', true);
        }

        Auth::guard('store')->login($user);
        $request->session()->regenerate();

        return redirect()->to($this->safeRedirect($request));
    }

    /** GET /store/email/verify/{id}/{hash} (signed) */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = EcommerceClient::find($id);

        if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->email), (string) $hash)) {
            return redirect()->route('store.login.show')
                ->withErrors(['email' => __('messages.VerifyEmailInvalidLink')]);
        }

        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        $s = StoreSetting::first();

        if ((int) $user->status === 0 && ($s->require_admin_approval ?? false)) {
            return redirect()->route('store.login.show')
                ->with('email_verified', true)
                ->with('pending_approval', true);
        }

        return redirect()->route('store.login.show')->with('email_verified', true);
    }

    /** POST /store/email/resend */
    public function resendVerification(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = EcommerceClient::where('email', $data['email'])
            ->whereNull('email_verified_at')
            ->first();

        if ($user) {
            $this->sendVerificationEmail($user, StoreSetting::first());
        }

        // Same response whether or not the account exists, to avoid leaking emails.
        return redirect()->route('store.login.show')->with('verify_email_sent', true);
    }

    /**
     * Send the signed verification link; failures are logged, never thrown,
     * so registration is not rolled back by SMTP issues.
     */
    protected function sendVerificationEmail(EcommerceClient $user, ?StoreSetting $s = null): bool
    {
        try {
            $this->Set_config_mail();

            $url = URL::temporarySignedRoute(
                'store.verification.verify',
                now()->addHours(24),
                ['id' => $user->id, 'hash' => sha1($user->email)]
            );

            Mail::to($user->email)->send(new StoreVerifyEmail($url, $s->store_name ?? null));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Store verification email failed: '.$e->getMessage());

            return false;
        }
    }

    /** GET /store/forgot-password */
    public function showForgotPassword(Request $request)
    {
        $s = StoreSetting::first();

        return view('store.auth.forgot-password', compact('s'));
    }

    /** POST /store/forgot-password — email a reset link (no account enumeration). */
    public function sendResetLink(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = $data['email'];

        // Only issue a link for a real, verified, non-blocked account — but the
        // response is identical regardless, so we never reveal whether it exists.
        $user = EcommerceClient::where('email', $email)->first();
        if ($user && ! $user->is_blocked && $user->email_verified_at) {
            $token = Str::random(64);

            DB::table('store_password_resets')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $this->sendResetEmail($email, $token, StoreSetting::first());
        }

        return back()->with('reset_link_sent', true);
    }

    /** GET /store/reset-password/{token} */
    public function showResetForm(Request $request, $token)
    {
        $s = StoreSetting::first();
        $email = (string) $request->query('email', '');

        return view('store.auth.reset-password', compact('s', 'token', 'email'));
    }

    /** POST /store/reset-password */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $row = DB::table('store_password_resets')->where('email', $data['email'])->first();

        if (! $row || ! Hash::check($data['token'], $row->token)) {
            return back()->withErrors(['email' => __('messages.ResetLinkInvalid')]);
        }

        // Tokens expire after 60 minutes.
        if (\Carbon\Carbon::parse($row->created_at)->addMinutes(60)->isPast()) {
            DB::table('store_password_resets')->where('email', $data['email'])->delete();

            return back()->withErrors(['email' => __('messages.ResetLinkExpired')]);
        }

        $user = EcommerceClient::where('email', $data['email'])->first();
        if (! $user) {
            return back()->withErrors(['email' => __('messages.ResetLinkInvalid')]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        DB::table('store_password_resets')->where('email', $data['email'])->delete();

        return redirect()->route('store.login.show')->with('password_reset_done', true);
    }

    /** Best-effort reset email; failures are logged, never thrown. */
    protected function sendResetEmail(string $email, string $token, ?StoreSetting $s = null): bool
    {
        try {
            (new \App\Http\Controllers\BaseController)->Set_config_mail();

            $url = route('store.password.reset', ['token' => $token]).'?email='.urlencode($email);

            Mail::to($email)->send(new \App\Mail\StorePasswordReset($url, $s->store_name ?? null));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Store password reset email failed: '.$e->getMessage());

            return false;
        }
    }

    /** POST /store/logout */
    public function logout(Request $request)
    {
        Auth::guard('store')->logout();

        $request->session()->forget('guard_store');
        $request->session()->regenerateToken();

        return redirect()->route('store.login.show');
    }

    public function getNumberOrder()
    {
        $last = DB::table('clients')->latest('id')->first();

        if ($last) {
            $code = $last->code + 1;
        } else {
            $code = 1;
        }

        return $code;
    }
}
