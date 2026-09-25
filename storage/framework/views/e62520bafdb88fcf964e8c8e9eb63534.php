<?php $pdfT = \App\Models\PdfTemplate::settingsFor('sale'); ?>
<?php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    // No colon in Arabic; English keeps colon after summary labels
    $rtlLabelSuffix = $isRtl ? '' : ':';
?>
<!DOCTYPE html>
<html lang="<?php echo e($pdfLocale); ?>" dir="<?php echo e($isRtl ? 'rtl' : 'ltr'); ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Sale Invoice - <?php echo e($sale['Ref']); ?></title>
    <?php
        // Price formatting helper function (shared behavior with other PDFs)
        $priceFormat = $setting['price_format'] ?? null;
        // Monetary precision: 3 when 3-decimal pricing is enabled, else 2
        $priceDecimals = \App\utils\helpers::price_decimals();
        function formatPrice($number, $decimals = 2, $priceFormat = null) {
            $number = (float) $number;
            $decimals = (int) $decimals;

            if (empty($priceFormat)) {
                return number_format($number, $decimals, '.', ',');
            }

            switch ($priceFormat) {
                case 'comma_dot':
                    return number_format($number, $decimals, '.', ',');
                case 'dot_comma':
                    return number_format($number, $decimals, ',', '.');
                case 'space_comma':
                    return number_format($number, $decimals, ',', ' ');
                default:
                    return number_format($number, $decimals, '.', ',');
            }
        }
    ?>
    <style>
        @page { size: A4; margin: <?php echo e($pdfT['margin_v']); ?>mm <?php echo e($pdfT['margin_h']); ?>mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* DejaVu Sans has full Arabic support in DomPDF; avoid Arial/sans-serif fallback which can show ???? for Arabic */
        body, body * { 
            font-family: '<?php echo e($pdfT['font_family']); ?>', sans-serif !important; 
        }
        body { font-size: <?php echo e($pdfT['font_size']); ?>pt; color: <?php echo e($pdfT['text_color']); ?>; background: <?php echo e($pdfT['background_color']); ?>; line-height: 1.4; padding: 15px 20px; max-width: 100%; }
        body.rtl { direction: rtl; text-align: right; }
        body.rtl table { direction: rtl; }
    </style>
</head>
<body class="<?php echo e($isRtl ? 'rtl' : ''); ?>" dir="<?php echo e($isRtl ? 'rtl' : 'ltr'); ?>">
    <!-- Header Section: in RTL, logo column appears on the right -->
    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0" <?php echo e($isRtl ? 'dir="rtl"' : ''); ?>>
        <tr>
            <td style="width: 30%; vertical-align: top;">
                <?php
                    $logoSrc = null;
                    if (!empty($setting['logo'])) {
                        // Setting.logo now stores a full relative path for
                        // company-scoped uploads (files/company_{id}/...);
                        // bare filenames (pre-existing records) still resolve
                        // against the old shared images/ folder.
                        $logoPath = str_contains($setting['logo'], '/')
                            ? public_path($setting['logo'])
                            : public_path('images/'.$setting['logo']);
                        if (file_exists($logoPath) && is_readable($logoPath)) {
                            $logoData = @file_get_contents($logoPath);
                            if ($logoData !== false) {
                                $logoB64 = base64_encode($logoData);
                                $logoExt = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                                $logoMime = $logoExt === 'svg' ? 'image/svg+xml' : (in_array($logoExt, ['png','jpeg','jpg','gif','webp'], true) ? 'image/'.$logoExt : 'image/png');
                                if ($logoExt === 'jpg') { $logoMime = 'image/jpeg'; }
                                $logoSrc = 'data:'.$logoMime.';base64,'.$logoB64;
                            }
                        }
                    }
                ?>
                <?php if($pdfT['logo_show'] && $logoSrc): ?>
                    <img src="<?php echo e($logoSrc); ?>" alt="Logo" style="max-height: <?php echo e($pdfT['logo_height']); ?>px; max-width: <?php echo e($pdfT['logo_width']); ?>px;">
                <?php endif; ?>
            </td>
            <td style="width: 70%; vertical-align: top; text-align: <?php echo e($isRtl ? 'right' : 'right'); ?>;">
                <div style="font-size: 18pt; font-weight: bold; color: <?php echo e($pdfT['primary_color']); ?>; margin-bottom: 6px; letter-spacing: 0.5px;"><?php echo e($pdfT['labels']['title'] !== '' ? $pdfT['labels']['title'] : __('pdf.sales_invoice')); ?></div>
                <div style="display: inline-block; background: #f3f4f6; padding: 5px 12px; border-radius: 4px; font-size: 10pt; font-weight: bold; color: #4b5563; margin-bottom: 8px;"><?php echo e($sale['Ref']); ?></div>
                <table style="width: 100%; font-size: 8pt; margin-top: 6px;" cellpadding="3" cellspacing="0">
                    <tr>
                        <td style="text-align: right; color: #6b7280; font-weight: 600;"><?php echo e(__('pdf.date')); ?><?php echo e($isRtl ? '' : ':'); ?></td>
                        <td style="text-align: right; color: <?php echo e($pdfT['text_color']); ?>; font-weight: 500;">
                            <?php
                                $dateFormat = $setting['date_format'] ?? 'YYYY-MM-DD';
                                $dateTime = \Carbon\Carbon::parse($sale['date']);
                                $phpDateFormat = str_replace(['YYYY', 'MM', 'DD'], ['Y', 'm', 'd'], $dateFormat);
                                // Check if original date string contains time
                                $hasTime = strpos($sale['date'], ' ') !== false && preg_match('/\d{1,2}:\d{2}/', $sale['date']);
                                if ($hasTime) {
                                    $formattedDate = $dateTime->format($phpDateFormat . ' H:i');
                                    // Preserve seconds if they exist
                                    if (preg_match('/:\d{2}:\d{2}/', $sale['date'])) {
                                        $formattedDate = $dateTime->format($phpDateFormat . ' H:i:s');
                                    }
                                } else {
                                    $formattedDate = $dateTime->format($phpDateFormat);
                                }
                            ?>
                            <?php echo e($formattedDate); ?>

                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: right; color: #6b7280; font-weight: 600;"><?php echo e(__('pdf.invoice_no')); ?><?php echo e($isRtl ? '' : ':'); ?></td>
                        <td style="text-align: right; color: <?php echo e($pdfT['text_color']); ?>; font-weight: 500;"><?php echo e($sale['Ref']); ?></td>
                    </tr>
                    <tr style="<?php echo e($pdfT['show_status'] ? '' : 'display:none;'); ?>">
                        <td style="text-align: right; color: #6b7280; font-weight: 600;"><?php echo e(__('pdf.status')); ?><?php echo e($isRtl ? '' : ':'); ?></td>
                        <td style="text-align: right;">
                            <?php
                                $statusColors = [
                                    'completed' => ['bg' => '#d1fae5', 'color' => '#065f46'],
                                    'paid' => ['bg' => '#d1fae5', 'color' => '#065f46'],
                                    'pending' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                    'unpaid' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                    'partial' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
                                ];
                                $statusKey = strtolower($sale['statut']);
                                $statusStyle = $statusColors[$statusKey] ?? ['bg' => '#e5e7eb', 'color' => '#374151'];
                            ?>
                            <span style="background: <?php echo e($statusStyle['bg']); ?>; color: <?php echo e($statusStyle['color']); ?>; padding: 3px 8px; border-radius: 3px; font-size: 7pt; font-weight: bold; text-transform: uppercase;"><?php echo e($sale['statut']); ?></span>
                        </td>
                    </tr>
                    <tr style="<?php echo e($pdfT['show_status'] ? '' : 'display:none;'); ?>">
                        <td style="text-align: right; color: #6b7280; font-weight: 600;"><?php echo e(__('pdf.payment')); ?><?php echo e($isRtl ? '' : ':'); ?></td>
                        <td style="text-align: right;">
                            <?php
                                $paymentKey = strtolower($sale['payment_status']);
                                $paymentStyle = $statusColors[$paymentKey] ?? ['bg' => '#e5e7eb', 'color' => '#374151'];
                            ?>
                            <span style="background: <?php echo e($paymentStyle['bg']); ?>; color: <?php echo e($paymentStyle['color']); ?>; padding: 3px 8px; border-radius: 3px; font-size: 7pt; font-weight: bold; text-transform: uppercase;"><?php echo e($sale['payment_status']); ?></span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Divider -->
    <div style="height: 2px; background: <?php echo e($pdfT['primary_color']); ?>; margin: 8px 0 10px 0;"></div>

    <!-- Bill To / From Section: same as summary — RTL = value left, label right; LTR = label left, value right -->
    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0" <?php echo e($isRtl ? 'dir="rtl"' : ''); ?>>
        <tr>
            <td style="width: 48%; vertical-align: top;">
                <div style="border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; <?php echo e($pdfT['show_customer'] ? '' : 'display:none;'); ?>">
                    <div style="background: <?php echo e($pdfT['primary_color']); ?>; padding: 5px 10px; border-bottom: 1px solid <?php echo e($pdfT['secondary_color']); ?>; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;">
                        <div style="color: #ffffff; font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;"><?php echo e(__('pdf.bill_to')); ?></div>
                    </div>
                    <div style="padding: 8px 10px; background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>;">
                        <div style="font-size: 10pt; font-weight: bold; color: <?php echo e($pdfT['text_color']); ?>; margin-bottom: 4px; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;"><?php echo e($sale['client_name']); ?></div>
                        <table style="width: 100%; font-size: 7.5pt; color: #6b7280; line-height: 1.5;" cellpadding="0" cellspacing="0">
                            <?php if($isRtl): ?>
                            
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($sale['client_phone']); ?></td><td style="width: 32%; padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.phone')); ?><?php echo e($isRtl ? '' : ':'); ?></strong></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($sale['client_email']); ?></td><td style="padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.email')); ?><?php echo e($isRtl ? '' : ':'); ?></strong></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($sale['client_adr']); ?></td><td style="padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.address')); ?><?php echo e($isRtl ? '' : ':'); ?></strong></td></tr>
                            <?php if($sale['client_tax']): ?>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($sale['client_tax']); ?></td><td style="padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.tax_no')); ?><?php echo e($isRtl ? '' : ':'); ?></strong></td></tr>
                            <?php endif; ?>
                            <?php else: ?>
                            
                            <tr><td style="width: 28%; padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.phone')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($sale['client_phone']); ?></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.email')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($sale['client_email']); ?></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.address')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($sale['client_adr']); ?></td></tr>
                            <?php if($sale['client_tax']): ?>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.tax_no')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($sale['client_tax']); ?></td></tr>
                            <?php endif; ?>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top;">
                <div style="border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; <?php echo e($pdfT['show_company'] ? '' : 'display:none;'); ?>">
                    <div style="background: <?php echo e($pdfT['primary_color']); ?>; padding: 5px 10px; border-bottom: 1px solid <?php echo e($pdfT['secondary_color']); ?>; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;">
                        <div style="color: #ffffff; font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;"><?php echo e(__('pdf.from')); ?></div>
                    </div>
                    <div style="padding: 8px 10px; background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>;">
                        <div style="font-size: 10pt; font-weight: bold; color: <?php echo e($pdfT['text_color']); ?>; margin-bottom: 4px; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;"><?php echo e($setting['CompanyName']); ?></div>
                        <table style="width: 100%; font-size: 7.5pt; color: #6b7280; line-height: 1.5;" cellpadding="0" cellspacing="0">
                            <?php if($isRtl): ?>
                            
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($setting['CompanyPhone']); ?></td><td style="width: 32%; padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.phone')); ?></strong></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($setting['email']); ?></td><td style="padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.email')); ?></strong></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;"><?php echo e($setting['CompanyAdress']); ?></td><td style="padding: 1px 0; vertical-align: top; text-align: right; direction: rtl;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.address')); ?></strong></td></tr>
                            <?php else: ?>
                            
                            <tr><td style="width: 28%; padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.phone')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($setting['CompanyPhone']); ?></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.email')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($setting['email']); ?></td></tr>
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(__('pdf.address')); ?>:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;"><?php echo e($setting['CompanyAdress']); ?></td></tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Products Table: in RTL, columns order right-to-left -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; border: <?php echo e($pdfT['table_borders'] ? '1px solid #e5e7eb' : 'none'); ?>;" cellpadding="0" cellspacing="0" <?php echo e($isRtl ? 'dir="rtl"' : ''); ?>>
        <thead>
            <tr style="background: <?php echo e($pdfT['primary_color']); ?>;">
                <th style="padding: 6px 5px; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase; border-right: 1px solid rgba(255,255,255,0.2);"><?php echo e(__('pdf.product')); ?></th>
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase; border-right: 1px solid rgba(255,255,255,0.2);"><?php echo e(__('pdf.price')); ?></th>
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase; border-right: 1px solid rgba(255,255,255,0.2);"><?php echo e(__('pdf.qty')); ?></th>
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase; border-right: 1px solid rgba(255,255,255,0.2);"><?php echo e(__('pdf.disc')); ?></th>
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase; border-right: 1px solid rgba(255,255,255,0.2);"><?php echo e(__('pdf.tax')); ?></th>
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #ffffff; text-transform: uppercase;"><?php echo e(__('pdf.total')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php $rowIndex = 0; ?>
            <?php $__currentLoopData = $details; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $detail): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr style="border-bottom: 1px solid #e5e7eb; background: <?php echo e($rowIndex % 2 == 0 ? '#ffffff' : '#f9fafb'); ?>;">
                <td style="padding: 5px; vertical-align: top;">
                    <div style="font-weight: 600; font-size: 8.5pt; color: <?php echo e($pdfT['text_color']); ?>; margin-bottom: 1px;"><?php echo e($detail['name']); ?></div>
                    <?php if(!empty($detail['note'])): ?>
                        <div style="white-space: pre-wrap; text-align: left; font-size: 7pt; color: #6b7280;">Note: <?php echo e($detail['note']); ?></div>
                    <?php endif; ?>
                    <?php if($detail['is_imei'] && $detail['imei_number'] !==null): ?>
                        <div style="font-size: 7pt; color: <?php echo e($pdfT['secondary_color']); ?>; margin-top: 1px;"><?php echo e(__('pdf.sn')); ?> <?php echo e($detail['imei_number']); ?></div>
                    <?php endif; ?>
                </td>
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(formatPrice((float)$detail['price'], $priceDecimals, $priceFormat)); ?></td>
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: <?php echo e($pdfT['text_color']); ?>;">
                    <?php echo e($detail['quantity']); ?> <?php echo e((!empty($detail['pack_name']) && (float)($detail['pack_multiplier'] ?? 1) > 1) ? $detail['pack_name'] : $detail['unitSale']); ?>

                    <?php if(!empty($detail['pack_name']) && (float)($detail['pack_multiplier'] ?? 1) > 1): ?>
                        <div style="font-size: 7pt; color: #6b7280;">(&times;<?php echo e(rtrim(rtrim(number_format((float)$detail['pack_multiplier'], 2, '.', ''), '0'), '.')); ?>) = <?php echo e(rtrim(rtrim(number_format((float)$detail['quantity'] * (float)$detail['pack_multiplier'], 2, '.', ''), '0'), '.')); ?> <?php echo e($detail['unitSale']); ?></div>
                    <?php endif; ?>
                </td>
                
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: #ef4444;"><?php echo e(formatPrice((float)$detail['DiscountNet'] * (float)$detail['quantity'], $priceDecimals, $priceFormat)); ?></td>
                <td style="padding: 5px; text-align: right; font-size: 8.5pt; color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e(formatPrice((float)$detail['taxe'] * (float)$detail['quantity'], $priceDecimals, $priceFormat)); ?></td>
                <td style="padding: 5px; text-align: right; font-size: 9pt; font-weight: bold; color: <?php echo e($pdfT['primary_color']); ?>;"><?php echo e(formatPrice((float)$detail['total'], $priceDecimals, $priceFormat)); ?></td>
            </tr>
            <?php $rowIndex++; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>

    <!-- Summary Section: in RTL, summary box appears on the left (start) side -->
    <table style="width: 100%; margin-bottom: 10px;" cellpadding="0" cellspacing="0" <?php echo e($isRtl ? 'dir="rtl"' : ''); ?>>
        <tr>
            <td style="width: 58%;"></td>
            <td style="width: 42%; vertical-align: top; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;">
                <?php
                    // Calculate subtotal from line items
                    $subtotal = 0;
                    // Total product tax = sum of (Unit Tax × Quantity); display-only, does not affect GrandTotal
                    $productTaxTotal = 0;
                    $productTaxBase = 0;
                    foreach ($details as $detail) {
                        $subtotal += (float)$detail['total'];
                        $productTaxTotal += (float)$detail['taxe'] * (float)$detail['quantity'];
                        $productTaxBase += ((float)$detail['price'] - (float)$detail['DiscountNet']) * (float)$detail['quantity'];
                    }
                    // Effective product tax rate (%) = totalProductTax / taxable base × 100
                    $productTaxRate = $productTaxBase > 0 ? ($productTaxTotal / $productTaxBase) * 100 : 0;
                    // When the Total Items Tax line is shown, display the subtotal net of items tax
                    // so the breakdown reconciles; otherwise keep the original gross subtotal.
                    $subtotalDisplay = !empty($show_items_tax) ? ($subtotal - $productTaxTotal) : $subtotal;
                    $discountMethod = $sale['discount_Method'] ?? '2';
                    $discountValue = (float)$sale['discount'];
                    $manualDiscountAmount = $discountMethod === '1' ? $subtotal * ($discountValue / 100) : min($discountValue, $subtotal);
                    // Arabic: col1=amount (left), col2=label (right). English: col1=label (left), col2=amount (right).
                    $tdAmountLeft = 'padding: 5px 10px; font-size: 8.5pt; font-weight: 600; text-align: left; direction: ltr;';
                    $tdLabelRight = 'padding: 5px 10px; font-size: 8pt; font-weight: 600; text-align: right; direction: rtl;';
                ?>
                <table style="width: 100%; border: 1px solid #e5e7eb; border-radius: 4px; border-collapse: collapse;" cellpadding="0" cellspacing="0">
                    <?php if($isRtl): ?>
                    
                    <tr style="background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-bottom: 1px solid #e5e7eb;">
                        <td style="<?php echo e($tdAmountLeft); ?> color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e($symbol); ?> <?php echo e(formatPrice($subtotalDisplay, $priceDecimals, $priceFormat)); ?></td>
                        <td style="<?php echo e($tdLabelRight); ?> color: #6b7280;"><?php echo e(__('pdf.subtotal')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <?php if($productTaxTotal > 0 && !empty($show_items_tax)): ?>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="<?php echo e($tdAmountLeft); ?> color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e($symbol); ?> <?php echo e(formatPrice($productTaxTotal, $priceDecimals, $priceFormat)); ?> (<?php echo e(number_format($productTaxRate, 2)); ?> %)</td>
                        <td style="<?php echo e($tdLabelRight); ?> color: #6b7280;"><?php echo e(__('pdf.total_product_tax')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="<?php echo e($tdAmountLeft); ?> color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['TaxNet'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="<?php echo e($tdLabelRight); ?> color: #6b7280;"><?php echo e(__('pdf.order_tax')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <tr style="background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-bottom: 1px solid #e5e7eb;">
                        <td style="<?php echo e($tdAmountLeft); ?> color: #ef4444;"><?php if($discountMethod === '1'): ?>- <?php echo e(number_format($discountValue, 2)); ?>% (<?php echo e($symbol); ?> <?php echo e(formatPrice($manualDiscountAmount, $priceDecimals, $priceFormat)); ?>)<?php else: ?> - <?php echo e($symbol); ?> <?php echo e(formatPrice($manualDiscountAmount, $priceDecimals, $priceFormat)); ?><?php endif; ?></td>
                        <td style="<?php echo e($tdLabelRight); ?> color: #6b7280;"><?php echo e(__('pdf.discount')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <?php if(isset($sale['discount_from_points']) && (float)$sale['discount_from_points'] > 0): ?>
                    <tr style="background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-bottom: 1px solid #e5e7eb;">
                        <td style="<?php echo e($tdAmountLeft); ?> color: #ef4444;">- <?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['discount_from_points'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="<?php echo e($tdLabelRight); ?> color: #6b7280;"><?php echo e(__('pdf.discount_from_points')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="<?php echo e($tdAmountLeft); ?> color: <?php echo e($pdfT['text_color']); ?>;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['shipping'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="<?php echo e($tdLabelRight); ?> color: #6b7280;"><?php echo e(__('pdf.shipping')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <tr style="background: <?php echo e($pdfT['primary_color']); ?>;">
                        <td style="padding: 8px 10px; font-size: 11pt; font-weight: bold; color: #ffffff; text-align: left; direction: ltr;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['GrandTotal'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="padding: 8px 10px; font-size: 10pt; font-weight: bold; color: #ffffff; text-align: right; direction: rtl;"><?php echo e(__('pdf.total_label')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <tr style="background: #d1fae5; border-bottom: 1px solid #a7f3d0;">
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #065f46; text-align: left; direction: ltr;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['paid_amount'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #065f46; text-align: right; direction: rtl;"><?php echo e(__('pdf.paid_amount')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: left; direction: ltr;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['due'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e; text-align: right; direction: rtl;"><?php echo e(__('pdf.amount_due')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <?php if(isset($sale['previous_dues']) && (float)$sale['previous_dues'] > 0): ?>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: left; direction: ltr;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['previous_dues'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e; text-align: right; direction: rtl;"><?php echo e(__('pdf.previous_dues')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: left; direction: ltr;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['previous_dues'] + (float)$sale['due'], $priceDecimals, $priceFormat)); ?></td>
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e; text-align: right; direction: rtl;"><?php echo e(__('pdf.net_balance')); ?><?php echo $rtlLabelSuffix; ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php else: ?>
                    
                    <tr style="background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;"><?php echo e(__('pdf.subtotal')); ?></td>
                        <td style="padding: 5px 10px; font-size: 8.5pt; font-weight: 600; color: <?php echo e($pdfT['text_color']); ?>; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice($subtotalDisplay, $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <?php if($productTaxTotal > 0 && !empty($show_items_tax)): ?>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;"><?php echo e(__('pdf.total_product_tax')); ?></td>
                        <td style="padding: 5px 10px; font-size: 8.5pt; font-weight: 600; color: <?php echo e($pdfT['text_color']); ?>; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice($productTaxTotal, $priceDecimals, $priceFormat)); ?> (<?php echo e(number_format($productTaxRate, 2)); ?> %)</td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;"><?php echo e(__('pdf.order_tax')); ?></td>
                        <td style="padding: 5px 10px; font-size: 8.5pt; font-weight: 600; color: <?php echo e($pdfT['text_color']); ?>; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['TaxNet'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <tr style="background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;"><?php echo e(__('pdf.discount')); ?></td>
                        <td style="padding: 5px 10px; font-size: 8.5pt; font-weight: 600; color: #ef4444; text-align: right;"><?php if($discountMethod === '1'): ?>- <?php echo e(number_format($discountValue, 2)); ?>% (<?php echo e($symbol); ?> <?php echo e(formatPrice($manualDiscountAmount, $priceDecimals, $priceFormat)); ?>)<?php else: ?> - <?php echo e($symbol); ?> <?php echo e(formatPrice($manualDiscountAmount, $priceDecimals, $priceFormat)); ?><?php endif; ?></td>
                    </tr>
                    <?php if(isset($sale['discount_from_points']) && (float)$sale['discount_from_points'] > 0): ?>
                    <tr style="background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;"><?php echo e(__('pdf.discount_from_points')); ?></td>
                        <td style="padding: 5px 10px; font-size: 8.5pt; font-weight: 600; color: #ef4444; text-align: right;">- <?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['discount_from_points'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 5px 10px; font-size: 8pt; font-weight: 600; color: #6b7280;"><?php echo e(__('pdf.shipping')); ?></td>
                        <td style="padding: 5px 10px; font-size: 8.5pt; font-weight: 600; color: <?php echo e($pdfT['text_color']); ?>; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['shipping'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <tr style="background: <?php echo e($pdfT['primary_color']); ?>;">
                        <td style="padding: 8px 10px; font-size: 10pt; font-weight: bold; color: #ffffff;"><?php echo e(__('pdf.total_label')); ?></td>
                        <td style="padding: 8px 10px; font-size: 11pt; font-weight: bold; color: #ffffff; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['GrandTotal'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <tr style="background: #d1fae5; border-bottom: 1px solid #a7f3d0;">
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #065f46;"><?php echo e(__('pdf.paid_amount')); ?></td>
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #065f46; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['paid_amount'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e;"><?php echo e(__('pdf.amount_due')); ?></td>
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['due'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <?php if(isset($sale['previous_dues']) && (float)$sale['previous_dues'] > 0): ?>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e;"><?php echo e(__('pdf.previous_dues')); ?></td>
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['previous_dues'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 6px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e;"><?php echo e(__('pdf.net_balance')); ?></td>
                        <td style="padding: 6px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: right;"><?php echo e($symbol); ?> <?php echo e(formatPrice((float)$sale['previous_dues'] + (float)$sale['due'], $priceDecimals, $priceFormat)); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <!-- Notes Section -->
    <?php if($pdfT['show_notes'] && (($sale['notes'] ?? null) || ($sale['payment_note'] ?? null))): ?>
    <div style="margin-top: 12px; margin-bottom: 10px;">
        <?php if($sale['notes'] ?? null): ?>
        <div style="padding: 8px 10px; background: #f0f9ff; border-<?php echo e($isRtl ? 'right' : 'left'); ?>: 3px solid #0284c7; border-radius: 3px; margin-bottom: 8px; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;">
            <div style="font-size: 8pt; font-weight: 600; color: #0c4a6e; margin-bottom: 3px;"><?php echo e(__('pdf.notes')); ?></div>
            <p style="font-size: 7.5pt; color: #0c4a6e; line-height: 1.5; margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo e($sale['notes']); ?></p>
        </div>
        <?php endif; ?>
        <?php if($sale['payment_note'] ?? null): ?>
        <div style="padding: 8px 10px; background: #fef3c7; border-<?php echo e($isRtl ? 'right' : 'left'); ?>: 3px solid #ca8a04; border-radius: 3px; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;">
            <div style="font-size: 8pt; font-weight: 600; color: #92400e; margin-bottom: 3px;"><?php echo e(__('pdf.payment_note')); ?></div>
            <p style="font-size: 7.5pt; color: #92400e; line-height: 1.5; margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo e($sale['payment_note']); ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div style="margin-top: 15px; padding-top: 10px; border-top: 2px solid #e5e7eb; text-align: <?php echo e($isRtl ? 'right' : 'left'); ?>;">
        <?php if($pdfT['show_footer_text'] && ($pdfT['footer_text'] !== '' || ($setting['is_invoice_footer'] && $setting['invoice_footer'] !== null))): ?>
            <div style="padding: 8px 10px; background: <?php echo e($pdfT['table_striped'] ? '#f9fafb' : '#ffffff'); ?>; border-<?php echo e($isRtl ? 'right' : 'left'); ?>: 3px solid <?php echo e($pdfT['primary_color']); ?>; border-radius: 3px; margin-bottom: 10px;">
                <p style="font-size: 7.5pt; color: #6b7280; line-height: 1.5; margin: 0;"><?php echo e($pdfT['footer_text'] !== '' ? $pdfT['footer_text'] : $setting['invoice_footer']); ?></p>
            </div>
        <?php endif; ?>
        <div style="text-align: center; padding: 8px 0; <?php echo e($pdfT['show_thank_you'] ? '' : 'display:none;'); ?>">
            <p style="font-size: 10pt; font-weight: bold; color: <?php echo e($pdfT['primary_color']); ?>; margin: 0; letter-spacing: 0.3px;"><?php echo e($pdfT['labels']['thank_you'] !== '' ? $pdfT['labels']['thank_you'] : __('pdf.thank_you')); ?></p>
        </div>
    </div>
</body>
</html>
<?php /**PATH D:\WEBSITES-LOCAL\KT-MURANO.LOCAL\resources\views/pdf/sale_pdf.blade.php ENDPATH**/ ?>