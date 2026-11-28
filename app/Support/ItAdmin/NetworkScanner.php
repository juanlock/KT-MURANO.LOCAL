<?php

namespace App\Support\ItAdmin;

use Illuminate\Support\Facades\DB;

/**
 * "Scan this network" (ported from HTD-IT, 2026-11-28). A browser cannot scan a network and Murano runs in the cloud, so the page
 * gives a script for one network (resources/scripts/it-network-scan.ps1, with a one-use token) -- or, where the PC blocks
 * scripts, one cmd line of Windows' own tools (ping sweep + "arp -a") whose text file is uploaded on the page -- that a PC of
 * the office runs; it uploads what it found. Nothing changes until someone reviews it: rows() compares the finding with the
 * assets live (a row reads "OK" as soon as it was applied or fixed by hand), apply() / useNew() write one row through
 * ItAssets::save (so the IP history records it). Devices are recognised by MAC, then by IP.
 */
class NetworkScanner
{
    public static function network(int $companyId, int $id): object
    {
        $n = DB::table('it_networks')->where('company_id', $companyId)->where('id', $id)->first();
        abort_unless($n, 404);

        return $n;
    }

    public static function script(object $scan, object $n, string $token, string $uploadUrl): string
    {
        $q = fn ($v) => str_replace("'", "''", preg_replace('/[^\x20-\x7E]/', '-', (string) $v));

        return strtr(file_get_contents(resource_path('scripts/it-network-scan.ps1')), [
            '%%URL%%' => $q($uploadUrl), '%%TOKEN%%' => $q($token), '%%NETWORK_NAME%%' => $q($n->name),
            '%%FIRST%%' => IpCalc::firstHost($n->network_address, (int) $n->cidr), '%%LAST%%' => IpCalc::lastHost($n->network_address, (int) $n->cidr),
            '%%NETWORK%%' => $n->network_address, '%%CIDR%%' => (int) $n->cidr, '%%SCAN%%' => $scan->id, '%%FILE_TAG%%' => self::fileTag($n),
        ]);
    }

    /** {location code}-{network}, e.g. Lima-192.168.1.0-24: the scan file is C:\temp\{date}-{tag}-{computer}.scan */
    public static function fileTag(object $n): string
    {
        $loc = $n->location_id ? DB::table('it_locations')->where('id', $n->location_id)->first(['code', 'name']) : null;
        $safe = fn ($v) => preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $v);

        return ($safe($loc->code ?? '') ?: ($safe($loc->name ?? '') ?: 'location')) . '-' . $safe($n->network_address) . '-' . (int) $n->cidr;
    }

    /**
     * No script (a PC whose Smart App Control / policy blocks it): Windows' own commands in cmd -- ping every IP in the background
     * (fills the ARP table), wait, save "arp -a" + the names ("ping -a") to a text file, uploaded on the page. Null above a /16.
     */
    public static function arpCommand(object $n): ?string
    {
        $first = explode('.', (string) IpCalc::firstHost($n->network_address, (int) $n->cidr));
        $last = explode('.', (string) IpCalc::lastHost($n->network_address, (int) $n->cidr));
        if (count($first) !== 4 || count($last) !== 4 || $first[0] !== $last[0] || $first[1] !== $last[1]) {
            return null;
        }
        $file = 'C:\temp\%t-' . self::fileTag($n) . '-%COMPUTERNAME%.scan';
        $ping = fn ($c) => "@start /b ping -n 1 -w 500 {$first[0]}.{$first[1]}.$c.%i ^>nul";
        $inner = $first[2] === $last[2]
            ? "for /L %i in ({$first[3]},1,{$last[3]}) do " . $ping($first[2])
            : "for /L %c in ({$first[2]},1,{$last[2]}) do @for /L %i in (1,1,254) do " . $ping('%c');
        $prefix = $first[2] === $last[2] ? "{$first[0]}.{$first[1]}.{$first[2]}." : "{$first[0]}.{$first[1]}.";
        $names = "for /f %a in ('arp -a ^^^| findstr /l $prefix ^^^| findstr /v :') do @start /b cmd /c ping -a -n 1 -w 300 %a ^^^| findstr /l /c:[ ^^^> C:\\temp\\%t-%a.name";
        $join = "ping -n 13 127.0.0.1 ^>nul ^& (type C:\\temp\\%t-*.name ^>^> $file 2^>nul) ^& (del /q C:\\temp\\%t-*.name 2^>nul)";

        return "cmd /c \"start cmd /c title IT Admin network scan ^& echo Scanning {$n->network_address}/{$n->cidr} - this window closes by itself when it finishes. ^& (if not exist C:\\temp mkdir C:\\temp) ^& (if not exist C:\\temp\\ (echo ERROR - the folder C:\\temp could not be created on this computer. ^& pause))"
            . " ^& for /f %t in ('powershell -NoProfile -Command Get-Date -Format yyyyMMdd-HHmmss 2^^^>nul ^^^|^^^| echo scan-%RANDOM%') do @(($inner) ^& ping -n 9 127.0.0.1 ^>nul ^& arp -a ^> $file ^& echo Devices listed, reading their names... ^& ($names) ^& $join ^& echo Finished: $file ^& ping -n 4 127.0.0.1 ^>nul)\"";
    }

    /** The text of "arp -a" (+ "ping -a" names) => the payload the script sends. Only entries inside the network. */
    public static function parseArp(string $text, object $n): ?array
    {
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }
        $results = $names = [];
        $localIp = null;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (preg_match('/(\S+)\s+\[(\d+\.\d+\.\d+\.\d+)\]/', $line, $m)) {
                $names[$m[2]] = $m[1];
            } elseif (preg_match('/^\S[^:]*:\s+(\d+\.\d+\.\d+\.\d+)\s+---/', $line, $m)) {
                if (ItAssets::contains($n, $m[1])) {
                    $localIp = $m[1];
                }
            } elseif (preg_match('/^\s*(\d+\.\d+\.\d+\.\d+)\s+((?:[0-9a-fA-F]{2}-){5}[0-9a-fA-F]{2})\s/', $line, $m) && ItAssets::contains($n, $m[1])) {
                $mac = strtoupper($m[2]);
                if (MacVendor::isDevice($mac)) {
                    $results[$m[1]] = ['ip' => $m[1], 'mac' => $mac, 'hostname' => null, 'method' => 'arp'];
                }
            }
        }
        if (! $results) {
            return null;
        }
        if ($localIp) {
            $results[$localIp] ??= ['ip' => $localIp, 'mac' => null, 'hostname' => null, 'method' => 'self'];
        }
        foreach ($names as $ip => $name) {
            if (isset($results[$ip]) && $name !== $ip) {
                $results[$ip]['hostname'] = $name;
            }
        }
        $hosts = (IpCalc::toLong(IpCalc::lastHost($n->network_address, (int) $n->cidr)) ?? 0) - (IpCalc::toLong(IpCalc::firstHost($n->network_address, (int) $n->cidr)) ?? 0) + 1;

        return ['computer' => null, 'localIp' => $localIp, 'connected' => true, 'scannedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'hosts' => max(0, $hosts), 'results' => array_values($results)];
    }

    /** Stores what was found (IPs outside the network dropped, MACs normalised) and updates the IP history's "seen" rows. */
    public static function ingest(object $scan, array $payload, ?string $uploaderIp): int
    {
        $n = DB::table('it_networks')->where('id', $scan->network_id)->first();
        $rows = [];
        foreach (array_slice((array) ($payload['results'] ?? []), 0, 70000) as $r) {
            $ip = trim((string) ($r['ip'] ?? ''));
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! ItAssets::contains($n, $ip) || isset($rows[$ip])) {
                continue;
            }
            $mac = MacVendor::normalize(is_string($r['mac'] ?? null) ? $r['mac'] : null);
            $mac = MacVendor::isDevice($mac) ? $mac : null;
            $host = is_string($r['hostname'] ?? null) ? mb_substr(trim(strip_tags($r['hostname'])), 0, 190) : null;
            $rows[$ip] = ['scan_id' => $scan->id, 'ip' => $ip, 'mac' => $mac, 'hostname' => $host ?: null, 'vendor' => MacVendor::name($mac),
                'method' => mb_substr((string) ($r['method'] ?? ''), 0, 20) ?: null, 'created_at' => now(), 'updated_at' => now()];
        }

        DB::transaction(function () use ($scan, $rows, $payload, $uploaderIp, $n) {
            DB::table('it_network_scan_results')->where('scan_id', $scan->id)->delete();
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                DB::table('it_network_scan_results')->insert($chunk);
            }
            $at = date('Y-m-d H:i:s', strtotime((string) ($payload['scannedAt'] ?? '')) ?: time());
            DB::table('it_network_scans')->where('id', $scan->id)->update(['status' => 'uploaded', 'token_hash' => null, 'token_enc' => null,
                'uploaded_at' => now(), 'scanned_at' => $at, 'scanner_computer' => mb_substr((string) ($payload['computer'] ?? ''), 0, 120) ?: null,
                'scanner_ip' => $uploaderIp, 'scanner_local_ip' => filter_var($payload['localIp'] ?? null, FILTER_VALIDATE_IP) ?: null,
                'directly_connected' => (bool) ($payload['connected'] ?? false), 'hosts_scanned' => (int) ($payload['hosts'] ?? 0),
                'found_count' => count($rows), 'updated_at' => now()]);

            [$assets, $owners] = self::maps($n);
            foreach ($rows as $r) {
                $asset = ($r['mac'] ? ($owners[$r['mac']] ?? null) : null) ?: $assets->first(fn ($a) => self::ifaceOf($a, $r['ip']));
                $q = DB::table('it_ip_history')->where('event', 'seen')->where('network_id', $n->id)->where('ip', $r['ip']);
                $q = $r['mac'] ? $q->where('mac', $r['mac']) : ($asset ? $q->where('asset_id', $asset->id) : $q->whereNull('mac')->whereNull('asset_id')->where('hostname', $r['hostname']));
                if ($h = $q->first()) {
                    DB::table('it_ip_history')->where('id', $h->id)->update(['last_at' => $at, 'times_seen' => $h->times_seen + 1, 'scan_id' => $scan->id,
                        'asset_id' => $asset->id ?? $h->asset_id, 'asset_tag' => $asset->asset_tag ?? $h->asset_tag, 'hostname' => $r['hostname'] ?? $h->hostname,
                        'source' => "Network scan #{$scan->id}", 'updated_at' => now()]);
                } else {
                    DB::table('it_ip_history')->insert(['company_id' => $n->company_id, 'ip' => $r['ip'], 'network_id' => $n->id, 'asset_id' => $asset->id ?? null,
                        'asset_tag' => $asset->asset_tag ?? null, 'hostname' => $r['hostname'], 'mac' => $r['mac'], 'event' => 'seen', 'source' => "Network scan #{$scan->id}",
                        'scan_id' => $scan->id, 'first_at' => $at, 'last_at' => $at, 'times_seen' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        return count($rows);
    }

    /** [active assets of the network's location, MAC => its asset (any location of the company)]. */
    private static function maps(object $n): array
    {
        $assets = DB::table('it_assets')->where('company_id', $n->company_id)->where('location_id', $n->location_id ?: 0)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'Retired'))->get();
        ItAssets::hydrate($assets);
        $owners = [];
        $all = DB::table('it_asset_macs as m')->join('it_assets as a', 'a.id', '=', 'm.asset_id')->where('a.company_id', $n->company_id)->get(['m.mac', 'a.id']);
        $byId = $assets->keyBy('id');
        $others = DB::table('it_assets')->whereIn('id', $all->pluck('id')->diff($byId->keys())->all() ?: [0])->get()->keyBy('id');
        foreach ($all as $m) {
            $owners[$m->mac] = $byId[$m->id] ?? $others[$m->id] ?? null;
        }

        return [$assets, array_filter($owners)];
    }

    private static function ifaceOf(object $a, string $ip): ?string
    {
        foreach (IpHistory::ROWS as $iface => [$ipCol]) {
            if (($a->$ipCol ?? null) === $ip) {
                return $iface;
            }
        }

        return collect($a->extra_ips ?? [])->contains('ip_address', $ip) ? 'extra' : null;
    }

    private static function rowOnNetwork(object $a, object $n): ?array
    {
        foreach (IpHistory::ROWS as $iface => [$ipCol, $netCol]) {
            if ($a->$ipCol && ((int) $a->$netCol === (int) $n->id || (! $a->$netCol && ItAssets::contains($n, $a->$ipCol)))) {
                return [$iface, $a->$ipCol];
            }
        }

        return null;
    }

    private static function shortName(?string $host): string
    {
        return strtolower(explode('.', trim((string) $host))[0]);
    }

    /**
     * What each found host means today, plus the registered devices it did not find:
     * kind = changed | new | conflict | ok | missing | ignored; changes = what Apply would write.
     */
    public static function rows(object $scan): array
    {
        $n = DB::table('it_networks')->where('id', $scan->network_id)->first();
        [$assets, $owners] = self::maps($n);
        $byIp = [];
        foreach ($assets as $a) {
            foreach (IpHistory::ROWS as [$ipCol]) {
                if ($a->$ipCol) {
                    $byIp[$a->$ipCol][] = $a;
                }
            }
            foreach ($a->extra_ips as $x) {
                $byIp[$x->ip_address][] = $a;
            }
        }
        $kinds = ItAssets::kinds($n);
        $defaultIface = in_array('lan', $kinds, true) ? 'lan' : ($kinds[0] ?? 'lan');
        $out = [];
        $seen = [];
        $results = DB::table('it_network_scan_results')->where('scan_id', $scan->id)->get();
        $macAt = $results->filter(fn ($x) => $x->mac)->pluck('mac', 'ip');
        foreach ($results as $r) {
            $seen[$r->ip] = true;
            $byMac = $r->mac ? ($owners[$r->mac] ?? null) : null;
            $onIp = $byIp[$r->ip] ?? [];
            $asset = $byMac ?: ($onIp[0] ?? null);
            $changes = [];
            $problem = $conflict = null;

            if ($byMac && ! $assets->contains('id', $byMac->id)) {
                $problem = __('This MAC belongs to :tag, registered in another location.', ['tag' => $byMac->asset_tag]);
                $conflict = ['type' => 'other_location', 'useNew' => __('Move :tag to this location with IP :ip.', ['tag' => $byMac->asset_tag, 'ip' => $r->ip])];
            } elseif ($byMac) {
                $other = collect($onIp)->first(fn ($a) => $a->id !== $byMac->id);
                if ($other) {
                    $problem = __('This IP is registered to :other, but the device answering is :tag (by its MAC).', ['other' => $other->asset_tag, 'tag' => $byMac->asset_tag]);
                    $conflict = ['type' => 'ip_other', 'otherID' => $other->id,
                        'useNew' => __(':tag keeps :ip; :other loses it.', ['tag' => $byMac->asset_tag, 'ip' => $r->ip, 'other' => $other->asset_tag])];
                } elseif (! self::ifaceOf($byMac, $r->ip)) {
                    $row = self::rowOnNetwork($byMac, $n);
                    $held = $row ? null : $byMac->{IpHistory::ROWS[$defaultIface][0]};
                    if ($held) {
                        $isNet = in_array($byMac->type, ItAssetTypes::NETWORK_DEVICES, true);
                        $problem = __(':tag also answers here, but its :iface IP is :held on another network: not changed.', ['tag' => $byMac->asset_tag, 'iface' => IpHistory::ROWS[$defaultIface][2], 'held' => $held]);
                        $conflict = ['type' => 'other_network', 'iface' => $defaultIface, 'addExtra' => $isNet,
                            'useNew' => $isNet ? __('Add :ip as an additional IP of :tag (its IP :held stays).', ['ip' => $r->ip, 'tag' => $byMac->asset_tag, 'held' => $held])
                                : __('Replace its IP :held with :ip.', ['held' => $held, 'ip' => $r->ip])];
                    } elseif ($row && (($owners[$macAt[$row[1]] ?? ''] ?? null)->id ?? null) === $byMac->id) {
                        $problem = __(':tag answers on two IPs with two of its cards: :a and :b.', ['tag' => $byMac->asset_tag, 'a' => $row[1], 'b' => $r->ip]);
                        $conflict = ['type' => 'other_network', 'iface' => $row[0], 'addExtra' => true,
                            'useNew' => __('Add :ip as an additional IP of :tag (its IP :held stays).', ['ip' => $r->ip, 'tag' => $byMac->asset_tag, 'held' => $row[1]])];
                    } else {
                        $changes['ip'] = ['iface' => $row[0] ?? $defaultIface, 'from' => $row[1] ?? null, 'to' => $r->ip];
                    }
                }
            } elseif ($asset && $r->mac && self::ifaceOf($asset, $r->ip) !== 'extra') {
                $iface = self::ifaceOf($asset, $r->ip) ?? 'lan';
                $registered = collect($asset->macs)->first(fn ($m) => match ($iface) {
                    'wifi' => in_array(strtolower((string) $m->label), ['wi-fi', 'wifi', 'wlan'], true),
                    'mgmt' => strtoupper((string) $m->label) === 'MGMT',
                    default => ! $m->label || in_array($m->label, ['LAN', 'Ethernet', 'Phone', 'Firewall', 'Router', 'Switch', 'Access Point'], true),
                });
                if ($iface !== 'wan' && $registered) {
                    $problem = __('A different device may be using this IP: :tag has MAC :mac, this one answers with :new.', ['tag' => $asset->asset_tag, 'mac' => $registered->mac, 'new' => $r->mac]);
                    $conflict = ['type' => 'mac_differs', 'macID' => $registered->id,
                        'useNew' => __('Same device, new card: replace :tag\'s MAC :mac with :new.', ['tag' => $asset->asset_tag, 'mac' => $registered->mac, 'new' => $r->mac])];
                } elseif ($iface !== 'wan') {
                    $changes['mac'] = ['to' => $r->mac, 'label' => IpHistory::ROWS[$iface][2]];
                }
            }
            if ($asset && ! $problem && $r->hostname && self::shortName($r->hostname) !== self::shortName($asset->details['hostname'] ?? null)) {
                $changes['hostname'] = ['from' => $asset->details['hostname'] ?? null, 'to' => self::shortName($r->hostname) === strtolower($r->hostname) ? $r->hostname : explode('.', $r->hostname)[0]];
            }
            $kind = $problem ? 'conflict' : (! $asset ? 'new' : ($changes ? 'changed' : 'ok'));
            $out[] = ['id' => $r->id, 'ip' => $r->ip, 'mac' => $r->mac, 'hostname' => $r->hostname, 'vendor' => $r->vendor, 'method' => $r->method,
                'kind' => $r->decision === 'ignored' && in_array($kind, ['new', 'changed', 'conflict'], true) ? 'ignored' : $kind,
                'realKind' => $kind, 'problem' => $problem, 'conflict' => $conflict, 'changes' => $changes,
                'asset_id' => $asset->id ?? null, 'asset' => $asset->asset_tag ?? null, 'asset_type' => $asset->type ?? null];
        }
        foreach ($assets as $a) {
            $row = self::rowOnNetwork($a, $n);
            if ($row && ! isset($seen[$row[1]])) {
                $out[] = ['id' => null, 'ip' => $row[1], 'mac' => null, 'hostname' => null, 'vendor' => null, 'method' => null, 'kind' => 'missing', 'realKind' => 'missing',
                    'problem' => null, 'conflict' => null, 'changes' => [], 'asset_id' => $a->id, 'asset' => $a->asset_tag, 'asset_type' => $a->type];
            }
            foreach ($a->extra_ips as $x) {
                if (((int) $x->network_id === (int) $n->id || (! $x->network_id && ItAssets::contains($n, $x->ip_address))) && ! isset($seen[$x->ip_address]) && $x->ip_address !== ($row[1] ?? null)) {
                    $seen[$x->ip_address] = true;
                    $out[] = ['id' => null, 'ip' => $x->ip_address, 'mac' => null, 'hostname' => null, 'vendor' => null, 'method' => null, 'kind' => 'missing', 'realKind' => 'missing',
                        'problem' => null, 'conflict' => null, 'changes' => [], 'asset_id' => $a->id, 'asset' => $a->asset_tag, 'asset_type' => $a->type];
                }
            }
        }
        usort($out, fn ($x, $y) => IpCalc::toLong($x['ip']) <=> IpCalc::toLong($y['ip']));

        return $out;
    }

    private static function note(object $scan, string $what): string
    {
        return "Network scan #{$scan->id} on " . now()->format('Y-m-d') . ': ' . $what . '.';
    }

    /** Writes a "changed" row's proposals to its asset. Returns an error or null. */
    public static function apply(object $scan, int $resultId): ?string
    {
        $row = collect(self::rows($scan))->firstWhere('id', $resultId);
        if (! $row || ! $row['changes'] || $row['realKind'] !== 'changed') {
            return __('Nothing to apply on this row (it may have changed already).');
        }
        $n = DB::table('it_networks')->where('id', $scan->network_id)->first();
        $cid = (int) $n->company_id;
        $asset = ItAssets::find($cid, (int) $row['asset_id']);

        return DB::transaction(function () use ($scan, $row, $asset, $n, $cid) {
            $data = [];
            $did = [];
            foreach ($row['changes'] as $what => $c) {
                if ($what === 'mac') {
                    if (DB::table('it_asset_macs as m')->join('it_assets as a', 'a.id', '=', 'm.asset_id')->where('a.company_id', $cid)->where('m.mac', $c['to'])->exists()) {
                        return __(':mac is already registered on another device.', ['mac' => $c['to']]);
                    }
                    DB::table('it_asset_macs')->insert(['asset_id' => $asset->id, 'mac' => $c['to'], 'label' => $c['label'], 'created_at' => now(), 'updated_at' => now()]);
                    $did[] = 'MAC ' . $c['to'];
                } elseif ($what === 'ip') {
                    [$err] = ItAssets::resolveIp($cid, $c['to'], $n->id, $asset->location_id ? (int) $asset->location_id : null);
                    if ($err) {
                        return $err;
                    }
                    [$ipCol, $netCol] = IpHistory::ROWS[$c['iface']];
                    $data[$ipCol] = $c['to'];
                    $data[$netCol] = $n->id;
                    if ($c['iface'] === 'lan') {
                        $data['no_ip'] = false;
                    }
                    $did[] = 'IP ' . ($c['from'] ?: '-') . ' -> ' . $c['to'];
                } else {
                    $details = $asset->details;
                    $details['hostname'] = $c['to'];
                    $data['details'] = $details;
                    $did[] = 'hostname ' . ($c['from'] ?: '-') . ' -> ' . $c['to'];
                }
            }
            IpHistory::withSource("Network scan #{$scan->id}", $scan->id, fn () => ItAssets::save($cid, $asset->id, $data, self::note($scan, implode(', ', $did))));

            return null;
        });
    }

    /** Conflict -> "Use new": what the scan saw wins. Returns an error or null. */
    public static function useNew(object $scan, int $resultId): ?string
    {
        $row = collect(self::rows($scan))->firstWhere('id', $resultId);
        $c = $row['conflict'] ?? null;
        if (! $c || empty($c['useNew']) || $row['realKind'] !== 'conflict') {
            return __('This conflict cannot be solved that way (it may have changed already).');
        }
        $n = DB::table('it_networks')->where('id', $scan->network_id)->first();
        $cid = (int) $n->company_id;
        $asset = ItAssets::find($cid, (int) $row['asset_id']);
        $kinds = ItAssets::kinds($n);
        $iface = in_array('lan', $kinds, true) ? 'lan' : ($kinds[0] ?? 'lan');
        $ipData = function (string $iface) use ($row, $n) {
            [$ipCol, $netCol] = IpHistory::ROWS[$iface];

            return [$ipCol => $row['ip'], $netCol => $n->id] + ($iface === 'lan' ? ['no_ip' => false] : []);
        };
        $src = fn (callable $w) => IpHistory::withSource("Network scan #{$scan->id}", $scan->id, $w);

        return DB::transaction(function () use ($scan, $c, $row, $n, $asset, $cid, $iface, $ipData, $src) {
            switch ($c['type']) {
                case 'mac_differs':
                    $old = DB::table('it_asset_macs')->where('id', $c['macID'])->value('mac');
                    DB::table('it_asset_macs')->where('id', $c['macID'])->update(['mac' => $row['mac'], 'updated_at' => now()]);
                    ItAssets::save($cid, $asset->id, [], self::note($scan, "MAC $old replaced with {$row['mac']}"));
                    break;
                case 'ip_other':
                    $other = ItAssets::find($cid, (int) $c['otherID']);
                    $oi = self::ifaceOf($other, $row['ip']);
                    if ($oi === 'extra') {
                        DB::table('it_asset_extra_ips')->where('asset_id', $other->id)->where('ip_address', $row['ip'])->delete();
                        $src(fn () => IpHistory::record($cid, 'removed', $row['ip'], $n->id, $other, 'extra'));
                        ItAssets::save($cid, $other->id, [], self::note($scan, "lost IP {$row['ip']}: it is answered by {$asset->asset_tag}"));
                    } else {
                        [$ipCol, $netCol] = IpHistory::ROWS[$oi ?? 'lan'];
                        $src(fn () => ItAssets::save($cid, $other->id, [$ipCol => null, $netCol => null], self::note($scan, "lost IP {$row['ip']}: it is answered by {$asset->asset_tag}")));
                    }
                    if (! self::ifaceOf($asset, $row['ip'])) {
                        $current = self::rowOnNetwork($asset, $n);
                        if (! $current && $asset->{IpHistory::ROWS[$iface][0]}) {
                            return __(':tag already has another IP in that row: edit it by hand.', ['tag' => $asset->asset_tag]);
                        }
                        $src(fn () => ItAssets::save($cid, $asset->id, $ipData($current[0] ?? $iface), self::note($scan, "took IP {$row['ip']}")));
                    }
                    break;
                case 'other_network':
                    if (! empty($c['addExtra'])) {
                        DB::table('it_asset_extra_ips')->insert(['asset_id' => $asset->id, 'network_id' => $n->id, 'ip_address' => $row['ip'],
                            'label' => 'Seen by scan #' . $scan->id, 'created_at' => now(), 'updated_at' => now()]);
                        $src(fn () => IpHistory::record($cid, 'assigned', $row['ip'], $n->id, $asset, 'extra'));
                        ItAssets::save($cid, $asset->id, [], self::note($scan, "additional IP {$row['ip']} added"));
                        break;
                    }
                    $src(fn () => ItAssets::save($cid, $asset->id, $ipData($c['iface']), self::note($scan, "IP moved to {$row['ip']}")));
                    break;
                case 'other_location':
                    if (! $n->location_id) {
                        return __('This network has no location.');
                    }
                    $src(fn () => ItAssets::save($cid, $asset->id, ['location_id' => $n->location_id] + $ipData($iface), self::note($scan, "moved to this location with IP {$row['ip']}")));
                    break;
            }

            return null;
        });
    }
}
