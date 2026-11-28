<?php

namespace App\Http\Controllers\ItAdmin;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\ItAdmin\IpCalc;
use App\Support\ItAdmin\IpHistory;
use App\Support\ItAdmin\ItAssets;
use App\Support\ItAdmin\ItAssetTypes;
use App\Support\ItAdmin\MacVendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * IT Admin, phase 1 (2026-11-28, from HTD-IT): locations, IP networks (subnet calculator, the IP list with assign / unassign and
 * each IP's history) and IT assets by category (type fields, MACs, the four IP rows + additional IPs). The acting company only;
 * itadmin_view to read, itadmin_manage to write. The scans are NetworkScanController.
 *
 *  GET itadmin/options                 GET itadmin/locations       POST / PUT / DELETE itadmin/locations[/{id}]
 *  GET itadmin/networks                GET itadmin/networks/{id}   POST / PUT / DELETE itadmin/networks[/{id}]
 *  GET itadmin/networks-calc?network   GET itadmin/networks/{id}/ips   POST itadmin/networks/{id}/ips/assign | unassign
 *  GET itadmin/networks/{id}/ips/history?ip
 *  GET itadmin/assets?category&location_id&status   GET itadmin/assets/{id}   POST / PUT / DELETE itadmin/assets[/{id}]
 */
class ItAdminController extends Controller
{
    use ScopesToCompany;

    public const NETWORK_TYPES = ['LAN', 'WAN', 'GUEST', 'LAN-HOME', 'VPN'];

    protected function allow(Request $request, string $perm): void
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), $perm), 403, __('You do not have permission for this.'));
    }

    protected function can(Request $request, string $perm): bool
    {
        return HelpdeskAccess::can($request->user('api'), $perm);
    }

    protected function company(Request $request): int
    {
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if(! $cid || $cid === 999, 422, __('Pick a company in the header first.'));

        return $cid;
    }

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => $msg]);
    }

    // ------------------------------------------------------------------ options

    public function options(Request $request)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);

        return response()->json([
            'locations' => DB::table('it_locations')->where('company_id', $cid)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'code', 'status']),
            'networks' => DB::table('it_networks')->where('company_id', $cid)->orderBy('name')->get(['id', 'name', 'location_id', 'network_address', 'cidr', 'type', 'is_wired', 'is_wifi', 'status']),
            'employees' => DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('firstname')
                ->get(['id', DB::raw("TRIM(CONCAT(firstname, ' ', COALESCE(lastname, ''))) as name")]),
            'warehouses' => DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'types' => ItAssetTypes::forScreen(),
            'categories' => collect(ItAssetTypes::CATEGORIES)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'types' => $c[1]])->values(),
            'network_types' => self::NETWORK_TYPES, 'statuses' => ItAssetTypes::STATUSES, 'work_modes' => ItAssetTypes::WORK_MODES,
            'uplink_media' => ItAssetTypes::UPLINK_MEDIA, 'requires_ip' => ItAssetTypes::REQUIRES_IP,
            'can' => ['manage' => $this->can($request, 'itadmin_manage'), 'scan' => $this->can($request, 'itadmin_scan')],
        ]);
    }

    // ---------------------------------------------------------------- locations

    public function locations(Request $request)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        $q = DB::table('it_locations as l')->where('l.company_id', $cid)
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('l.name', 'like', "%{$request->search}%")->orWhere('l.code', 'like', "%{$request->search}%")));
        $total = (clone $q)->count();
        $rows = $q->leftJoin('employees as e', 'e.id', '=', 'l.manager_employee_id')->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->orderBy('l.sort_order')->orderBy('l.name')->get(['l.*', DB::raw("TRIM(CONCAT(e.firstname, ' ', COALESCE(e.lastname, ''))) as manager"), 'w.name as warehouse']);
        $nets = DB::table('it_networks')->where('company_id', $cid)->select('location_id', DB::raw('count(*) c'))->groupBy('location_id')->pluck('c', 'location_id');
        $assets = DB::table('it_assets')->where('company_id', $cid)->select('location_id', DB::raw('count(*) c'))->groupBy('location_id')->pluck('c', 'location_id');
        foreach ($rows as $r) {
            $r->networks = (int) ($nets[$r->id] ?? 0);
            $r->assets = (int) ($assets[$r->id] ?? 0);
        }

        return response()->json(['locations' => $rows, 'totalRows' => $total]);
    }

    public function saveLocation(Request $request, ?int $id = null)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        $d = $request->validate(['name' => 'required|string|max:191', 'code' => 'nullable|string|max:20|regex:/^[A-Za-z0-9.\-]*$/',
            'address' => 'nullable|string', 'phone' => 'nullable|string|max:40', 'manager_employee_id' => 'nullable|integer', 'warehouse_id' => 'nullable|integer',
            'sort_order' => 'nullable|integer|min:0|max:65000', 'status' => 'nullable|in:Active,Inactive', 'notes' => 'nullable|string']);
        if (! empty($d['manager_employee_id']) && ! DB::table('employees')->where('company_id', $cid)->where('id', $d['manager_employee_id'])->exists()) {
            $this->fail('manager_employee_id', __('Employee not found in this company.'));
        }
        if (! empty($d['warehouse_id']) && ! DB::table('warehouses')->where('company_id', $cid)->where('id', $d['warehouse_id'])->exists()) {
            $this->fail('warehouse_id', __('Location not found in this company.'));
        }
        $d['sort_order'] = $d['sort_order'] ?? 100;
        $d['status'] = $d['status'] ?? 'Active';
        $d['updated_at'] = now();
        if ($id) {
            abort_unless(DB::table('it_locations')->where('company_id', $cid)->where('id', $id)->update($d) !== false
                && DB::table('it_locations')->where('company_id', $cid)->where('id', $id)->exists(), 404);
        } else {
            $id = DB::table('it_locations')->insertGetId($d + ['company_id' => $cid, 'created_at' => now()]);
        }

        return response()->json(['success' => true, 'id' => $id]);
    }

    public function deleteLocation(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        $nets = DB::table('it_networks')->where('company_id', $cid)->where('location_id', $id)->count();
        $assets = DB::table('it_assets')->where('company_id', $cid)->where('location_id', $id)->count();
        abort_if($nets || $assets, 422, __('This location still has :n networks and :a devices: move or delete them first.', ['n' => $nets, 'a' => $assets]));
        abort_unless(DB::table('it_locations')->where('company_id', $cid)->where('id', $id)->delete(), 404);

        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------------- networks

    public function networks(Request $request)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        $q = DB::table('it_networks as n')->leftJoin('it_locations as l', 'l.id', '=', 'n.location_id')->where('n.company_id', $cid)
            ->when($request->filled('location_id'), fn ($q) => $q->where('n.location_id', (int) $request->location_id))
            ->when($request->filled('type'), fn ($q) => $q->where('n.type', $request->type))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('n.name', 'like', "%{$request->search}%")
                ->orWhere('n.network_address', 'like', "%{$request->search}%")->orWhere('n.vlan', 'like', "%{$request->search}%")->orWhere('l.name', 'like', "%{$request->search}%")));
        $total = (clone $q)->count();
        $sort = in_array($request->SortField, ['name', 'network_address', 'type', 'vlan', 'status'], true) ? 'n.' . $request->SortField : 'l.sort_order';
        $rows = $q->orderBy($sort, $request->SortType === 'desc' ? 'desc' : 'asc')->orderBy('n.name')
            ->offset(max(0, ((int) $request->page - 1) * (int) ($request->limit ?: 20)))->limit((int) ($request->limit ?: 20) > 0 ? (int) ($request->limit ?: 20) : 100000)
            ->get(['n.id', 'n.name', 'n.location_id', 'l.name as location', 'n.network_address', 'n.cidr', 'n.type', 'n.is_wired', 'n.is_wifi', 'n.vlan', 'n.gateway',
                'n.dhcp_enabled', 'n.status', 'n.wifi24_ssid', 'n.wifi5_ssid', 'n.wifi6_ssid']);
        $ids = $rows->pluck('id')->all();
        $used = [];
        foreach (IpHistory::ROWS as [$ipCol, $netCol]) {
            foreach (DB::table('it_assets')->where('company_id', $cid)->whereIn($netCol, $ids ?: [0])->whereNotNull($ipCol)->get([$ipCol, $netCol]) as $a) {
                $used[$a->$netCol][$a->$ipCol] = true;
            }
        }
        foreach (DB::table('it_asset_extra_ips')->whereIn('network_id', $ids ?: [0])->get(['network_id', 'ip_address']) as $x) {
            $used[$x->network_id][$x->ip_address] = true;
        }
        $lastScan = DB::table('it_network_scans')->whereIn('network_id', $ids ?: [0])->whereNotNull('uploaded_at')
            ->select('network_id', DB::raw('MAX(uploaded_at) as at'))->groupBy('network_id')->pluck('at', 'network_id');
        $toReview = DB::table('it_network_scans')->whereIn('network_id', $ids ?: [0])->where('status', 'uploaded')
            ->select('network_id', DB::raw('count(*) c'))->groupBy('network_id')->pluck('c', 'network_id');
        foreach ($rows as $r) {
            $r->mask = IpCalc::mask((int) $r->cidr);
            $r->usable = IpCalc::usableHosts((int) $r->cidr);
            $r->used = count($used[$r->id] ?? []);
            $r->last_scan = $lastScan[$r->id] ?? null;
            $r->scans_to_review = (int) ($toReview[$r->id] ?? 0);
        }

        return response()->json(['networks' => $rows, 'totalRows' => $total]);
    }

    public function network(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        $n = DB::table('it_networks')->where('company_id', $cid)->where('id', $id)->first();
        abort_unless($n, 404);
        $manage = $this->can($request, 'itadmin_manage');
        foreach (['24', '5', '6'] as $b) {
            $col = "wifi{$b}_password";
            $n->{"wifi{$b}_has_password"} = (bool) $n->$col;
            // The Wi-Fi passwords are shown to whoever may edit the network only.
            $n->$col = $manage && $n->$col ? rescue(fn () => Crypt::decryptString($n->$col), null, false) : null;
        }
        $n->summary = IpCalc::summary($n->network_address, (int) $n->cidr);

        return response()->json(['network' => $n]);
    }

    public function calc(Request $request)
    {
        $this->allow($request, 'itadmin_view');
        $p = IpCalc::parse((string) $request->query('network', ''));

        return response()->json($p ? ['ok' => true] + IpCalc::summary($p[0], $p[1]) + ['cidr' => $p[1]] : ['ok' => false]);
    }

    public function saveNetwork(Request $request, ?int $id = null)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        $ip = 'nullable|ipv4';
        $d = $request->validate(['name' => 'required|string|max:191', 'location_id' => 'required|integer', 'network' => 'required|string|max:40',
            'type' => 'required|in:' . implode(',', self::NETWORK_TYPES), 'is_wired' => 'boolean', 'is_wifi' => 'boolean', 'vlan' => 'nullable|string|max:50',
            'gateway' => $ip, 'dns1' => $ip, 'dns2' => $ip, 'dhcp_enabled' => 'boolean', 'dhcp_start' => $ip, 'dhcp_end' => $ip,
            'wifi24_enabled' => 'boolean', 'wifi24_ssid' => 'nullable|string|max:191', 'wifi24_password' => 'nullable|string|max:191',
            'wifi5_enabled' => 'boolean', 'wifi5_ssid' => 'nullable|string|max:191', 'wifi5_password' => 'nullable|string|max:191',
            'wifi6_enabled' => 'boolean', 'wifi6_ssid' => 'nullable|string|max:191', 'wifi6_password' => 'nullable|string|max:191',
            'linked_asset_id' => 'nullable|integer', 'notes' => 'nullable|string', 'status' => 'nullable|in:Active,Inactive']);
        abort_unless(DB::table('it_locations')->where('company_id', $cid)->where('id', $d['location_id'])->exists(), 422, __('Location not found in this company.'));
        $p = IpCalc::parse($d['network']) ?? $this->fail('network', __('Write the network as 192.168.1.0/24 or 192.168.1.0 255.255.255.0.'));
        [$addr, $cidr] = [IpCalc::network($p[0], $p[1]), $p[1]];
        foreach (['gateway', 'dhcp_start', 'dhcp_end'] as $f) {
            if (! empty($d[$f]) && ! IpCalc::contains($addr, $cidr, $d[$f])) {
                $this->fail($f, __(':ip is not inside :net.', ['ip' => $d[$f], 'net' => "$addr/$cidr"]));
            }
        }
        $dup = DB::table('it_networks')->where('company_id', $cid)->where('network_address', $addr)->where('cidr', $cidr)
            ->where('location_id', $d['location_id'])->when($id, fn ($q) => $q->where('id', '<>', $id))->value('name');
        if ($dup) {
            $this->fail('network', __(':net is already registered in this location (:name).', ['net' => "$addr/$cidr", 'name' => $dup]));
        }
        if (! empty($d['linked_asset_id']) && ! DB::table('it_assets')->where('company_id', $cid)->where('id', $d['linked_asset_id'])->exists()) {
            $this->fail('linked_asset_id', __('Device not found in this company.'));
        }
        $old = $id ? DB::table('it_networks')->where('company_id', $cid)->where('id', $id)->first() : null;
        abort_if($id && ! $old, 404);
        $row = collect($d)->except(['network', 'wifi24_password', 'wifi5_password', 'wifi6_password'])->all();
        $row += ['network_address' => $addr, 'cidr' => $cidr, 'status' => $d['status'] ?? 'Active', 'updated_at' => now()];
        foreach (['24', '5', '6'] as $b) {
            // A password left empty on an edit keeps the stored one; "clear_wifiNN" removes it.
            $pw = $d["wifi{$b}_password"] ?? null;
            if ($pw !== null && $pw !== '') {
                $row["wifi{$b}_password"] = Crypt::encryptString($pw);
            } elseif (! $id || $request->boolean("clear_wifi{$b}")) {
                $row["wifi{$b}_password"] = null;
            }
        }
        if ($id) {
            DB::table('it_networks')->where('id', $id)->update($row);
        } else {
            $id = DB::table('it_networks')->insertGetId($row + ['company_id' => $cid, 'created_at' => now()]);
        }

        return response()->json(['success' => true, 'id' => $id]);
    }

    public function deleteNetwork(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        abort_unless(DB::table('it_networks')->where('company_id', $cid)->where('id', $id)->exists(), 404);
        $using = 0;
        foreach (IpHistory::ROWS as [, $netCol]) {
            $using += DB::table('it_assets')->where('company_id', $cid)->where($netCol, $id)->count();
        }
        abort_if($using, 422, __(':n devices have an IP on this network: take them off first.', ['n' => $using]));
        DB::transaction(function () use ($id) {
            DB::table('it_asset_extra_ips')->where('network_id', $id)->delete();
            $scans = DB::table('it_network_scans')->where('network_id', $id)->pluck('id');
            DB::table('it_network_scan_results')->whereIn('scan_id', $scans)->delete();
            DB::table('it_network_scans')->whereIn('id', $scans)->delete();
            DB::table('it_networks')->where('id', $id)->delete();
        });

        return response()->json(['success' => true]);
    }

    /** Every host IP of the network, who has it (any interface or an additional IP), duplicates and the last time a scan saw it. */
    public function ips(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        $n = DB::table('it_networks')->where('company_id', $cid)->where('id', $id)->first();
        abort_unless($n, 404);
        abort_if((int) $n->cidr < 20, 422, __('The IP list is shown for networks up to /20 (4,094 IPs).'));
        $holders = [];
        $assets = DB::table('it_assets')->where('company_id', $cid)->where(function ($q) use ($id) {
            foreach (IpHistory::ROWS as [, $netCol]) {
                $q->orWhere($netCol, $id);
            }
        })->get();
        ItAssets::hydrate($assets);
        foreach ($assets as $a) {
            foreach (IpHistory::ROWS as $iface => [$ipCol, $netCol]) {
                if ($a->$ipCol && (int) $a->$netCol === $id) {
                    $holders[$a->$ipCol][] = ['asset_id' => $a->id, 'tag' => $a->asset_tag, 'type' => $a->type, 'iface' => $iface, 'hostname' => $a->details['hostname'] ?? null,
                        'mac' => collect($a->macs)->first()->mac ?? null];
                }
            }
        }
        foreach (DB::table('it_asset_extra_ips as x')->join('it_assets as a', 'a.id', '=', 'x.asset_id')->where('x.network_id', $id)->get(['x.ip_address', 'x.label', 'a.id', 'a.asset_tag', 'a.type']) as $x) {
            $holders[$x->ip_address][] = ['asset_id' => $x->id, 'tag' => $x->asset_tag, 'type' => $x->type, 'iface' => 'extra', 'hostname' => $x->label, 'mac' => null];
        }
        $seen = DB::table('it_ip_history')->where('network_id', $id)->where('event', 'seen')->select('ip', DB::raw('MAX(last_at) as at'))->groupBy('ip')->pluck('at', 'ip');
        $hist = DB::table('it_ip_history')->where('network_id', $id)->distinct()->pluck('ip')->flip();
        $rows = [];
        $from = IpCalc::toLong(IpCalc::firstHost($n->network_address, (int) $n->cidr));
        $to = IpCalc::toLong(IpCalc::lastHost($n->network_address, (int) $n->cidr));
        for ($l = $from; $l <= $to; $l++) {
            $ip = IpCalc::fromLong($l);
            $rows[] = ['ip' => $ip, 'holders' => $holders[$ip] ?? [], 'gateway' => $ip === $n->gateway,
                'dhcp' => $n->dhcp_enabled && $n->dhcp_start && $n->dhcp_end && $l >= IpCalc::toLong($n->dhcp_start) && $l <= IpCalc::toLong($n->dhcp_end),
                'last_seen' => $seen[$ip] ?? null, 'has_history' => isset($hist[$ip])];
        }

        return response()->json(['network' => ['id' => $n->id, 'name' => $n->name, 'network_address' => $n->network_address, 'cidr' => (int) $n->cidr, 'kinds' => ItAssets::kinds($n)],
            'rows' => $rows, 'used' => count($holders), 'duplicates' => count(array_filter($holders, fn ($h) => count($h) > 1))]);
    }

    public function ipHistory(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        abort_unless(DB::table('it_networks')->where('company_id', $cid)->where('id', $id)->exists(), 404);

        return response()->json(['rows' => DB::table('it_ip_history as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->where('h.network_id', $id)->where('h.ip', (string) $request->query('ip'))->orderByDesc('h.last_at')->limit(200)
            ->get(['h.event', 'h.asset_id', 'h.asset_tag', 'h.hostname', 'h.mac', 'h.interface', 'h.source', 'h.first_at', 'h.last_at', 'h.times_seen', 'u.username'])]);
    }

    /** Puts a device on an IP of this network (its LAN / Wi-Fi / WAN / MGMT row), same rules as the asset form. */
    public function assignIp(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        $d = $request->validate(['ip' => 'required|ipv4', 'asset_id' => 'required|integer', 'iface' => 'required|in:lan,wifi,wan,mgmt']);
        $n = DB::table('it_networks')->where('company_id', $cid)->where('id', $id)->first();
        $a = ItAssets::find($cid, (int) $d['asset_id']);
        abort_unless($n && $a, 404);
        if ((int) $a->location_id !== (int) $n->location_id) {
            $this->fail('asset_id', __('The device is in another location.'));
        }
        [$err] = ItAssets::resolveIp($cid, $d['ip'], $id, (int) $n->location_id);
        if ($err) {
            $this->fail('ip', $err);
        }
        [$ipCol, $netCol] = IpHistory::ROWS[$d['iface']];
        ItAssets::save($cid, $a->id, [$ipCol => $d['ip'], $netCol => $id] + ($d['iface'] === 'lan' ? ['no_ip' => false] : []));

        return response()->json(['success' => true]);
    }

    public function unassignIp(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        $d = $request->validate(['ip' => 'required|ipv4', 'asset_id' => 'required|integer']);
        $a = ItAssets::find($cid, (int) $d['asset_id']);
        abort_unless($a, 404);
        $data = [];
        foreach (IpHistory::ROWS as [$ipCol, $netCol]) {
            if ($a->$ipCol === $d['ip'] && (int) $a->$netCol === $id) {
                $data += [$ipCol => null, $netCol => null];
            }
        }
        $extra = DB::table('it_asset_extra_ips')->where('asset_id', $a->id)->where('ip_address', $d['ip'])->where('network_id', $id);
        if ($extra->exists()) {
            $extra->delete();
            IpHistory::record($cid, 'removed', $d['ip'], $id, $a, 'extra', 'IP list');
        }
        IpHistory::withSource('IP list', null, fn () => ItAssets::save($cid, $a->id, $data));

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------- assets

    public function assets(Request $request)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        $q = DB::table('it_assets as a')->leftJoin('it_locations as l', 'l.id', '=', 'a.location_id')->leftJoin('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('a.company_id', $cid)
            ->when($request->filled('location_id'), fn ($q) => $q->where('a.location_id', (int) $request->location_id))
            ->when($request->filled('status'), fn ($q) => $q->where('a.status', $request->status))
            ->when($request->filled('type'), fn ($q) => $q->where('a.type', $request->type))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = "%{$request->search}%";
                $mac = MacVendor::normalize($request->search);
                $q->where(fn ($w) => $w->where('a.asset_tag', 'like', $s)->orWhere('a.model', 'like', $s)->orWhere('a.manufacturer', 'like', $s)->orWhere('a.serial_number', 'like', $s)
                    ->orWhere('a.ip_address', 'like', $s)->orWhere('a.wifi_ip_address', 'like', $s)->orWhere('a.details', 'like', $s)
                    ->orWhere(DB::raw("CONCAT(e.firstname, ' ', COALESCE(e.lastname, ''))"), 'like', $s)
                    ->orWhereExists(fn ($m) => $m->from('it_asset_macs as m')->whereColumn('m.asset_id', 'a.id')->where('m.mac', 'like', $mac ? $mac : $s)));
            });
        ItAssetTypes::applyCategory($q, $request->query('category'), 'a.type');
        $counts = [];
        foreach (array_keys(ItAssetTypes::CATEGORIES) as $k) {
            $c = DB::table('it_assets')->where('company_id', $cid);
            ItAssetTypes::applyCategory($c, $k);
            $counts[$k] = $c->count();
        }
        $total = (clone $q)->count();
        $sort = in_array($request->SortField, ['asset_tag', 'type', 'model', 'ip_address', 'status', 'warranty_end'], true) ? 'a.' . $request->SortField : 'a.asset_tag';
        $limit = (int) ($request->limit ?: 20);
        $rows = $q->orderBy($sort, $request->SortType === 'desc' ? 'desc' : 'asc')
            ->offset(max(0, ((int) $request->page - 1) * $limit))->limit($limit > 0 ? $limit : 100000)
            ->get(['a.*', 'l.name as location', DB::raw("TRIM(CONCAT(e.firstname, ' ', COALESCE(e.lastname, ''))) as employee")]);
        ItAssets::hydrate($rows);
        $dups = [];
        foreach (['ip_address', 'wifi_ip_address'] as $col) {
            foreach (DB::table('it_assets')->where('company_id', $cid)->whereNotNull($col)->select($col, DB::raw('count(*) c'))->groupBy($col)->having('c', '>', 1)->pluck($col) as $ip) {
                $dups[$ip] = true;
            }
        }
        foreach ($rows as $r) {
            $r->hostname = $r->details['hostname'] ?? null;
            $r->duplicate_ip = isset($dups[$r->ip_address]) || isset($dups[$r->wifi_ip_address]);
            unset($r->source_note);
        }

        return response()->json(['assets' => $rows, 'totalRows' => $total, 'counts' => $counts]);
    }

    public function asset(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_view');
        $a = ItAssets::find($this->company($request), $id);
        abort_unless($a, 404);

        return response()->json(['asset' => $a]);
    }

    public function saveAsset(Request $request, ?int $id = null)
    {
        $this->allow($request, 'itadmin_manage');
        $cid = $this->company($request);
        $types = array_keys(ItAssetTypes::types());
        $d = $request->validate(['asset_tag' => 'required|string|max:60', 'type' => 'required|in:' . implode(',', $types), 'location_id' => 'required|integer',
            'manufacturer' => 'nullable|string|max:191', 'model' => 'nullable|string|max:191', 'serial_number' => 'nullable|string|max:191',
            'ip_address' => 'nullable|string|max:45', 'ip_network_id' => 'nullable|integer', 'no_ip' => 'boolean',
            'wifi_ip_address' => 'nullable|string|max:45', 'wifi_network_id' => 'nullable|integer',
            'wan_ip_address' => 'nullable|string|max:45', 'wan_network_id' => 'nullable|integer',
            'mgmt_ip_address' => 'nullable|string|max:45', 'mgmt_network_id' => 'nullable|integer',
            'uplink_asset_id' => 'nullable|integer', 'uplink_medium' => 'nullable|in:' . implode(',', ItAssetTypes::UPLINK_MEDIA),
            'employee_id' => 'nullable|integer', 'assigned_note' => 'nullable|string|max:191', 'purchase_date' => 'nullable|date', 'warranty_end' => 'nullable|date',
            'status' => 'required|in:' . implode(',', ItAssetTypes::STATUSES), 'work_mode' => 'nullable|in:' . implode(',', ItAssetTypes::WORK_MODES),
            'notes' => 'nullable|string', 'details' => 'nullable|array',
            'macs' => 'nullable|array', 'macs.*.mac' => 'nullable|string|max:30', 'macs.*.label' => 'nullable|string|max:60',
            'extra_ips' => 'nullable|array', 'extra_ips.*.ip_address' => 'nullable|string|max:45', 'extra_ips.*.network_id' => 'nullable|integer', 'extra_ips.*.label' => 'nullable|string|max:100']);
        $loc = (int) $d['location_id'];
        abort_unless(DB::table('it_locations')->where('company_id', $cid)->where('id', $loc)->exists(), 422, __('Location not found in this company.'));
        if (DB::table('it_assets')->where('company_id', $cid)->where('asset_tag', $d['asset_tag'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            $this->fail('asset_tag', __('Another device already has the tag :tag.', ['tag' => $d['asset_tag']]));
        }
        foreach (['employee_id' => 'employees', 'uplink_asset_id' => 'it_assets'] as $f => $t) {
            if (! empty($d[$f]) && ! DB::table($t)->where('company_id', $cid)->where('id', $d[$f])->exists()) {
                $this->fail($f, __('Not found in this company.'));
            }
        }
        $old = $id ? DB::table('it_assets')->where('company_id', $cid)->where('id', $id)->first() : null;
        abort_if($id && ! $old, 404);

        // The four IP rows: each inside a network of the location (WAN may be a public IP with no network registered).
        foreach (IpHistory::ROWS as $iface => [$ipCol, $netCol]) {
            [$err, $net] = ItAssets::resolveIp($cid, $d[$ipCol] ?? null, $d[$netCol] ?? null, $loc, $old->$ipCol ?? null, $old->$netCol ?? null, $iface !== 'wan');
            if ($err) {
                $this->fail($ipCol, $err);
            }
            $d[$ipCol] = trim((string) ($d[$ipCol] ?? '')) ?: null;
            $d[$netCol] = $net;
        }
        if (in_array($d['type'], ItAssetTypes::REQUIRES_IP, true) && ! $d['ip_address'] && ! $d['wan_ip_address'] && empty($d['no_ip'])) {
            $this->fail('ip_address', __('A :type needs its IP.', ['type' => $d['type']]));
        }
        $macs = [];
        foreach ((array) ($d['macs'] ?? []) as $i => $m) {
            if (! trim((string) ($m['mac'] ?? ''))) {
                continue;
            }
            $mac = MacVendor::normalize($m['mac']) ?? $this->fail("macs.$i.mac", __('":mac" is not a MAC address.', ['mac' => $m['mac']]));
            $taken = DB::table('it_asset_macs as m')->join('it_assets as a', 'a.id', '=', 'm.asset_id')->where('a.company_id', $cid)->where('m.mac', $mac)
                ->when($id, fn ($q) => $q->where('a.id', '<>', $id))->value('a.asset_tag');
            if ($taken || isset($macs[$mac])) {
                $this->fail("macs.$i.mac", __(':mac is already registered on :tag.', ['mac' => $mac, 'tag' => $taken ?: $d['asset_tag']]));
            }
            $macs[$mac] = mb_substr(trim((string) ($m['label'] ?? '')), 0, 60) ?: null;
        }
        $own = array_filter([$d['ip_address'], $d['wifi_ip_address'], $d['wan_ip_address'], $d['mgmt_ip_address']]);
        $extras = [];
        foreach ((array) ($d['extra_ips'] ?? []) as $i => $x) {
            $ip = trim((string) ($x['ip_address'] ?? ''));
            if ($ip === '') {
                continue;
            }
            [$err, $net] = ItAssets::resolveIp($cid, $ip, $x['network_id'] ?? null, $loc);
            $err ??= in_array($ip, $own, true) ? __(':ip is already one of this device\'s main IPs.', ['ip' => $ip]) : (isset($extras[$ip]) ? __(':ip is listed twice.', ['ip' => $ip]) : null);
            if ($err) {
                $this->fail("extra_ips.$i.ip_address", $err);
            }
            $extras[$ip] = ['network_id' => $net, 'label' => mb_substr(trim((string) ($x['label'] ?? '')), 0, 100) ?: null];
        }
        // Only the type's own fields are kept in details.
        $fields = ItAssetTypes::types()[$d['type']];
        $details = array_filter(array_intersect_key((array) ($d['details'] ?? []), $fields), fn ($v) => $v !== null && $v !== '');
        $row = collect($d)->except(['macs', 'extra_ips', 'details'])->all() + ['details' => $details];
        $row['no_ip'] = ! empty($d['no_ip']);
        $row['work_mode'] = $d['work_mode'] ?? 'Office';

        $id = DB::transaction(function () use ($cid, $id, $row, $macs, $extras) {
            $id = ItAssets::save($cid, $id, $row);
            DB::table('it_asset_macs')->where('asset_id', $id)->whereNotIn('mac', array_keys($macs) ?: ['-'])->delete();
            foreach ($macs as $mac => $label) {
                DB::table('it_asset_macs')->updateOrInsert(['asset_id' => $id, 'mac' => $mac], ['label' => $label, 'updated_at' => now(), 'created_at' => now()]);
            }
            $asset = DB::table('it_assets')->where('id', $id)->first();
            foreach (DB::table('it_asset_extra_ips')->where('asset_id', $id)->get() as $x) {
                if (! isset($extras[$x->ip_address])) {
                    DB::table('it_asset_extra_ips')->where('id', $x->id)->delete();
                    IpHistory::record($cid, 'removed', $x->ip_address, $x->network_id, $asset, 'extra');
                } else {
                    DB::table('it_asset_extra_ips')->where('id', $x->id)->update($extras[$x->ip_address] + ['updated_at' => now()]);
                    unset($extras[$x->ip_address]);
                }
            }
            foreach ($extras as $ip => $x) {
                DB::table('it_asset_extra_ips')->insert($x + ['asset_id' => $id, 'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now()]);
                IpHistory::record($cid, 'assigned', $ip, $x['network_id'], $asset, 'extra');
            }

            return $id;
        });

        return response()->json(['success' => true, 'id' => $id]);
    }

    public function deleteAsset(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_manage');
        ItAssets::delete($this->company($request), $id);

        return response()->json(['success' => true]);
    }
}
