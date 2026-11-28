<?php

namespace App\Support\ItAdmin;

use Illuminate\Support\Facades\DB;

/**
 * Who had / was seen on an IP, also long after it moved or was deleted (HTD-IT's ip_history): "assigned" / "removed" when an
 * asset's IP rows change (ItAssets::save), "seen" by each network scan (one row per device per IP, first / last time, times).
 * Never edited by users.
 */
class IpHistory
{
    /** interface => [ip column, network column, MAC label]. */
    public const ROWS = [
        'lan' => ['ip_address', 'ip_network_id', 'LAN'],
        'wifi' => ['wifi_ip_address', 'wifi_network_id', 'Wi-Fi'],
        'wan' => ['wan_ip_address', 'wan_network_id', null],
        'mgmt' => ['mgmt_ip_address', 'mgmt_network_id', 'MGMT'],
    ];

    public static ?string $source = null;

    public static ?int $scanId = null;

    /** Runs $work with its IP changes labelled with $source (e.g. "Network scan #3"). */
    public static function withSource(string $source, ?int $scanId, callable $work)
    {
        [$s, $i] = [self::$source, self::$scanId];
        [self::$source, self::$scanId] = [$source, $scanId];
        try {
            return $work();
        } finally {
            [self::$source, self::$scanId] = [$s, $i];
        }
    }

    public static function record(int $companyId, string $event, string $ip, ?int $networkId, ?object $asset, ?string $iface, string $default = 'Asset form'): void
    {
        $now = now();
        $details = $asset ? (is_array($asset->details ?? null) ? $asset->details : (json_decode((string) ($asset->details ?? ''), true) ?: [])) : [];
        DB::table('it_ip_history')->insert([
            'company_id' => $companyId, 'ip' => $ip, 'network_id' => $networkId, 'asset_id' => $asset->id ?? null, 'asset_tag' => $asset->asset_tag ?? null,
            'hostname' => isset($details['hostname']) ? mb_substr((string) $details['hostname'], 0, 190) : null,
            'event' => $event, 'interface' => $iface, 'source' => self::$source ?? $default, 'scan_id' => self::$scanId,
            'user_id' => optional(auth()->user())->id, 'first_at' => $now, 'last_at' => $now, 'times_seen' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** The asset's IP rows before / after a save: the old IP "removed", the new one "assigned". */
    public static function diff(int $companyId, ?array $before, object $after): void
    {
        foreach (self::ROWS as $iface => [$ipCol, $netCol]) {
            $old = $before[$ipCol] ?? null;
            $new = $after->$ipCol ?? null;
            if ($old === $new && (int) ($before[$netCol] ?? 0) === (int) ($after->$netCol ?? 0)) {
                continue;
            }
            if ($old) {
                self::record($companyId, 'removed', $old, ($before[$netCol] ?? null) ? (int) $before[$netCol] : null, $after, $iface);
            }
            if ($new) {
                self::record($companyId, 'assigned', $new, $after->$netCol ? (int) $after->$netCol : null, $after, $iface);
            }
        }
    }
}
