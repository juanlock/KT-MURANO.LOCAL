<?php

/*
 * The modules each business type starts with (App\Support\BusinessTypes), for
 * the PDF manuals per type: writes KT-TOOLS/Manual/business_types.json.
 * Run: php artisan tinker --execute="require 'database/scripts/manual_business_types.php';"
 * then: node KT-TOOLS/Manual/build_type_pdfs.mjs
 */

use App\Support\BusinessTypes;

$out = [];
foreach (array_keys(BusinessTypes::LABELS) as $type) {
    $out[$type] = ['label' => BusinessTypes::LABELS[$type], 'modules' => BusinessTypes::modules($type)];
}
file_put_contents(base_path('KT-TOOLS/Manual/business_types.json'), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($out)." types -> KT-TOOLS/Manual/business_types.json\n";
