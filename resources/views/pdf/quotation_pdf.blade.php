@php $pdfT = \App\Models\PdfTemplate::settingsFor('quotation'); @endphp
@php
    /*
     * The quotation looks like the invoice (user's rule, 2026-11-16, Ruba; 2026-11-18, KcolTech: "no se parece a la factura...
     * sale el código del producto y ese código no se usa"): same header, same "Para / De" bars, the product line = its name and
     * the line's Description (no product code), the Disc. / Tax columns and the discount / shipping rows under the same
     * Settings -> Invoice PDF switches, the company note and terms, the notes. Only what a quotation has no use for is left
     * out: payment status, paid, due, previous dues.
     */
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $rtlLabelSuffix = $isRtl ? '' : ':';
    $showDiscColumn = (bool) ($setting['sale_pdf_show_disc_column'] ?? true);
    $showTaxColumn = (bool) ($setting['sale_pdf_show_tax_column'] ?? true);
    $showShippingRow = (bool) ($setting['sale_pdf_show_shipping_row'] ?? true);
    $showDiscountRow = (bool) ($setting['sale_pdf_show_discount_row'] ?? true);
    $showCompanyNote = (bool) ($setting['sale_pdf_show_company_note'] ?? true);
    $showTermsConditions = (bool) ($setting['sale_pdf_show_terms_conditions'] ?? true);
    $anyLineHasTax = collect($details)->contains(fn ($d) => (float) ($d['taxe'] ?? 0) > 0);
    $anyLineHasDisc = collect($details)->contains(fn ($d) => (float) ($d['DiscountNet'] ?? 0) > 0);
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Quotation - {{$quote['Ref']}}</title>
    @php
        $priceFormat = $setting['price_format'] ?? null;
        $priceDecimals = \App\utils\helpers::price_decimals();
        if (! function_exists('formatPrice')) {
        function formatPrice($number, $decimals = 2, $priceFormat = null) {
            $number = (float) $number;
            $decimals = (int) $decimals;
            if (empty($priceFormat)) {
                return number_format($number, $decimals, '.', ',');
            }
            switch ($priceFormat) {
                case 'comma_dot': return number_format($number, $decimals, '.', ',');
                case 'dot_comma': return number_format($number, $decimals, ',', '.');
                case 'space_comma': return number_format($number, $decimals, ',', ' ');
                default: return number_format($number, $decimals, '.', ',');
            }
        }
        }
    @endphp
    <style>
        @page { size: A4; margin: {{ $pdfT['margin_v'] }}mm {{ $pdfT['margin_h'] }}mm; }
        body, body * { margin: 0; padding: 0; box-sizing: border-box; }
        body, body * { font-family: '{{ $pdfT['font_family'] }}', sans-serif !important; }
        body { font-size: {{ $pdfT['font_size'] }}pt; color: {{ $pdfT['text_color'] }}; background: {{ $pdfT['background_color'] }}; line-height: 1.4; padding: 15px 20px; max-width: 100%; }
        body.rtl { direction: rtl; text-align: right; }
        body.rtl table { direction: rtl; }
    </style>
</head>
<body class="{{ $isRtl ? 'rtl' : '' }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <tr>
            <td style="width: 30%; vertical-align: top;">
                @php
                    $logoSrc = null;
                    if (!empty($setting['logo'])) {
                        $logoPath = str_contains($setting['logo'], '/') ? public_path($setting['logo']) : public_path('images/'.$setting['logo']);
                        if (file_exists($logoPath) && is_readable($logoPath)) {
                            $logoData = @file_get_contents($logoPath);
                            if ($logoData !== false) {
                                $logoExt = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                                $logoMime = $logoExt === 'svg' ? 'image/svg+xml' : (in_array($logoExt, ['png','jpeg','jpg','gif','webp'], true) ? 'image/'.$logoExt : 'image/png');
                                if ($logoExt === 'jpg') { $logoMime = 'image/jpeg'; }
                                $logoSrc = 'data:'.$logoMime.';base64,'.base64_encode($logoData);
                            }
                        }
                    }
                @endphp
                @if($pdfT['logo_show'] && $logoSrc)
                    <img src="{{ $logoSrc }}" alt="Logo" style="max-height: {{ $pdfT['logo_height'] }}px; max-width: {{ $pdfT['logo_width'] }}px;">
                @endif
            </td>
            <td style="width: 70%; vertical-align: top; text-align: right;">
                <div style="font-size: 18pt; font-weight: bold; color: {{ $pdfT['primary_color'] }}; margin-bottom: 6px; letter-spacing: 0.5px;">{{ $pdfT['labels']['title'] !== '' ? $pdfT['labels']['title'] : __('pdf.quotation') }}</div>
                {{-- The reference once, larger, with its label (as on the invoice). --}}
                <div style="font-size: 13pt; font-weight: bold; color: {{ $pdfT['text_color'] }}; margin-bottom: 4px;"><span style="font-size: 9pt; font-weight: 600; color: #6b7280;">{{ __('pdf.quote_no') }}{{ $isRtl ? '' : ':' }}</span> {{$quote['Ref']}}</div>
                <table style="width: 100%; font-size: 8pt; margin-top: 2px;" cellpadding="3" cellspacing="0">
                    <tr>
                        <td style="text-align: right; color: #6b7280; font-weight: 600;">{{ __('pdf.date') }}{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right; width: 1%; white-space: nowrap; padding-left: 6px; color: {{ $pdfT['text_color'] }}; font-weight: 500;">
                            @php
                                $dateFormat = $setting['date_format'] ?? 'YYYY-MM-DD';
                                $dateTime = \Carbon\Carbon::parse($quote['date']);
                                $phpDateFormat = str_replace(['YYYY', 'MM', 'DD'], ['Y', 'm', 'd'], $dateFormat);
                                $hasTime = strpos($quote['date'], ' ') !== false && preg_match('/\d{1,2}:\d{2}/', $quote['date']);
                                if (! empty($setting['sale_pdf_hide_time'])) {
                                    $hasTime = false;
                                }
                                $formattedDate = $hasTime ? $dateTime->format($phpDateFormat . ' H:i') : $dateTime->format($phpDateFormat);
                            @endphp
                            {{$formattedDate}}
                        </td>
                    </tr>
                    @if($pdfT['show_status'])
                    @php
                        $statusColors = ['sent' => ['bg' => '#d1fae5', 'color' => '#065f46'], 'pending' => ['bg' => '#fef3c7', 'color' => '#92400e'], 'draft' => ['bg' => '#e5e7eb', 'color' => '#374151']];
                        $statusStyle = $statusColors[strtolower($quote['statut'])] ?? ['bg' => '#dbeafe', 'color' => '#1e40af'];
                    @endphp
                    <tr>
                        <td colspan="2" style="text-align: right; padding-top: 4px;">
                            <span style="color: #6b7280; font-weight: 600;">{{ __('pdf.status') }}{{ $isRtl ? '' : ':' }}</span>
                            <span style="background: {{$statusStyle['bg']}}; color: {{$statusStyle['color']}}; padding: 3px 8px; border-radius: 3px; font-size: 7pt; font-weight: bold; text-transform: uppercase;">{{ \Illuminate\Support\Facades\Lang::has('pdf.st_'.strtolower($quote['statut'])) ? __('pdf.st_'.strtolower($quote['statut'])) : $quote['statut'] }}</span>
                        </td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div style="height: 2px; background: {{ $pdfT['primary_color'] }}; margin: 8px 0 10px 0;"></div>

    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <tr>
            <td style="width: 48%; vertical-align: top;">
                <div style="border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; {{ $pdfT['show_customer'] ? '' : 'display:none;' }}">
                    <div style="background: {{ $pdfT['primary_color'] }}; padding: 5px 10px; border-bottom: 1px solid {{ $pdfT['secondary_color'] }}; text-align: {{ $isRtl ? 'right' : 'left' }};">
                        <div style="color: #ffffff; font-size: 10pt; font-weight: bold;"><span style="font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.85;">{{ __('pdf.quote_for') }}{{ $isRtl ? '' : ':' }}</span> {{$quote['client_name']}}</div>
                    </div>
                    <div style="padding: 6px 10px; background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }};">
                        <table style="width: 100%; font-size: 7.5pt; color: #6b7280; line-height: 1.5;" cellpadding="0" cellspacing="0">
                            <tr><td style="width: 28%; padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.phone') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$quote['client_phone']}}</td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.email') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$quote['client_email']}}</td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.address') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$quote['client_adr']}}</td></tr>
                            @if($quote['client_tax'])
                            <tr><td style="padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.tax_no') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$quote['client_tax']}}</td></tr>
                            @endif
                        </table>
                    </div>
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top;">
                <div style="border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; {{ $pdfT['show_company'] ? '' : 'display:none;' }}">
                    <div style="background: {{ $pdfT['primary_color'] }}; padding: 5px 10px; border-bottom: 1px solid {{ $pdfT['secondary_color'] }}; text-align: {{ $isRtl ? 'right' : 'left' }};">
                        <div style="color: #ffffff; font-size: 10pt; font-weight: bold;"><span style="font-size: 8pt; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.85;">{{ __('pdf.from') }}{{ $isRtl ? '' : ':' }}</span> {{$setting['CompanyName']}}</div>
                    </div>
                    <div style="padding: 6px 10px; background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }};">
                        <table style="width: 100%; font-size: 7.5pt; color: #6b7280; line-height: 1.5;" cellpadding="0" cellspacing="0">
                            <tr><td style="width: 28%; padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.phone') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$setting['CompanyPhone']}}</td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.email') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$setting['email']}}</td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.address') }}{!! $rtlLabelSuffix !!}</strong></td><td style="padding: 1px 0; vertical-align: top;">{{$setting['CompanyAdress']}}</td></tr>
                        </table>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    @php
        // Columns as on the invoice: Disc. / Tax under the Invoice PDF switches, Tax only when a line has one.
        $discCol = $showDiscColumn;
        $taxCol = $showTaxColumn && $anyLineHasTax;
        $th = 'padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase; border-right: 1px solid rgba(255,255,255,0.2);';
    @endphp
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; border: {{ $pdfT['table_borders'] ? '1px solid #e5e7eb' : 'none' }};" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <thead>
            <tr style="background: {{ $pdfT['primary_color'] }};">
                <th style="{{ $th }} text-align: {{ $isRtl ? 'right' : 'left' }};">{{ __('pdf.product') }}</th>
                <th style="{{ $th }}">{{ __('pdf.price') }}</th>
                <th style="{{ $th }}">{{ __('pdf.qty') }}</th>
                @if($discCol)<th style="{{ $th }}">{{ __('pdf.disc') }}</th>@endif
                @if($taxCol)<th style="{{ $th }}">{{ __('pdf.tax') }}</th>@endif
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase;">{{ __('pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @php $rowIndex = 0; $subtotal = 0; @endphp
            @foreach ($details as $detail)
            @php $subtotal += (float) $detail['total']; @endphp
            <tr style="border-bottom: 1px solid #e5e7eb; background: {{$rowIndex % 2 == 0 ? '#ffffff' : '#f9fafb'}};">
                <td style="padding: 5px; vertical-align: top;">
                    <div style="font-weight: 600; font-size: 8.5pt; color: {{ $pdfT['text_color'] }}; margin-bottom: 1px;">{{$detail['name']}}</div>
                    @if(!empty($detail['note']))
                        <div style="white-space: pre-wrap; text-align: left; font-size: 7pt; color: #6b7280;">{{ $detail['note'] }}</div>
                    @endif
                    @if($detail['is_imei'] && $detail['imei_number'] !== null)
                        <div style="font-size: 7pt; color: {{ $pdfT['secondary_color'] }}; margin-top: 1px;">{{ __('pdf.sn') }} {{$detail['imei_number']}}</div>
                    @endif
                </td>
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: {{ $pdfT['text_color'] }};">{{formatPrice((float)$detail['price'], $priceDecimals, $priceFormat)}}</td>
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: {{ $pdfT['text_color'] }};">
                    @php $isPack = !empty($detail['pack_name']) && (float)($detail['pack_multiplier'] ?? 1) > 1; @endphp
                    {{ $detail['quantity'] }} {{ $isPack ? $detail['pack_name'] : $detail['unitSale'] }}
                    @if($isPack)
                        <div style="font-size: 7pt; color: #6b7280;">(&times;{{ rtrim(rtrim(number_format((float)$detail['pack_multiplier'], 2, '.', ''), '0'), '.') }}) = {{ rtrim(rtrim(number_format((float)$detail['quantity'] * (float)$detail['pack_multiplier'], 2, '.', ''), '0'), '.') }} {{$detail['unitSale']}}</div>
                    @endif
                </td>
                @if($discCol)
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: #ef4444;">{{formatPrice((float)$detail['DiscountNet'] * (float)$detail['quantity'], $priceDecimals, $priceFormat)}}</td>
                @endif
                @if($taxCol)
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: {{ $pdfT['text_color'] }};">{{formatPrice((float)$detail['taxe'] * (float)$detail['quantity'], $priceDecimals, $priceFormat)}}</td>
                @endif
                <td style="padding: 5px; text-align: right; font-size: 9pt; font-weight: bold; color: {{ $pdfT['primary_color'] }};">{{formatPrice((float)$detail['total'], $priceDecimals, $priceFormat)}}</td>
            </tr>
            @php $rowIndex++; @endphp
            @endforeach
        </tbody>
    </table>

    @php
        $lbl = 'padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;';
        $amt = 'padding: 5px 10px; font-size: 8.5pt; font-weight: 600; text-align: right; color: '.$pdfT['text_color'].';';
    @endphp
    <table style="width: 100%; margin-bottom: 10px;" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <tr>
            <td style="width: 58%;"></td>
            <td style="width: 42%; vertical-align: top;">
                <table style="width: 100%; border: 1px solid #e5e7eb; border-radius: 4px; border-collapse: collapse;" cellpadding="0" cellspacing="0">
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $lbl }}">{{ __('pdf.subtotal') }}</td>
                        <td style="{{ $amt }}">{{$symbol}} {{formatPrice($subtotal, $priceDecimals, $priceFormat)}}</td>
                    </tr>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $lbl }}">{{ __('pdf.order_tax') }}</td>
                        <td style="{{ $amt }}">{{$symbol}} {{formatPrice((float)$quote['TaxNet'], $priceDecimals, $priceFormat)}}</td>
                    </tr>
                    @if($showDiscountRow && (float)$quote['discount'] > 0)
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $lbl }}">{{ __('pdf.discount') }}</td>
                        <td style="{{ $amt }} color: #ef4444;">- {{$symbol}} {{formatPrice((float)$quote['discount'], $priceDecimals, $priceFormat)}}</td>
                    </tr>
                    @endif
                    @if($showShippingRow && (float)$quote['shipping'] > 0)
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $lbl }}">{{ __('pdf.shipping') }}</td>
                        <td style="{{ $amt }}">{{$symbol}} {{formatPrice((float)$quote['shipping'], $priceDecimals, $priceFormat)}}</td>
                    </tr>
                    @endif
                    <tr style="background: {{ $pdfT['primary_color'] }};">
                        <td style="padding: 8px 10px; font-size: 10pt; font-weight: bold; color: #ffffff;">{{ __('pdf.total_label') }}</td>
                        <td style="padding: 8px 10px; text-align: right; font-size: 11pt; font-weight: bold; color: #ffffff;">{{$symbol}} {{formatPrice((float)$quote['GrandTotal'], $priceDecimals, $priceFormat)}}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @php
        $toParagraph = function ($text) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), 'strlen'));
            $out = '';
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $out .= preg_match('/[.,;:!?]$/', $out) ? ' ' : '. ';
                }
                $out .= $line;
            }
            return $out;
        };
        $noteText = ($showCompanyNote && !empty($setting['sale_pdf_company_note'])) ? $toParagraph($setting['sale_pdf_company_note']) : '';
        $termsText = ($showTermsConditions && !empty($setting['sale_pdf_terms_conditions']))
            ? $toParagraph(str_replace(['{{COMPANY NAME}}', '{{ADDRESS COMPANY}}'], [$setting['CompanyName'] ?? '', $setting['CompanyAdress'] ?? ''], $setting['sale_pdf_terms_conditions'])) : '';
        $noteBoxes = array_filter([__('pdf.company_note') => $noteText, __('pdf.terms_conditions') => $termsText], 'strlen');
    @endphp
    @if(count($noteBoxes))
    <table style="width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; margin-bottom: 10px;">
        <tr>
            @foreach($noteBoxes as $boxTitle => $boxText)
            <td style="width: {{ 100 / count($noteBoxes) }}%; vertical-align: top; padding: 0 {{ $loop->last ? '0' : '4px' }} 0 {{ $loop->first ? '0' : '4px' }};">
                <div style="padding: 8px 10px; background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border: 1px solid #e5e7eb; border-radius: 3px;">
                    <div style="font-size: 8pt; font-weight: 600; color: {{ $pdfT['text_color'] }}; margin-bottom: 3px;">{{ $boxTitle }}</div>
                    <p style="font-size: 7.5pt; color: #6b7280; line-height: 1.5; margin: 0; word-wrap: break-word;">{{ $boxText }}</p>
                </div>
            </td>
            @endforeach
        </tr>
    </table>
    @endif

    @if($pdfT['show_notes'] && !empty($quote['notes']))
    <div style="margin-top: 12px; margin-bottom: 10px; padding: 8px 10px; background: #f0f9ff; border-{{ $isRtl ? 'right' : 'left' }}: 3px solid #0284c7; border-radius: 3px;">
        <div style="font-size: 8pt; font-weight: 600; color: #0c4a6e; margin-bottom: 3px;">{{ __('pdf.notes') }}</div>
        <p style="font-size: 7.5pt; color: #0c4a6e; line-height: 1.5; margin: 0; white-space: pre-wrap; word-wrap: break-word;">{{ $quote['notes'] }}</p>
    </div>
    @endif

    <div style="margin-top: 15px; padding-top: 10px; border-top: 2px solid #e5e7eb; text-align: {{ $isRtl ? 'right' : 'left' }};">
        @if($pdfT['show_footer_text'] && ($pdfT['footer_text'] !== '' || ($setting['is_invoice_footer'] && $setting['invoice_footer'] !== null)))
            <div style="padding: 8px 10px; background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-{{ $isRtl ? 'right' : 'left' }}: 3px solid {{ $pdfT['primary_color'] }}; border-radius: 3px; margin-bottom: 10px;">
                <p style="font-size: 7.5pt; color: #6b7280; line-height: 1.5; margin: 0;">{{ $pdfT['footer_text'] !== '' ? $pdfT['footer_text'] : $setting['invoice_footer'] }}</p>
            </div>
        @endif
        <div style="text-align: center; padding: 8px 0; {{ ($pdfT['show_thank_you'] ?? true) ? '' : 'display:none;' }}">
            <p style="font-size: 10pt; font-weight: bold; color: {{ $pdfT['primary_color'] }}; margin: 0; letter-spacing: 0.3px;">{{ $pdfT['labels']['thank_you'] !== '' ? $pdfT['labels']['thank_you'] : __('pdf.thank_you') }}</p>
        </div>
    </div>
</body>
</html>
