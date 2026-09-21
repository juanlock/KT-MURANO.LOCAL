<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LocaleSyncController;
use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [
        // Written by LocaleSyncController on an 'api' route, which has no
        // EncryptCookies — so a 'web' request would fail to decrypt it and
        // drop it, and SetLocale would never see the language the user picked.
        // A locale code is not sensitive.
        LocaleSyncController::COOKIE_NAME,
    ];
}
