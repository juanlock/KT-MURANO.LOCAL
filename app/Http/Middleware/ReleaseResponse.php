<?php

namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gives the browser its answer before the "after the response" work runs
 * (register / booking notices by email, the POS receipt PDF: every
 * `dispatch(...)->afterResponse()`).
 *
 * PHP-FPM and LiteSpeed end the request by themselves (fastcgi_finish_request /
 * litespeed_finish_request) and this does nothing there. Apache's mod_php (XAMPP
 * on a developer PC) has neither: the answer was sent in chunks and the browser
 * kept waiting for the last one until the email had gone -- "Open register" took
 * 16 seconds and looked dead. With the body's length declared the browser knows
 * the answer is complete as soon as it is flushed; "Connection: close" keeps it
 * from sending its next call down the connection still busy with that work.
 */
class ReleaseResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')
            || in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            return $response;
        }
        if (! $this->pendingWork()) {
            return $response;
        }
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse
            || $request->isMethod('HEAD') || $response->isEmpty() || $response->isInformational()
            || $response->headers->has('Content-Length') || $response->headers->has('Transfer-Encoding')) {
            return $response;
        }
        // Anything that would make the real body differ from ours: leave the answer as it is.
        if (headers_sent() || ini_get('zlib.output_compression') || ini_get('output_handler') || (int) ob_get_length() > 0) {
            return $response;
        }
        $content = $response->getContent();
        if (! is_string($content) || $content === '') {
            return $response;
        }

        $response->headers->set('Content-Length', (string) strlen($content));
        $response->headers->set('Connection', 'close');

        return $response;
    }

    /** Is there work registered to run once the answer has been sent? */
    private function pendingWork(): bool
    {
        try {
            return (function () {
                return ! empty($this->terminatingCallbacks);
            })->call(app());
        } catch (\Throwable $e) {
            return false;
        }
    }
}
