<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The invoice link given to a customer (2026-10-26): the public, HMAC-signed invoice
 * page (/invoice/{id}/{signature}, SalesController::publicInvoiceView -- the same one the
 * receipt's QR opens) on the company's public address. The emails' {invoice_url} used to
 * be the internal /api/sale_pdf/{id}?random link: it showed the server address and the
 * sale id, and the customer couldn't open it (it needs a staff login).
 */
class PublicInvoice
{
    public static function signature(int $saleId): string
    {
        return substr(hash_hmac('sha256', (string) $saleId, config('app.key')), 0, 32);
    }

    public static function url(int $saleId, ?int $companyId = null): string
    {
        $companyId ??= (int) DB::table('sales')->where('id', $saleId)->value('company_id');

        return PublicUrl::base((int) $companyId).'/invoice/'.$saleId.'/'.self::signature($saleId);
    }

    /**
     * {invoice_url} in an HTML email body: inside href="..." the address only; anywhere
     * else a "View invoice" link (the company's language) instead of the bare address.
     */
    public static function fillBody(string $body, int $saleId, ?int $companyId = null): string
    {
        $url = self::url($saleId, $companyId);
        $es = str_starts_with((string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('default_language'), 'es');
        $body = preg_replace('/(href\s*=\s*["\'])\{invoice_url\}/i', '${1}'.$url, $body);

        return str_replace('{invoice_url}', '<a href="'.e($url).'" style="color:#1677ff;font-weight:600">'.($es ? 'Ver factura' : 'View invoice').'</a>', $body);
    }
}
