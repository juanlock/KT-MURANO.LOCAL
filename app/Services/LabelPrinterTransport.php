<?php

namespace App\Services;

use App\Models\PosSetting;

/**
 * Delivers a raw command payload (TSPL bytes) to the configured label printer.
 *
 * - 'network': TCP RAW/JetDirect socket (same mechanism as receipt Direct
 *   Network Printing) — printer must be reachable from the web server.
 * - 'windows' (stored value; means "USB printer attached to the server"):
 *   on Windows the bytes go through the print spooler in RAW mode via a
 *   PowerShell helper (resources/scripts/raw_print.ps1); on macOS/Linux they
 *   go through CUPS via `lp -o raw`, using the CUPS queue name.
 * - 'qz': delivered by the BROWSER through QZ Tray on the client machine —
 *   never sent from the server. Endpoints must return the raw payload to the
 *   frontend instead of calling this transport (see isClientSide()).
 */
class LabelPrinterTransport
{
    public static function isClientSide(PosSetting $printer): bool
    {
        return $printer->label_printer_connection === 'qz';
    }

    /** @return array{ok: bool, message: string} */
    public function send(PosSetting $printer, string $payload): array
    {
        if (self::isClientSide($printer)) {
            return ['ok' => false, 'message' => 'QZ Tray printing runs in the browser and cannot be sent from the server.'];
        }

        $connection = $printer->label_printer_connection === 'network' ? 'network' : 'windows';

        return $connection === 'network'
            ? $this->sendNetwork($printer, $payload)
            : $this->sendUsb($printer, $payload);
    }

    private function sendNetwork(PosSetting $printer, string $payload): array
    {
        $ip = trim((string) $printer->label_printer_ip);
        $port = (int) ($printer->label_printer_port ?: 9100);
        if ($ip === '' || $port < 1 || $port > 65535) {
            return ['ok' => false, 'message' => 'Label printer IP/port is not configured.'];
        }

        $fp = @fsockopen($ip, $port, $errno, $errstr, 3);
        if (! $fp) {
            return ['ok' => false, 'message' => "Could not reach label printer at {$ip}:{$port} ({$errstr})."];
        }
        @stream_set_timeout($fp, 5);
        // Large jobs can exceed the socket send buffer — loop until everything
        // is written instead of trusting a single fwrite().
        $total = strlen($payload);
        $sent = 0;
        while ($sent < $total) {
            $written = @fwrite($fp, substr($payload, $sent));
            if ($written === false || $written === 0) {
                break;
            }
            $sent += $written;
        }
        @fflush($fp);
        @fclose($fp);

        if ($sent < $total) {
            return ['ok' => false, 'message' => "Write to label printer at {$ip}:{$port} failed ({$sent}/{$total} bytes sent)."];
        }
        return ['ok' => true, 'message' => "Sent to {$ip}:{$port}."];
    }

    /** USB printer attached to the server — OS spooler in RAW mode. */
    private function sendUsb(PosSetting $printer, string $payload): array
    {
        $name = trim((string) $printer->label_printer_name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'USB printer name is not configured.'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lbl');
        if ($tmp === false || file_put_contents($tmp, $payload) === false) {
            return ['ok' => false, 'message' => 'Could not write the temporary print file.'];
        }

        try {
            return PHP_OS_FAMILY === 'Windows'
                ? $this->spoolWindows($name, $tmp)
                : $this->spoolCups($name, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function spoolWindows(string $name, string $path): array
    {
        $script = base_path('resources/scripts/raw_print.ps1');
        if (! is_file($script)) {
            return ['ok' => false, 'message' => 'raw_print.ps1 helper script is missing.'];
        }

        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            return ['ok' => false, 'message' => 'PHP exec() is disabled on this server, so USB printing is unavailable. Enable exec() or use the network connection type instead.'];
        }

        $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . escapeshellarg($script)
            . ' -Printer ' . escapeshellarg($name)
            . ' -Path ' . escapeshellarg($path)
            . ' 2>&1';
        @exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $detail = trim(implode(' ', array_slice((array) $output, 0, 3)));
            return ['ok' => false, 'message' => "Windows spooler print to \"{$name}\" failed" . ($detail !== '' ? ": {$detail}" : '.')];
        }
        return ['ok' => true, 'message' => "Sent to \"{$name}\"."];
    }

    /** macOS / Linux: raw job through CUPS. $name is the CUPS queue name. */
    private function spoolCups(string $name, string $path): array
    {
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            return ['ok' => false, 'message' => 'PHP exec() is disabled on this server, so USB (CUPS) printing is unavailable. Enable exec() or use the network connection type instead.'];
        }

        $lp = $this->findLpBinary();
        if ($lp === null) {
            return ['ok' => false, 'message' => 'The CUPS "lp" command was not found on this server, so USB printing is unavailable. Use the network connection type instead.'];
        }

        $cmd = escapeshellarg($lp) . ' -d ' . escapeshellarg($name) . ' -o raw ' . escapeshellarg($path) . ' 2>&1';
        @exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $detail = trim(implode(' ', array_slice((array) $output, 0, 3)));
            return ['ok' => false, 'message' => "CUPS print to \"{$name}\" failed" . ($detail !== '' ? ": {$detail}" : '.') . ' Check the queue name with "lpstat -p".'];
        }
        return ['ok' => true, 'message' => "Sent to \"{$name}\" via CUPS."];
    }

    /**
     * Locate the CUPS lp binary by absolute path — web-server PHP often runs
     * with a minimal PATH where `command -v lp` finds nothing.
     */
    private function findLpBinary(): ?string
    {
        foreach (['/usr/bin/lp', '/usr/local/bin/lp', '/opt/homebrew/bin/lp', '/bin/lp'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        @exec('command -v lp 2>/dev/null', $out, $code);
        $found = trim((string) ($out[0] ?? ''));
        if ($code === 0 && $found !== '' && @is_executable($found)) {
            return $found;
        }
        return null;
    }
}
