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
    public static function save(Sale $sale, bool $deleted = false): ?string
    {
        if (! $sale->company_id || ! $sale->Ref || self::isDemo((int) $sale->id)) {
            return null;
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
        $target = self::path($sale, true);
        if (is_file($target)) {
            return;
        }
        $dir = public_path(self::dir((int) $sale->company_id));
        foreach (['Invoice_'.self::safe($sale->Ref).'.pdf', self::safe($sale->Ref).'.pdf'] as $name) {
            if (is_file($dir.'/'.$name)) {
                @rename($dir.'/'.$name, $target);

                return;
            }
        }
        self::save($sale, true);
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

    /** After the answer (the browser doesn't wait), once: the sale and its lines are final by then. */
    public static function queue(Sale $sale, bool $deleted = false): void
    {
        static $queued = [];
        $key = $sale->id.($deleted ? 'd' : '');
        if (isset($queued[$key])) {
            return;
        }
        $queued[$key] = true;
        $id = (int) $sale->id;
        app()->terminating(function () use ($id, $deleted) {
            $fresh = Sale::query()->find($id);
            if (! $fresh) {
                return;
            }
            $deleted || $fresh->deleted_at ? self::markDeleted($fresh) : self::save($fresh);
        });
    }

    private static function safe(string $ref): string
    {
        return preg_replace('/[^A-Za-z0-9_\-. ]/', '_', $ref);
    }
}
