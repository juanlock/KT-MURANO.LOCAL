<?php

use App\Support\CompanyFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/*
 * Transport locations get a picture (2026-11-01). Turismo Cautivo's own pictures, from
 * its website (turismocautivo.com/images): each terminal its city photo, and its buses
 * without a picture the bus photo. Downloaded into files/company_{id}/transport and
 * /vehicles; a download that fails is skipped (nothing else depends on it). Only empty
 * images are filled. Safe to run twice.
 */
return new class extends Migration
{
    private const BASE = 'https://turismocautivo.com/images/';

    private const CITY = ['LIMA' => 'lima.webp', 'CHICLAYO' => 'chiclayo.webp', 'CHEPEN' => 'chepen.webp', 'TRUJILLO' => 'trujillo.webp'];

    public function up(): void
    {
        if (! Schema::hasColumn('transport_locations', 'image')) {
            Schema::table('transport_locations', function (Blueprint $t) {
                $t->string('image', 255)->nullable()->after('description');
            });
        }

        $cid = (int) DB::table('companies')->where('name', 'Turismo Cautivo')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $get = function (string $file, string $module) use ($cid): ?string {
            try {
                $res = Http::timeout(20)->get(self::BASE.$file);

                return $res->successful() && strlen($res->body()) > 1000 ? CompanyFiles::putBytes($cid, $module, $file, $res->body()) : null;
            } catch (\Throwable $e) {
                return null;
            }
        };
        foreach (self::CITY as $name => $file) {
            $row = DB::table('transport_locations')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->first();
            if ($row && ! $row->image && ($path = $get($file, 'transport'))) {
                DB::table('transport_locations')->where('id', $row->id)->update(['image' => $path, 'updated_at' => now()]);
            }
        }
        $buses = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->whereNull('image')->pluck('id');
        if ($buses->isNotEmpty() && ($bus = $get('bus.png', 'vehicles'))) {
            DB::table('vehicles')->whereIn('id', $buses)->update(['image' => $bus]);
        }
    }

    public function down(): void
    {
    }
};
