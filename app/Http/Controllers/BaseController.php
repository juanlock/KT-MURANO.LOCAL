<?php

namespace App\Http\Controllers;

use App\Traits\ScopesWarehouseAccess;
use Config;
use DB;

class BaseController extends Controller
{
    use ScopesWarehouseAccess;

    public function sendResponse($result, $msg)
    {
        $response = [
            'success' => true,
            'message' => $msg,
        ];
        if (! empty($result)) {
            $response['data'] = $result;
        }

        return response()->json($response, 200);
    }

    public function sendError($error_msg, $error = null)
    {
        $response = [
            'success' => false,
            'message' => $error_msg,
        ];
        if (isset($error)) {
            $response['errors'] = $error;
        }

        return response()->json($response, 400);
    }

    //    Set cookie
    public function setCookie($cookie_name, $cookie_value)
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        $this->destroyCookie($cookie_name);
        setcookie($cookie_name, $cookie_value, time() + 2147483647, '/', $domain);
    }

    // Get cookie
    public function getCookie($cookie_name)
    {
        if (isset($_COOKIE[$cookie_name])) {
            return $_COOKIE[$cookie_name];
        } else {
            return false;
        }
    }

    // Has cookie
    public function hasCookie($cookie_name)
    {
        if (isset($_COOKIE[$cookie_name])) {
            return true;
        } else {
            return false;
        }
    }

    // Destroy cookie
    public function destroyCookie($cookie_name)
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        if (isset($_COOKIE[$cookie_name])) {
            unset($_COOKIE[$cookie_name]);
            setcookie($cookie_name, '', time() - 2147483647, '/', $domain);

        }
    }

    // Clear cookie
    public function clearCookie()
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        if (isset($_COOKIE['Murano_token'])) {
            unset($_COOKIE['Murano_token']);
            setcookie('Murano_token', '', time() - 2147483647, '/', $domain); // empty value and old timestamp
        }
    }

    // Set config mail
    /**
     * Loads this company's SMTP config from the DB into Laravel's runtime
     * mail config. $companyId defaults to the authenticated user's own
     * company (correct for every one of this method's ~18 authenticated
     * call sites without having to touch each one); pass it explicitly for
     * a guest/unauthenticated context (e.g. a public storefront action)
     * where there's a specific company to attribute the email to.
     *
     * Root-cause fix: this used to Config::set('mail', [...]) with a FLAT,
     * pre-Laravel-7 shape ('driver'/'host'/'port' directly under 'mail').
     * This app's actual config/mail.php (Laravel 10) expects the nested
     * 'mailers.{name}.*' shape -- the old flat overwrite replaced the whole
     * 'mail' config array including 'default' and 'mailers', so Mail::
     * calls had no way to resolve a real transport at all. This was the
     * actual reason "Test" always failed, independent of the multicompany
     * gap below.
     */
    public function Set_config_mail(?int $companyId = null)
    {
        $companyId = $companyId ?? optional(auth('api')->user())->company_id ?? 1;

        $server = \App\Models\Server::forCompany($companyId);
        $settings = \App\Models\Setting::forCompany($companyId);

        if ($server && $server->host) {
            $fromEmail = $server->sender_email ?: ($settings->email ?? null);
            $mailer = $server->mail_mailer ?: 'smtp';

            Config::set('mail.default', $mailer);
            Config::set("mail.mailers.{$mailer}", [
                'transport' => $mailer,
                'host' => $server->host,
                'port' => $server->port,
                'encryption' => $server->encryption === 'none' ? null : $server->encryption,
                'username' => $server->username,
                'password' => $server->password,
                'timeout' => null,
                'stream' => [
                    'ssl' => [
                        'allow_self_signed' => true,
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ],
            ]);
            Config::set('mail.from', [
                'address' => $fromEmail,
                'name' => $server->sender_name ?: ($settings->CompanyName ?? 'KcolTech - Murano'),
            ]);
        }
    }
}
