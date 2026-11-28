<?php

namespace App\Support\ItAdmin;

use Illuminate\Support\Facades\DB;

/**
 * IT assets: reading one with its MACs / additional IPs, the networks of a location, the IP rule of HTD-IT (an IP lives in a
 * network of the asset's location and is never its network / broadcast address; duplicates are allowed and flagged), and the
 * one save every screen and the scanner use (IP history written from the before / after of the IP rows).
 */
class ItAssets
{
    public static function kinds(object $n): array
    {
        if ($n->type === 'VPN') {
            return [];
        }

        return array_values(array_filter([
            $n->type !== 'WAN' && $n->is_wired ? 'lan' : null,
            $n->is_wifi ? 'wifi' : null,
            $n->type === 'WAN' ? 'wan' : null,
        ]));
    }

    public static function contains(object $n, ?string $ip): bool
    {
        return IpCalc::contains($n->network_address, (int) $n->cidr, $ip);
    }

    /** Active networks of a location, most specific first. */
    public static function networksOf(int $companyId, ?int $locationId)
    {
        return DB::table('it_networks')->where('company_id', $companyId)->where('location_id', $locationId ?: 0)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'Inactive'))
            ->orderByDesc('cidr')->orderBy('name')->get();
    }

    /** [error|null, network id|null] for an IP of an asset of $locationId (the network picked, else found). */
    public static function resolveIp(int $companyId, ?string $ip, $networkId, ?int $locationId, ?string $originalIp = null, $originalNet = null, bool $required = true): array
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return [null, null];
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [__('":ip" is not a valid IPv4 address.', ['ip' => $ip]), null];
        }
        $nets = self::networksOf($companyId, $locationId);
        $n = $networkId ? $nets->firstWhere('id', (int) $networkId) : null;
        if ($networkId && ! $n) {
            return [__('Pick a network of the device\'s location.'), null];
        }
        $n ??= $nets->first(fn ($x) => self::contains($x, $ip));
        if (! $n) {
            if ($originalIp !== null && $ip === $originalIp) {
                return [null, $originalNet];                                   // a legacy IP kept as it was
            }

            return $required ? [__('IP :ip is not in any network of the device\'s location: pick its network or create it first.', ['ip' => $ip]), null] : [null, null];
        }
        if (! self::contains($n, $ip)) {
            return [__('IP :ip is not inside :net.', ['ip' => $ip, 'net' => "{$n->name} ({$n->network_address}/{$n->cidr})"]), null];
        }
        if ((int) $n->cidr < 31 && ($ip === $n->network_address || $ip === IpCalc::broadcast($n->network_address, (int) $n->cidr))) {
            return [__(':ip is the network or broadcast address of :net: use a host address (:first - :last).', ['ip' => $ip, 'net' => $n->name,
                'first' => IpCalc::firstHost($n->network_address, (int) $n->cidr), 'last' => IpCalc::lastHost($n->network_address, (int) $n->cidr)]), null];
        }

        return [null, (int) $n->id];
    }

    /** The asset with its details decoded, MACs and additional IPs. */
    public static function find(int $companyId, int $id): ?object
    {
        $a = DB::table('it_assets')->where('company_id', $companyId)->where('id', $id)->first();
        if ($a) {
            self::hydrate([$a]);
        }

        return $a;
    }

    /** Adds details (array), macs and extra_ips to asset rows (one query each). */
    public static function hydrate(iterable $assets): void
    {
        $list = collect($assets);
        $ids = $list->pluck('id')->all();
        $macs = DB::table('it_asset_macs')->whereIn('asset_id', $ids ?: [0])->orderBy('id')->get()->groupBy('asset_id');
        $extra = DB::table('it_asset_extra_ips')->whereIn('asset_id', $ids ?: [0])->orderBy('id')->get()->groupBy('asset_id');
        foreach ($list as $a) {
            $a->details = is_array($a->details) ? $a->details : (json_decode((string) $a->details, true) ?: []);
            $a->macs = $macs->get($a->id, collect())->values()->all();
            $a->extra_ips = $extra->get($a->id, collect())->values()->all();
        }
    }

    /** Insert or update an asset's row; the IP history records the IP rows that changed. Returns the id. */
    public static function save(int $companyId, ?int $id, array $data, ?string $sourceAppend = null): int
    {
        if (array_key_exists('details', $data) && is_array($data['details'])) {
            $data['details'] = json_encode($data['details'], JSON_UNESCAPED_UNICODE);
        }
        $before = $id ? (array) DB::table('it_assets')->where('company_id', $companyId)->where('id', $id)->first() : null;
        abort_if($id && ! $before, 404);
        if ($sourceAppend) {
            $data['source_note'] = trim(trim((string) ($before['source_note'] ?? '')) . "\n" . $sourceAppend);
        }
        $data['updated_at'] = now();
        if ($id) {
            DB::table('it_assets')->where('id', $id)->update($data);
        } else {
            $id = (int) DB::table('it_assets')->insertGetId($data + ['company_id' => $companyId, 'created_at' => now()]);
        }
        IpHistory::diff($companyId, $before, DB::table('it_assets')->where('id', $id)->first());

        return $id;
    }

    /** The asset goes: its IPs were in use until now. */
    public static function delete(int $companyId, int $id): void
    {
        $a = DB::table('it_assets')->where('company_id', $companyId)->where('id', $id)->first();
        abort_unless($a, 404);
        DB::transaction(function () use ($companyId, $a) {
            foreach (IpHistory::ROWS as $iface => [$ipCol, $netCol]) {
                if ($a->$ipCol) {
                    IpHistory::record($companyId, 'removed', $a->$ipCol, $a->$netCol ? (int) $a->$netCol : null, $a, $iface);
                }
            }
            foreach (DB::table('it_asset_extra_ips')->where('asset_id', $a->id)->get() as $x) {
                IpHistory::record($companyId, 'removed', $x->ip_address, $x->network_id, $a, 'extra');
            }
            DB::table('it_asset_macs')->where('asset_id', $a->id)->delete();
            DB::table('it_asset_extra_ips')->where('asset_id', $a->id)->delete();
            DB::table('it_assets')->where('id', $a->id)->delete();
            DB::table('it_assets')->where('company_id', $companyId)->where('uplink_asset_id', $a->id)->update(['uplink_asset_id' => null]);
            DB::table('it_networks')->where('company_id', $companyId)->where('linked_asset_id', $a->id)->update(['linked_asset_id' => null]);
        });
    }
}
