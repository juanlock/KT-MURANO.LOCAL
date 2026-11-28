<?php

namespace App\Http\Controllers\ItAdmin;

use App\Support\ItAdmin\NetworkScanner;
use App\Support\PublicUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IT Admin -> Networks IP -> [Scan this network] and Scan imports (from HTD-IT, 2026-11-28). Starting a scan makes a one-use
 * token (2 hours); the page offers the PowerShell script (uploads by itself) or one cmd line of Windows' own tools (its text
 * file is uploaded on the page). The script's upload is public (api/itadmin-scan/upload): the token in X-Scan-Token is the
 * permission. Then the review: per found host, apply its changes / use what the scan saw / ignore; reviewed or discarded.
 *
 *  POST itadmin/networks/{id}/scan          GET itadmin/scans/{id}/script     GET itadmin/scans/{id}/status
 *  POST itadmin/scans/{id}/upload-file      GET itadmin/scans                 GET itadmin/scans/{id}
 *  POST itadmin/scans/{id}/apply | use-new | ignore {id}     POST itadmin/scans/{id}/reviewed | discard
 *  POST api/itadmin-scan/upload (public, X-Scan-Token)
 */
class NetworkScanController extends ItAdminController
{
    private function scan(Request $request, int $id): object
    {
        $s = DB::table('it_network_scans')->where('company_id', $this->company($request))->where('id', $id)->first();
        abort_unless($s, 404);

        return $s;
    }

    public function start(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_scan');
        $cid = $this->company($request);
        $n = NetworkScanner::network($cid, $id);
        abort_if((int) $n->cidr < 16, 422, __('A network larger than a /16 cannot be scanned.'));
        $token = Str::random(40);
        $sid = DB::table('it_network_scans')->insertGetId(['company_id' => $cid, 'network_id' => $n->id, 'created_by' => $request->user('api')->id,
            'token_hash' => hash('sha256', $token), 'token_enc' => Crypt::encryptString($token), 'expires_at' => now()->addHours(2), 'status' => 'waiting',
            'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['scan_id' => $sid, 'arp_command' => NetworkScanner::arpCommand($n), 'file_tag' => NetworkScanner::fileTag($n),
            'expires_at' => now()->addHours(2)->toDateTimeString()]);
    }

    /** The PowerShell script of a waiting scan (its token inside). */
    public function script(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_scan');
        $s = $this->scan($request, $id);
        abort_unless($s->status === 'waiting' && $s->token_enc && now()->lt($s->expires_at), 410, __('This scan was already used or expired: start a new one.'));
        $n = DB::table('it_networks')->where('id', $s->network_id)->first();
        // The server's own address (APP_URL), never a company's public website: the API lives there.
        $body = NetworkScanner::script($s, $n, Crypt::decryptString($s->token_enc), PublicUrl::server() . '/api/itadmin-scan/upload');

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="scan-' . NetworkScanner::fileTag($n) . '.ps1"']);
    }

    public function status(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_view');
        $s = $this->scan($request, $id);

        return response()->json(['status' => $s->status, 'found' => (int) $s->found_count, 'uploaded_at' => $s->uploaded_at,
            'computer' => $s->scanner_computer, 'expired' => $s->status === 'waiting' && now()->gte($s->expires_at)]);
    }

    /** The public upload of the script: the one-use token is the permission. */
    public function upload(Request $request)
    {
        $token = (string) $request->header('X-Scan-Token');
        $s = strlen($token) === 40 ? DB::table('it_network_scans')->where('token_hash', hash('sha256', $token))->where('status', 'waiting')->first() : null;
        if (! $s || now()->gte($s->expires_at)) {
            return response()->json(['message' => 'This scan link was already used or expired: start a new scan on the page.'], 403);
        }
        $payload = $request->json()->all();
        abort_unless(is_array($payload['results'] ?? null), 422, 'No results.');

        return response()->json(['message' => NetworkScanner::ingest($s, $payload, $request->ip()) . ' devices received.']);
    }

    /** By hand: the .scan file of the script (JSON) or the text file of the cmd line ("arp -a"). */
    public function uploadFile(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_scan');
        $s = $this->scan($request, $id);
        abort_unless($s->status === 'waiting', 422, __('This scan already has its result: start a new one.'));
        $request->validate(['file' => 'required|file|max:5120']);
        $text = (string) file_get_contents($request->file('file')->getRealPath());
        $n = DB::table('it_networks')->where('id', $s->network_id)->first();
        $clean = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
        $json = json_decode($clean, true);
        $payload = is_array($json) && is_array($json['results'] ?? null) ? $json : NetworkScanner::parseArp($text, $n);
        abort_unless($payload, 422, __('No device of this network is in the file: run it on a computer that is IN the office (over VPN the devices are not seen).'));

        return response()->json(['success' => true, 'found' => NetworkScanner::ingest($s, $payload, null)]);
    }

    public function index(Request $request)
    {
        $this->allow($request, 'itadmin_view');
        $cid = $this->company($request);
        $q = DB::table('it_network_scans as s')->join('it_networks as n', 'n.id', '=', 's.network_id')->leftJoin('it_locations as l', 'l.id', '=', 'n.location_id')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')->where('s.company_id', $cid)->where('s.status', '<>', 'waiting')
            ->when($request->filled('status'), fn ($q) => $q->where('s.status', $request->status))
            ->when($request->filled('network_id'), fn ($q) => $q->where('s.network_id', (int) $request->network_id))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('n.name', 'like', "%{$request->search}%")->orWhere('s.scanner_computer', 'like', "%{$request->search}%")));
        $total = (clone $q)->count();
        $limit = (int) ($request->limit ?: 20);
        $rows = $q->orderByDesc('s.uploaded_at')->offset(max(0, ((int) $request->page - 1) * $limit))->limit($limit > 0 ? $limit : 100000)
            ->get(['s.id', 's.status', 's.uploaded_at', 's.scanned_at', 's.scanner_computer', 's.scanner_ip', 's.directly_connected', 's.hosts_scanned', 's.found_count',
                'n.id as network_id', 'n.name as network', 'n.network_address', 'n.cidr', 'l.name as location', 'u.username']);

        return response()->json(['scans' => $rows, 'totalRows' => $total]);
    }

    public function show(Request $request, int $id)
    {
        $this->allow($request, 'itadmin_view');
        $s = $this->scan($request, $id);
        $n = DB::table('it_networks as n')->leftJoin('it_locations as l', 'l.id', '=', 'n.location_id')->where('n.id', $s->network_id)
            ->first(['n.id', 'n.name', 'n.network_address', 'n.cidr', 'l.name as location']);
        $rows = NetworkScanner::rows($s);
        $counts = array_count_values(array_column($rows, 'kind'));

        return response()->json(['scan' => $s, 'network' => $n, 'rows' => $rows, 'counts' => $counts]);
    }

    public function act(Request $request, int $id, string $action)
    {
        $this->allow($request, 'itadmin_scan');
        $s = $this->scan($request, $id);
        abort_if($s->status === 'discarded', 422, __('This scan was discarded.'));
        $rid = (int) $request->input('id');
        abort_unless(DB::table('it_network_scan_results')->where('scan_id', $s->id)->where('id', $rid)->exists(), 404);
        $error = null;
        if ($action === 'apply') {
            $error = NetworkScanner::apply($s, $rid);
        } elseif ($action === 'use-new') {
            $error = NetworkScanner::useNew($s, $rid);
        } else {
            DB::table('it_network_scan_results')->where('id', $rid)->update(['decision' => $request->boolean('undo') ? null : 'ignored', 'updated_at' => now()]);
        }
        abort_if($error, 422, $error ?? '');

        return response()->json(['success' => true]);
    }

    public function close(Request $request, int $id, string $action)
    {
        $this->allow($request, 'itadmin_scan');
        $s = $this->scan($request, $id);
        DB::table('it_network_scans')->where('id', $s->id)->update(['status' => $action === 'discard' ? 'discarded' : 'reviewed', 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }
}
