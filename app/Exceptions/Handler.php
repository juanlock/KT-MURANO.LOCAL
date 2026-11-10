<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var arrays
     */
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */

    // public function destroyCookie($cookie_name){

    //     $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];

    //     if (isset($_COOKIE[$cookie_name])) {
    //         unset($_COOKIE[$cookie_name]);
    //         setcookie($cookie_name, '', time() - 2147483647, '/', $domain); // empty value and old timestamp
    //     }
    // }
    // protected function unauthenticated($request, AuthenticationException $exception)
    // {
    //     if ($request->expectsJson()) {
    //         $this->destroyCookie('Murano_token');

    //         return response()->json([
    //             'message' => 'Unauthenticated.',
    //             'status' => 401,
    //         ], 401);
    //     }

    // }

    public function render($request, Throwable $exception)
    {
        // SuperAdmin login chooser with an old token (Back after entering as an admin,
        // an expired session, another tab): a fresh chooser -- or the login page when
        // the session is gone -- instead of "419 Page Expired".
        if ($exception instanceof \Illuminate\Session\TokenMismatchException && ! $request->expectsJson()
            && in_array(trim($request->path(), '/'), ['login/choose', 'login/back'], true)) {
            $es = app()->getLocale() === 'es';

            return redirect('/login/choose')->with('choose_notice', $es
                ? 'La página había caducado. Elija otra vez cómo entrar.'
                : 'The page had expired. Choose again how to enter.');
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
                'status' => 404,
            ], 404);

        } elseif ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return response()->json([
                'message' => 'You are not authorized',
                'status' => 403,
            ], 403);
        }

        return parent::render($request, $exception);
    }
}
