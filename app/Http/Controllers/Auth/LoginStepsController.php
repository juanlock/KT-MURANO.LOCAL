<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LoginSecurity;
use App\Support\PasswordPolicy;
use App\Support\WebLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The extra steps of a staff sign-in (2026-10-27), after the right password and
 * before the session starts (App\Support\LoginSecurity::pendingSteps):
 *  - /login/verify   the two-step code (email, or the authenticator app / a recovery code)
 *  - /login/password a new password, when the one used is a standard password
 * The sign-in waits in the session (WebLogin::PENDING) for 15 minutes. Wrong codes
 * count as failed attempts (5 = the account is locked for an hour).
 */
class LoginStepsController extends Controller
{
    private const TTL = 900;

    public function showVerify(Request $request)
    {
        [$p, $user] = $this->pending($request);
        if (! $user) return $this->expired($request);
        if (($p['steps'][0] ?? null) !== '2fa') return $this->next($request, $p, $user);

        $method = LoginSecurity::method($user);
        if ($method === 'email' && (! $p['code'] || $p['code_exp'] < time())) {
            [$sent, $hash] = LoginSecurity::sendEmailCode($user, $request, 'web');
            $p = $this->put($request, ['code' => $hash, 'code_exp' => time() + LoginSecurity::CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => time()]);
            if (! $sent) {
                session()->now('step_error', LoginSecurity::es() ? 'No se pudo enviar el código a su email. Inténtelo de nuevo o avise a su administrador.' : 'The code could not be emailed. Try again or tell your administrator.');
            }
        }

        return response()->view('auth.login-step', ['mode' => 'verify', 'method' => $method, 'user' => $user, 'masked' => $this->mask((string) $user->email)])
            ->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request)
    {
        [$p, $user] = $this->pending($request);
        if (! $user) return $this->expired($request);
        $code = trim((string) $request->input('code'));
        $es = LoginSecurity::es();

        // app = the authenticator's code or a recovery code; email = the code we sent.
        $digits = preg_replace('/\s+/', '', $code);
        $ok = LoginSecurity::method($user) === 'app'
            ? LoginSecurity::verifyAppCode($user, $code)
            : ($p['code'] && $p['code_exp'] >= time() && preg_match('/^\d{6}$/', $digits) && Hash::check($digits, $p['code']));

        if (! $ok) {
            LoginSecurity::event($user, $user->email, 'web', 'code_failed', $request);
            $locked = LoginSecurity::recordFailure([$user->fresh()], (string) $user->email, $request, 'web');
            $tries = $p['tries'] + 1;
            if ($locked || $tries >= LoginSecurity::CODE_TRIES) {
                $request->session()->forget(WebLogin::PENDING);

                return redirect()->route('login')->with('erro_login', $locked ? LoginSecurity::lockedMessage($locked)
                    : ($es ? 'Demasiados códigos incorrectos. Ingrese de nuevo.' : 'Too many wrong codes. Sign in again.'));
            }
            $this->put($request, ['tries' => $tries]);

            return back()->withErrors(['code' => $es ? 'Código incorrecto o vencido.' : 'Wrong or expired code.']);
        }

        LoginSecurity::event($user, $user->email, 'web', 'code_ok', $request);
        $p = $this->put($request, ['steps' => array_values(array_diff($p['steps'], ['2fa'])), 'remember_device' => $request->boolean('remember_device'), 'code' => null]);

        return $this->next($request, $p, $user);
    }

    public function resend(Request $request)
    {
        [$p, $user] = $this->pending($request);
        if (! $user) return $this->expired($request);
        $es = LoginSecurity::es();
        if ($p['sent_at'] && time() - $p['sent_at'] < 60) {
            return back()->withErrors(['code' => $es ? 'Espere un minuto para pedir otro código.' : 'Wait a minute before asking for another code.']);
        }
        [$sent, $hash] = LoginSecurity::sendEmailCode($user, $request, 'web');
        $this->put($request, ['code' => $hash, 'code_exp' => time() + LoginSecurity::CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => time()]);

        return back()->with('step_info', $sent ? ($es ? 'Le enviamos un código nuevo.' : 'We sent you a new code.')
            : ($es ? 'No se pudo enviar el código.' : 'The code could not be sent.'));
    }

    public function showPassword(Request $request)
    {
        [$p, $user] = $this->pending($request);
        if (! $user) return $this->expired($request);
        if (($p['steps'][0] ?? null) !== 'password') return $this->next($request, $p, $user);

        return response()->view('auth.login-step', ['mode' => 'password', 'method' => null, 'user' => $user, 'masked' => null])
            ->header('Cache-Control', 'no-store');
    }

    public function password(Request $request)
    {
        [$p, $user] = $this->pending($request);
        if (! $user) return $this->expired($request);
        $request->validate(['password' => PasswordPolicy::rules($user)]);
        PasswordPolicy::save($user, (string) $request->input('password'), 'web', $request);
        $p = $this->put($request, ['steps' => array_values(array_diff($p['steps'], ['password']))]);

        return $this->next($request, $p, $user->fresh());
    }

    public function cancel(Request $request)
    {
        $request->session()->forget(WebLogin::PENDING);

        return redirect()->route('login');
    }

    // ------------------------------------------------------------------

    /** @return array{0: ?array, 1: ?User} the waiting sign-in and its (still active, unlocked) user */
    private function pending(Request $request): array
    {
        $p = $request->session()->get(WebLogin::PENDING);
        if (! $p || time() - ($p['at'] ?? 0) > self::TTL) {
            $request->session()->forget(WebLogin::PENDING);

            return [null, null];
        }
        $user = User::whereKey($p['user_id'])->whereNull('deleted_at')->where('statut', 1)->first();
        if (! $user || LoginSecurity::lockedUntil($user)) {
            $request->session()->forget(WebLogin::PENDING);

            return [null, null];
        }

        return [$p, $user];
    }

    private function put(Request $request, array $set): array
    {
        $p = array_merge($request->session()->get(WebLogin::PENDING, []), $set);
        $request->session()->put(WebLogin::PENDING, $p);

        return $p;
    }

    private function next(Request $request, array $p, User $user)
    {
        if (! $p['steps']) {
            $request->session()->forget(WebLogin::PENDING);

            return WebLogin::complete($request, $user, (bool) $p['remember'], (bool) ($p['remember_device'] ?? false));
        }

        return redirect()->route($p['steps'][0] === '2fa' ? 'login.verify' : 'login.password');
    }

    private function expired(Request $request)
    {
        return redirect()->route('login')->with('erro_login', LoginSecurity::es()
            ? 'El ingreso venció. Escriba su email y contraseña de nuevo.'
            : 'The sign-in expired. Enter your email and password again.');
    }

    private function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
    }
}
