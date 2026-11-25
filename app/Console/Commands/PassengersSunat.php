<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The passenger base's companies get their official name from SUNAT by RUC (2026-11-24), the same free service as the
 * B2B RUC lookup (and its 30-day cache). A few per run -- the service answers 429 when asked too fast; a run stops at the
 * first 429 and the next one continues. The biggest buyers first.
 */
class PassengersSunat extends Command
{
    protected $signature = 'transport:passengers-sunat {--limit=10} {--pause=6 : seconds between lookups}';

    protected $description = 'Official company names from SUNAT for the passenger base (RUC)';

    public function handle(): int
    {
        $rows = DB::table('transport_passengers')->where('is_company', 1)->whereNotNull('doc')->whereNull('sunat_at')
            ->orderByDesc('amount')->limit(max(1, (int) $this->option('limit')))->get(['id', 'doc']);
        $done = 0;
        foreach ($rows as $i => $r) {
            $key = 'ruc_lookup:'.$r->doc;
            $name = Cache::get($key)['name'] ?? null;
            if (! $name) {
                if ($i) {
                    sleep(max(0, (int) $this->option('pause')));
                }
                try {
                    $res = Http::timeout(12)->acceptJson()->get('https://api.apis.net.pe/v1/ruc', ['numero' => $r->doc]);
                } catch (\Throwable $e) {
                    break;
                }
                if ($res->status() === 429) {
                    break;
                }
                $name = $res->successful() ? trim((string) ($res->json('nombre') ?? '')) : '';
                if ($name !== '') {
                    Cache::put($key, ['ruc' => $r->doc, 'name' => $name], now()->addDays(30));
                }
            }
            DB::table('transport_passengers')->where('id', $r->id)->update(['sunat_name' => $name ?: null, 'sunat_at' => now()]);
            $done++;
        }
        $left = DB::table('transport_passengers')->where('is_company', 1)->whereNotNull('doc')->whereNull('sunat_at')->count();
        $this->info("SUNAT names: $done looked up, $left left");

        return self::SUCCESS;
    }
}
