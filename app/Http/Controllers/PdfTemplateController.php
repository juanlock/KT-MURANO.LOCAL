<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\PdfTemplate;
use App\Models\Setting;
use Illuminate\Http\Request;

class PdfTemplateController extends Controller
{
    use ScopesToCompany;

    /**
     * Settings -> Invoice PDF -> Default: company-scoped invoice options
     * (settings row). Each key => its default when the column is empty.
     */
    private const COMPANY_FLAGS = [
        'sale_pdf_hide_time' => false,
        'sale_pdf_show_disc_column' => true,
        'sale_pdf_show_tax_column' => true,
        'sale_pdf_show_shipping_row' => true,
        'sale_pdf_show_discount_row' => true,
        'sale_pdf_show_previous_dues' => true,
        'sale_pdf_show_company_note' => true,
        'sale_pdf_show_terms_conditions' => true,
    ];
    private const COMPANY_TEXTS = ['sale_pdf_company_note', 'sale_pdf_terms_conditions'];

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

        PdfTemplate::updateOrCreate(['doc_type' => $type], ['settings' => $this->validated($request)]);

        return response()->json(['success' => true]);
    }

    /**
     * POST pdf_templates/{type}/preview: the REAL PDF (same blade, same
     * builder as the download button) of the company's latest document of
     * that type, rendered with the UNSAVED design settings -- and, for sales,
     * the unsaved Default-tab options (`company`) -- so the preview is exactly
     * what will be generated. Nothing is saved.
     */
    public function preview(Request $request, $type)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        abort_unless(in_array($type, PdfTemplate::TYPES, true), 404);

        PdfTemplate::$preview[$type] = $this->validated($request);
        $companyId = $this->resolveActingCompanyId($request);

        $model = ['sale' => \App\Models\Sale::class, 'quotation' => \App\Models\Quotation::class, 'purchase' => \App\Models\Purchase::class][$type];
        $doc = $model::whereNull('deleted_at')->where('company_id', $companyId)->latest('id')->first()
            ?? ($request->user('api')->type === 'SuperAdmin' ? $model::whereNull('deleted_at')->latest('id')->first() : null);
        if (! $doc) {
            return response()->json(['message' => 'There is no '.$type.' yet to preview with. Create one first.'], 404);
        }

        if ($type === 'sale') {
            $overrides = $this->companyValues((array) $request->input('company', []), Setting::forCompany((int) $doc->company_id));
            $sale = \App\Models\Sale::with('details.product.unitSale')->find($doc->id);
            $content = app(SalesController::class)->buildSalePdf($sale, $overrides)->output();
        } else {
            $ctl = $type === 'quotation' ? app(QuotationsController::class) : app(PurchasesController::class);
            $method = $type === 'quotation' ? 'Quotation_pdf' : 'Purchase_pdf';
            $content = $ctl->{$method}($request, $doc->id)->getContent();
        }

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'X-Preview-Ref' => (string) ($doc->Ref ?? ''),
        ]);
    }

    /** GET pdf_company_settings: the Default tab (acting company). */
    public function companyShow(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $s = Setting::forCompany($this->resolveActingCompanyId($request));

        return response()->json(['settings' => $this->companyValues([], $s)]);
    }

    /** PUT pdf_company_settings: save the Default tab (acting company). */
    public function companyUpdate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $s = Setting::forCompany($this->resolveActingCompanyId($request));
        abort_unless($s, 404);

        foreach ($this->companyValues($request->all(), $s) as $k => $v) {
            $s->{$k} = is_bool($v) ? (int) $v : $v;
        }
        $s->save();

        return response()->json(['success' => true]);
    }

    /** Default-tab values: request values where given, else the saved ones, else the defaults. */
    private function companyValues(array $in, $setting): array
    {
        $out = [];
        foreach (self::COMPANY_FLAGS as $k => $default) {
            $out[$k] = array_key_exists($k, $in)
                ? filter_var($in[$k], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($setting->{$k} ?? $default);
        }
        foreach (self::COMPANY_TEXTS as $k) {
            $out[$k] = array_key_exists($k, $in) ? (string) ($in[$k] ?? '') : (string) ($setting->{$k} ?? '');
        }

        return $out;
    }

    private function validated(Request $request): array
    {
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

        return $data;
    }
}
