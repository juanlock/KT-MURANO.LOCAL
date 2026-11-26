<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Jelaf / AuraSoft reports without a browser (2026-11-26; Turismo Cautivo's own data, the owner's yes): its report site
 * (RepGerencial) sends every call as JSON to base/redirectAtLocalhost {protocol, root: its internal API, jsonrequest, token}.
 * Login/Autentication answers a token; an "...Excel" call builds the file on their server and answers its address, which
 * is then downloaded. No cookies. One request at a time and a pause between them (the provider's server is small).
 *
 * The login comes from .env.jelaf (JELAF_URL, JELAF_USER, JELAF_PASSWORD), per machine and git-ignored, until it moves
 * to the company's encrypted settings for the production scheduler.
 */
class JelafClient
{
    private string $base;

    private ?string $token = null;

    public function __construct(private string $user, private string $password, string $url)
    {
        $this->base = rtrim($url, '/');
    }

    /** Where the login file is: the repo root on a developer PC, ~/murano_deploy on the server (outside the website). */
    public static function envFile(): ?string
    {
        foreach ([base_path('.env.jelaf'), rtrim((string) (getenv('HOME') ?: ''), '/').'/murano_deploy/.env.jelaf', dirname(base_path()).'/../murano_deploy/.env.jelaf'] as $f) {
            if ($f && is_file($f)) {
                return $f;
            }
        }

        return null;
    }

    public static function fromEnvFile(?string $file = null): self
    {
        $file ??= self::envFile();
        if (! $file || ! is_file($file)) {
            throw new RuntimeException('No Jelaf login on this machine (.env.jelaf).');
        }
        $env = [];
        foreach (file($file) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m)) {
                $env[$m[1]] = trim($m[2], '"');
            }
        }
        foreach (['JELAF_URL', 'JELAF_USER', 'JELAF_PASSWORD'] as $k) {
            if (empty($env[$k])) {
                throw new RuntimeException("Jelaf login incomplete ($k).");
            }
        }

        return new self($env['JELAF_USER'], $env['JELAF_PASSWORD'], $env['JELAF_URL']);
    }

    private function call(string $protocol, string $api, $payload): array
    {
        $r = Http::timeout(240)->acceptJson()->retry(2, 5000, throw: false)->post($this->base.'/base/redirectAtLocalhost', [
            'protocol' => $protocol, 'root' => 'http://localhost:8034/RepGerencial/api/'.$api,
            'jsonrequest' => $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE), 'token' => $this->token ?? '',
        ]);
        $json = $r->json();
        if (! $r->successful() || ! is_array($json)) {
            throw new RuntimeException("Jelaf $api: HTTP {$r->status()}");
        }

        return $json;
    }

    public function login(): void
    {
        $res = $this->call('POST', 'Login/Autentication', ['LoginUser' => $this->user, 'passUser' => $this->password]);
        $token = null;
        array_walk_recursive($res, function ($v) use (&$token) {
            if (! $token && is_string($v) && preg_match('/^eyJ[\w-]+\.[\w-]+\.[\w-]+$/', $v)) {
                $token = $v;
            }
        });
        if (empty($res['Success']) || ! $token) {
            throw new RuntimeException('Jelaf login refused: '.($res['Message'] ?? 'no token'));
        }
        $this->token = $token;
    }

    /** Ask Jelaf to build an Excel and download it to $target. Returns the bytes written (0 = nothing to export). */
    public function export(string $api, array $payload, string $target): int
    {
        if (! $this->token) {
            $this->login();
        }
        $res = $this->call('POST', $api, $payload);
        $url = is_string($res['Data'] ?? null) && preg_match('/\.xlsx?$/i', $res['Data']) ? $res['Data'] : null;
        if (empty($res['Success']) || ! $url) {
            $msg = (string) ($res['Message'] ?? '');
            if (stripos($msg, 'no hay') !== false) {
                return 0;
            }
            throw new RuntimeException("Jelaf $api: ".($msg ?: 'no file'));
        }
        // Only a file of Jelaf's own site.
        if (! str_starts_with($url, preg_replace('#/RepGerencial$#i', '', $this->base))) {
            throw new RuntimeException('Jelaf answered an unexpected address.');
        }
        $r = Http::timeout(240)->retry(2, 5000, throw: false)->get($url);
        if (! $r->successful() || strlen($r->body()) < 200) {
            throw new RuntimeException("Jelaf download: HTTP {$r->status()}");
        }
        @mkdir(dirname($target), 0775, true);
        file_put_contents($target, $r->body());

        return strlen($r->body());
    }

    /** Producción por bus, resumido, Formato 1, every company (TODOS: the web store moved to another entity in Nov 2026). */
    public function productionPerBus(string $from, string $to, string $target): int
    {
        return $this->export('ProduccionParaBus/ProduccionParaBusResumenExcel', [
            'fechaI' => date('d/m/Y', strtotime($from)), 'fechaF' => date('d/m/Y', strtotime($to)), 'Codi_empresa' => 0, 'suc' => 0, 'ptventa' => 0,
            'empresa' => 'TODOS', 'unidad' => '', 'cajero' => 0, 'DescEmpresa' => 'TODOS', 'Tip_Rpt' => '1', 'Formato' => 'F1',
        ], $target);
    }
}
