<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turismo Cautivo's complaints book (user's yes, 2026-11-10): 337 submissions imported from
 * the old forms system kept their answers under the old field keys, so each value showed
 * under another question (the agency under "Doc. Identidad", the reason under "Agencia",
 * the DNI and the phone under keys the form no longer has...). The mapping below was taken
 * from the rows themselves (e.g. old text_2 holds 8-digit DNIs in 326 of 337 rows, old
 * text_6 9-digit phones in 313).
 *
 * Each corrected row keeps its ORIGINAL data, untouched, in the new column
 * form_submissions.notes ("Original import data ... {json}") to compare it later.
 * Only rows of that form (legacy Easy Forms #5), imported (legacy_id), still shifted
 * (selectlist_6 is not a document type) and with no notes yet: safe to run twice.
 */
return new class extends Migration
{
    /** old key => the current field that question has now. Keys not listed stay as they are. */
    private const MAP = [
        'selectlist_6' => 'selectlist_5',   // agency
        'selectlist_5' => 'selectlist_4',   // reason ("Seleccione una opción")
        'selectlist_4' => 'selectlist_3',   // receipt type
        'selectlist_3' => 'selectlist_2',   // product or service
        'selectlist_2' => 'selectlist_6',   // identity document type
        'text_2' => 'text_3',               // document number
        'text_3' => 'text_4',               // address
        'text_4' => 'text_7',               // father or mother
        'text_5' => 'text_9',               // receipt series - number
        'text_6' => 'text_5',               // phone
        'number_3' => 'number_1',           // amount
    ];

    public function up(): void
    {
        if (! Schema::hasTable('form_submissions')) {
            return;
        }
        if (! Schema::hasColumn('form_submissions', 'notes')) {
            Schema::table('form_submissions', function (Blueprint $t) {
                $t->longText('notes')->nullable()->after('attended_note');
            });
        }

        $form = DB::table('forms')->where('legacy_source', 'easyforms')->where('legacy_id', 5)->first();
        if (! $form) {
            return;
        }
        $fields = json_decode((string) $form->fields, true) ?: [];
        $docTypes = collect($fields)->firstWhere('key', 'selectlist_6')['options'] ?? [];
        if (! $docTypes) {
            return;
        }
        $order = array_column(array_filter($fields, fn ($f) => ! in_array($f['type'] ?? '', ['heading', 'paragraph'], true)), 'key');

        DB::transaction(function () use ($form, $docTypes, $order) {
            $rows = DB::table('form_submissions')->where('form_id', $form->id)->whereNotNull('legacy_id')
                ->whereNull('notes')->orderBy('id')->get(['id', 'data']);
            foreach ($rows as $r) {
                $d = json_decode((string) $r->data, true);
                if (! is_array($d) || ! isset($d['selectlist_6']) || in_array($d['selectlist_6'], $docTypes, true)) {
                    continue;   // not shifted
                }
                $fixed = [];
                foreach ($d as $k => $v) {
                    if (! isset(self::MAP[$k])) {
                        $fixed[$k] = $v;
                    }
                }
                foreach (self::MAP as $old => $new) {
                    if (array_key_exists($old, $d)) {
                        $fixed[$new] = $d[$old];
                    }
                }
                // The form's order first, anything else after.
                $fixed = array_merge(array_intersect_key(array_fill_keys($order, null), $fixed), $fixed);

                DB::table('form_submissions')->where('id', $r->id)->update([
                    'data' => json_encode($fixed, JSON_UNESCAPED_UNICODE),
                    'notes' => 'Original import data (before the 2026-11-10 field correction): '.$r->data,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Puts the original data back from the notes.
        if (! Schema::hasColumn('form_submissions', 'notes')) {
            return;
        }
        $prefix = 'Original import data (before the 2026-11-10 field correction): ';
        DB::table('form_submissions')->where('notes', 'like', $prefix.'%')->orderBy('id')->each(function ($r) use ($prefix) {
            DB::table('form_submissions')->where('id', $r->id)->update(['data' => substr($r->notes, strlen($prefix)), 'notes' => null]);
        });
    }
};
