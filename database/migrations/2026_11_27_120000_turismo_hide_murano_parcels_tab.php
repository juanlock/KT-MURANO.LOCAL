<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo (the user, 2026-11-27): hide the "Encom. Murano" board (transport_parcels: parcels registered in Murano).
 * Turismo does not register parcels in Murano -- its parcels come from AuraSoft (the "Encomiendas" board). Hidden the same way
 * System Settings -> Dashboard does it (settings.dashboard_hidden_sections). Safe to run twice; down() shows it again.
 */
return new class extends Migration
{
    private function company(): int
    {
        return (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
    }

    private function update(callable $change): void
    {
        $cid = $this->company();
        if (! $cid) {
            return;
        }
        foreach (DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->get(['id', 'dashboard_hidden_sections']) as $s) {
            $list = json_decode((string) $s->dashboard_hidden_sections, true) ?: [];
            $new = array_values(array_unique($change($list)));
            if ($new !== $list) {
                DB::table('settings')->where('id', $s->id)->update(['dashboard_hidden_sections' => json_encode($new)]);
            }
        }
    }

    public function up(): void
    {
        $this->update(fn ($l) => in_array('transport_parcels', $l, true) ? $l : [...$l, 'transport_parcels']);
    }

    public function down(): void
    {
        $this->update(fn ($l) => array_values(array_diff($l, ['transport_parcels'])));
    }
};
