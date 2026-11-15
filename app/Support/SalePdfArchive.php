<?php

namespace App\Support;

use App\Http\Controllers\SalesController;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every sale keeps its invoice PDF in files/company_{id}/sales/Invoice_{Ref}.pdf (user's rule, 2026-11-15, Ruba: "cada vez que se
 * crea un sale, al terminar se debe crear el pdf y guardarlo en el folder"). Made when the sale is created or edited (after
 * the answer, so the sale and its lines are final; Sale model events), and the missing ones by `php artisan sales:pdf-archive`.
 * A deleted sale's PDF is never deleted: it is renamed "Invoice_{Ref} - Delete.pdf".
 * Demo sales (demo_records) are left out. Never blocks a sale: a failure is only logged.
 */
class SalePdfArchive
{
    public const MODULE = 'sales';

    /** files/company_{id}/sales/ (relative to public/). */
    public static function dir(int $companyId): string
    {
        return CompanyFiles::dir($companyId, self::MODULE);
    }

    public static function path(Sale $sale, bool $deleted = false): string
    {
        // "Invoice_{Ref}.pdf" (user's rule, 2026-11-15: the same name as the earlier system's invoices).
        $name = 'Invoice_'.self::safe((string) $sale->Ref).($deleted ? ' - Delete' : '').'.pdf';

        return public_path(self::dir((int) $sale->company_id).'/'.$name);
    }

    /** Builds the sale's PDF in the company's language and stores it. Returns the file or null. */
    public static function save(Sale $sale, bool $deleted = false, ?string $versionBy = null): ?string
    {
        if (! $sale->company_id || ! $sale->Ref || self::isDemo((int) $sale->id)) {
            return null;
        }
        if ($versionBy !== null && ! $deleted) {
            self::keepVersion($sale, $versionBy);
        }
        $previous = App::getLocale();
        try {
            $lang = (string) (Setting::forCompany((int) $sale->company_id)->default_language ?? '');
            App::setLocale(in_array($lang, ['en', 'es', 'ar'], true) ? $lang : 'en');
            $sale->loadMissing('details.product.unitSale', 'client');
            if (! $sale->client) {
                return null;
            }
            $file = self::path($sale, $deleted);
            app(SalesController::class)->buildSalePdf($sale)->save($file);

            return $file;
        } catch (\Throwable $e) {
            Log::warning('Sale PDF archive failed (non-blocking): '.$e->getMessage(), ['sale_id' => $sale->id]);

            return null;
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * The sale was deleted: its PDF ("Invoice_{Ref}.pdf", or an older "{Ref}.pdf") becomes "Invoice_{Ref} - Delete.pdf".
     * Without a PDF on disk, one is made from what is left of the sale.
     */
    public static function markDeleted(Sale $sale): void
    {
        if (! $sale->company_id || ! $sale->Ref || self::isDemo((int) $sale->id)) {
            return;
        }
        // One PDF per invoice (user's rule, 2026-11-15: "solo renómbralo, no tengas la versión anterior, se repite"): the
        // invoice's PDF is renamed; any other copy of it is removed.
        $target = self::path($sale, true);
        $dir = public_path(self::dir((int) $sale->company_id));
        foreach (['Invoice_'.self::safe($sale->Ref).'.pdf', self::safe($sale->Ref).'.pdf'] as $name) {
            $file = $dir.'/'.$name;
            if (! is_file($file)) {
                continue;
            }
            is_file($target) ? @unlink($file) : @rename($file, $target);
        }
        if (! is_file($target)) {
            self::save($sale, true);
        }
    }

    /** True when a PDF of this sale is already on disk (Murano's name or the earlier system's). */
    public static function exists(Sale $sale): bool
    {
        $dir = public_path(self::dir((int) $sale->company_id));
        $ref = self::safe((string) $sale->Ref);
        foreach (['Invoice_'.$ref.'.pdf', 'Invoice_'.$ref.' - Delete.pdf'] as $name) {
            if (is_file($dir.'/'.$name)) {
                return true;
            }
        }

        return false;
    }

    public static function isDemo(int $saleId): bool
    {
        return DB::table('demo_records')->where('record_type', 'sales')->where('record_id', $saleId)->exists();
    }

    /**
     * After the answer (the browser doesn't wait), once per sale and request: the sale and its lines are final by then.
     * $modified: the invoice was changed (not created in this same request) -- the PDF it had is kept as a version.
     */
    public static function queue(Sale $sale, bool $deleted = false, bool $modified = false): void
    {
        static $queued = [];
        $id = (int) $sale->id;
        if (isset($queued[$id])) {
            // the same request: a deletion wins; a sale created in this request is never "modified"
            $queued[$id]['deleted'] = $queued[$id]['deleted'] || $deleted;
            $queued[$id]['modified'] = $queued[$id]['modified'] || $modified;

            return;
        }
        $queued[$id] = ['deleted' => $deleted, 'modified' => $modified, 'created' => $sale->wasRecentlyCreated, 'by' => self::who()];
        app()->terminating(function () use ($id, &$queued) {
            $q = $queued[$id];
            if (! empty($q['done'])) {
                return;  // the app can be terminated twice (console); the PDF / version is made once
            }
            $queued[$id]['done'] = true;
            $fresh = Sale::query()->find($id);
            if (! $fresh) {
                return;
            }
            if ($q['deleted'] || $fresh->deleted_at) {
                self::markDeleted($fresh);
            } else {
                self::save($fresh, false, $q['modified'] && ! $q['created'] ? $q['by'] : null);
            }
        });
    }

    /**
     * Before the PDF of a modified invoice is replaced, it is kept as
     * "Invoice_{Ref}-ver01-modified-{Y-m-d_H-i}-{user}.pdf" (user's rule, 2026-11-15), ver02, ver03... in order.
     */
    public static function keepVersion(Sale $sale, string $by): ?string
    {
        $current = self::path($sale);
        if (! is_file($current)) {
            return null;
        }
        $dir = dirname($current);
        $ref = self::safe((string) $sale->Ref);
        $n = count(glob($dir.'/'.'Invoice_'.$ref.'-ver*-modified-*.pdf') ?: []) + 1;
        $name = sprintf('Invoice_%s-ver%02d-modified-%s-%s.pdf', $ref, $n, now()->format('Y-m-d_H-i'), $by);
        $target = $dir.'/'.$name;

        return @rename($current, $target) ? $target : null;
    }

    /** Who made the change, for the version's name: the login's username, else the part of the email before @. */
    private static function who(): string
    {
        $u = auth('api')->user() ?? auth()->user();
        $name = $u ? ($u->username ?: strstr((string) $u->email, '@', true) ?: ('user'.$u->id)) : 'system';

        return trim(preg_replace('/[^A-Za-z0-9_.]+/', '_', (string) $name), '_') ?: 'system';
    }

    /** Fields that change what the invoice PDF shows (an update of anything else is internal and makes no new version). */
    public const DOC_FIELDS = ['Ref', 'date', 'time', 'client_id', 'GrandTotal', 'TaxNet', 'tax_rate', 'discount', 'discount_Method',
        'shipping', 'notes', 'statut', 'paid_amount', 'payment_statut', 'warehouse_id', 'discount_from_points'];

    private static function safe(string $ref): string
    {
        return preg_replace('/[^A-Za-z0-9_\-. ]/', '_', $ref);
    }
}
