<?php

namespace App\Services;

use RuntimeException;

/**
 * Self-signed certificate + request signing for QZ Tray.
 *
 * QZ Tray only lets the user permanently "remember" the Allow decision (or
 * skip the prompt entirely) when the website signs its print requests with a
 * certificate. This service generates a long-lived self-signed keypair on
 * first use (storage/app/qz/) and signs the challenge strings the qz-tray JS
 * library sends to POST qz/sign.
 *
 * For a fully silent setup the user copies the public certificate into the
 * QZ Tray install folder as override.crt (the settings page offers it as a
 * download) and restarts QZ Tray.
 */
class QzCertificateService
{
    private string $certPath;
    private string $keyPath;

    public function __construct()
    {
        $dir = storage_path('app/qz');
        $this->certPath = $dir . '/digital-certificate.txt';
        $this->keyPath = $dir . '/private-key.pem';
    }

    /** Public certificate (PEM). Generates the keypair on first call. */
    public function certificate(): string
    {
        $this->ensureKeyPair();

        return (string) file_get_contents($this->certPath);
    }

    /** Base64 RSA-SHA512 signature of $data, matching setSignatureAlgorithm('SHA512'). */
    public function sign(string $data): string
    {
        $this->ensureKeyPair();

        $key = openssl_pkey_get_private((string) file_get_contents($this->keyPath));
        if ($key === false) {
            throw new RuntimeException('QZ private key could not be loaded.');
        }
        if (! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA512)) {
            throw new RuntimeException('QZ request signing failed.');
        }

        return base64_encode($signature);
    }

    private function ensureKeyPair(): void
    {
        if (is_file($this->certPath) && is_file($this->keyPath)) {
            return;
        }
        if (! function_exists('openssl_pkey_new')) {
            throw new RuntimeException('The PHP openssl extension is required to sign QZ Tray requests.');
        }

        $dir = dirname($this->certPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw new RuntimeException('Could not create the storage/app/qz directory.');
        }

        $args = $this->opensslConfigArgs();
        $key = openssl_pkey_new($args + [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new RuntimeException('RSA key generation failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        $dn = ['commonName' => 'Stocky QZ Tray', 'organizationName' => 'Stocky'];
        $csr = openssl_csr_new($dn, $key, $args + ['digest_alg' => 'sha256']);
        $x509 = $csr ? openssl_csr_sign($csr, null, $key, 3650, $args + ['digest_alg' => 'sha256']) : false;
        if ($x509 === false) {
            throw new RuntimeException('Certificate generation failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($key, $keyOut, null, $args);

        if (file_put_contents($this->certPath, $certOut) === false
            || file_put_contents($this->keyPath, $keyOut) === false) {
            throw new RuntimeException('Could not write the QZ key pair to storage/app/qz.');
        }
        @chmod($this->keyPath, 0600);
    }

    /**
     * On Windows, PHP's openssl functions fail unless pointed at an
     * openssl.cnf — locate one and pass it as the 'config' arg. Linux/macOS
     * builds find their config on their own.
     */
    private function opensslConfigArgs(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }
        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            getenv('OPENSSL_CONF') ?: '',
            $phpDir . '\\extras\\ssl\\openssl.cnf',
            $phpDir . '\\extras\\openssl\\openssl.cnf',
            'C:\\Program Files\\Common Files\\SSL\\openssl.cnf',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return ['config' => $candidate];
            }
        }
        return [];
    }
}
