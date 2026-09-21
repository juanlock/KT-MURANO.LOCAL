<?php

namespace App\Http\Controllers;

use App\Models\PdfTemplate;
use App\Models\Setting;
use Illuminate\Http\Request;

class PdfTemplateController extends Controller
{
    /** Current (defaults-merged) settings for one document type. */
    public function show(Request $request, $type)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        abort_unless(in_array($type, PdfTemplate::TYPES, true), 404);

        return response()->json([
            'settings' => PdfTemplate::settingsFor($type),
            'defaults' => PdfTemplate::DEFAULTS,
        ]);
    }

    /** Save the settings for one document type. */
    public function update(Request $request, $type)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        abort_unless(in_array($type, PdfTemplate::TYPES, true), 404);

        $data = $request->validate([
            'primary_color' => 'required|string|max:20',
            'secondary_color' => 'required|string|max:20',
            'text_color' => 'required|string|max:20',
            'background_color' => 'required|string|max:20',
            'font_family' => 'required|in:DejaVu Sans,DejaVu Serif,DejaVu Sans Mono,Helvetica,Times,Courier',
            'font_size' => 'required|integer|min:7|max:14',
            'margin_v' => 'required|integer|min:0|max:40',
            'margin_h' => 'required|integer|min:0|max:40',
            'logo_show' => 'required|boolean',
            'logo_width' => 'required|integer|min:40|max:400',
            'logo_height' => 'required|integer|min:20|max:300',
            'table_borders' => 'required|boolean',
            'table_striped' => 'required|boolean',
            'show_status' => 'required|boolean',
            'show_customer' => 'required|boolean',
            'show_company' => 'required|boolean',
            'show_notes' => 'required|boolean',
            'show_footer_text' => 'required|boolean',
            'show_thank_you' => 'required|boolean',
            'labels' => 'nullable|array',
            'labels.title' => 'nullable|string|max:120',
            'labels.thank_you' => 'nullable|string|max:190',
            'footer_text' => 'nullable|string|max:1000',
        ]);
        $data['labels'] = [
            'title' => (string) ($data['labels']['title'] ?? ''),
            'thank_you' => (string) ($data['labels']['thank_you'] ?? ''),
        ];
        $data['footer_text'] = (string) ($data['footer_text'] ?? '');

        PdfTemplate::updateOrCreate(['doc_type' => $type], ['settings' => $data]);

        return response()->json(['success' => true]);
    }
}
