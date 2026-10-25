<?php

namespace App\Support;

use App\Models\HmsFloor;
use App\Models\HmsRoom;
use App\Models\HospitalBed;
use App\Models\HospitalWard;
use Illuminate\Support\Facades\DB;

/**
 * Floor maps built from the wards (2026-10-25): Hospital -> Configuration ->
 * "Build from the wards" and the hospital demo. Explicit company everywhere
 * (no acting-company scope), so it works from a request or a console run.
 */
class HospitalLayout
{
    /** A floor per distinct ward floor text of the company (linked); returns the floors. */
    public static function floorsFromWards(int $companyId): \Illuminate\Support\Collection
    {
        $wards = HospitalWard::withoutGlobalScope('company')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('floor_id')->get();
        foreach ($wards as $w) {
            $name = trim((string) $w->floor);
            if ($name === '') {
                continue;
            }
            $level = preg_match('/baja|ground|lobby/i', $name) ? 0 : (preg_match('/(-?\d+)/', $name, $m) ? (int) $m[1] : 1);
            $floor = HmsFloor::withoutGlobalScope('company')->where('company_id', $companyId)->where('name', $name)->whereNull('deleted_at')->first()
                ?? HmsFloor::withoutGlobalScope('company')->create(['company_id' => $companyId, 'name' => $name, 'level' => $level, 'sort_order' => $level + 10, 'map_cols' => 24, 'map_rows' => 14]);
            $w->update(['floor_id' => $floor->id]);
        }

        return HmsFloor::withoutGlobalScope('company')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('sort_order')->get();
    }

    /**
     * One room per ward of the floor (ER wards as "er"), sized to its beds not
     * on a map yet, the beds lined up inside; reception + nursing station on
     * the ground floor. Existing rooms are kept. Returns the rooms created.
     */
    public static function build(HmsFloor $floor, bool $es): int
    {
        $cid = (int) $floor->company_id;
        $wards = HospitalWard::withoutGlobalScope('company')->where('company_id', $cid)->whereNull('deleted_at')->where('floor_id', $floor->id)->orderBy('name')->get();
        $made = 0;
        DB::transaction(function () use ($floor, $wards, $es, $cid, &$made) {
            foreach ($wards as $w) {
                $beds = HospitalBed::withoutGlobalScope('company')->where('company_id', $cid)->whereNull('deleted_at')->where('ward_id', $w->id)->whereNull('room_id')->orderBy('bed_number')->get();
                if ($beds->isEmpty()) {
                    continue;
                }
                $wCells = max(3, min(8, $beds->count()));
                $hCells = max(3, (int) ceil($beds->count() / $wCells) + 2); // a row for the name, the beds, a row of air
                self::ensureRows($floor, $hCells);
                [$x, $y] = self::freeSpot($floor, $wCells, $hCells);
                $room = HmsRoom::withoutGlobalScope('company')->create(['company_id' => $cid, 'floor_id' => $floor->id, 'ward_id' => $w->id, 'name' => $w->name,
                    'kind' => $w->type === 'emergency' ? 'er' : 'room', 'map_x' => $x, 'map_y' => $y, 'map_w' => $wCells, 'map_h' => $hCells]);
                foreach ($beds->values() as $i => $b) {
                    $b->update(['room_id' => $room->id, 'map_x' => $x + ($i % $wCells), 'map_y' => $y + 1 + intdiv($i, $wCells)]);
                }
                $made++;
            }
            if ((int) $floor->level === 0) {
                foreach ([['reception', $es ? 'Recepción' : 'Reception'], ['nursing', $es ? 'Estación de enfermería' : 'Nursing station']] as [$kind, $name]) {
                    if (! HmsRoom::withoutGlobalScope('company')->where('floor_id', $floor->id)->where('kind', $kind)->exists()) {
                        [$x, $y] = self::freeSpot($floor, 3, 2);
                        HmsRoom::withoutGlobalScope('company')->create(['company_id' => $cid, 'floor_id' => $floor->id, 'name' => $name, 'kind' => $kind, 'map_x' => $x, 'map_y' => $y, 'map_w' => 3, 'map_h' => 2]);
                    }
                }
            }
        });

        return $made;
    }

    /** The first free top-left spot of the floor for a w x h rectangle (0,0 when full). */
    public static function freeSpot(HmsFloor $floor, int $w, int $h): array
    {
        $taken = HmsRoom::withoutGlobalScope('company')->where('floor_id', $floor->id)->whereNull('deleted_at')->whereNotNull('map_x')->get(['map_x', 'map_y', 'map_w', 'map_h']);
        for ($y = 0; $y + $h <= $floor->map_rows; $y++) {
            for ($x = 0; $x + $w <= $floor->map_cols; $x++) {
                $clash = $taken->first(fn ($r) => $x < $r->map_x + $r->map_w && $x + $w > $r->map_x && $y < $r->map_y + $r->map_h && $y + $h > $r->map_y);
                if (! $clash) {
                    return [$x, $y];
                }
            }
        }

        return [0, 0];
    }

    /** Grow the map's height when a new room would not fit. */
    private static function ensureRows(HmsFloor $floor, int $needed): void
    {
        $bottom = (int) HmsRoom::withoutGlobalScope('company')->where('floor_id', $floor->id)->whereNull('deleted_at')->whereNotNull('map_y')->selectRaw('MAX(map_y + map_h) m')->value('m');
        if ($bottom + $needed > $floor->map_rows && $floor->map_rows < 60) {
            $floor->update(['map_rows' => min(60, $bottom + $needed + 1)]);
        }
    }
}
