<?php

namespace App\Services\GoogleSheets;

use App\Models\Client as Customer;
use App\Models\GoogleSheetLog;
use App\Models\GoogleSheetSetting;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Google Sheets snapshot exports: Sales, Products and Customers each land on
 * their own tab of the target spreadsheet. Batch methods follow the shared
 * cursor contract (`last_id` + `has_more`, driven by useBatchSync): the FIRST
 * batch (cursor 0) recreates the tab content — clear + header row — and every
 * batch appends its rows, so a finished run is a complete fresh snapshot.
 */
class ExportService
{
    private GoogleSheetSetting $settings;
    private Client $client;

    public function __construct(GoogleSheetSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?GoogleSheetSetting $settings = null): self
    {
        return new self($settings ?: GoogleSheetSetting::current());
    }

    // -----------------------------------------------------------------
    // Connection helpers
    // -----------------------------------------------------------------

    public function testConnection(): array
    {
        try {
            if ($this->settings->spreadsheet_id) {
                $meta = $this->client->spreadsheet($this->settings->spreadsheet_id);

                return ['ok' => true, 'spreadsheet_title' => $meta['properties']['title'] ?? null];
            }

            // No target sheet yet — proving the token refreshes is enough.
            $this->client->freshAccessToken(true);

            return ['ok' => true, 'spreadsheet_title' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function createSpreadsheet(string $title = 'Stocky Export'): array
    {
        $created = $this->client->createSpreadsheet($title);
        $id = (string) ($created['spreadsheetId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Google returned no spreadsheet id');
        }

        $this->settings->spreadsheet_id = $id;
        $this->settings->save();
        $this->log('spreadsheet.create', 'info', 'Spreadsheet created: '.$title);

        return ['spreadsheet_id' => $id, 'spreadsheet_url' => $this->settings->spreadsheetUrl()];
    }

    // -----------------------------------------------------------------
    // Exports
    // -----------------------------------------------------------------

    public function exportSales(int $startAfterId = 0, int $batch = 200): array
    {
        return $this->exportTab('Sales', $startAfterId, $batch,
            ['Ref', 'Date', 'Time', 'Customer', 'Warehouse', 'Status', 'Payment status', 'Grand total', 'Paid', 'Due', 'Discount', 'Shipping', 'Tax'],
            function (int $cursor, int $limit) {
                return Sale::whereNull('sales.deleted_at')
                    ->where('sales.id', '>', $cursor)
                    ->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
                    ->leftJoin('warehouses', 'warehouses.id', '=', 'sales.warehouse_id')
                    ->orderBy('sales.id')
                    ->limit($limit)
                    ->get([
                        'sales.id', 'sales.Ref', 'sales.date', 'sales.time', 'sales.statut',
                        'sales.payment_statut', 'sales.GrandTotal', 'sales.paid_amount',
                        'sales.discount', 'sales.shipping', 'sales.TaxNet',
                        'clients.name as client_name', 'warehouses.name as warehouse_name',
                    ]);
            },
            fn ($sale) => [
                (string) $sale->Ref,
                (string) $sale->date,
                (string) $sale->time,
                (string) ($sale->client_name ?? ''),
                (string) ($sale->warehouse_name ?? ''),
                (string) $sale->statut,
                (string) $sale->payment_statut,
                (float) $sale->GrandTotal,
                (float) $sale->paid_amount,
                round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2),
                (float) $sale->discount,
                (float) $sale->shipping,
                (float) $sale->TaxNet,
            ]);
    }

    public function exportProducts(int $startAfterId = 0, int $batch = 200): array
    {
        return $this->exportTab('Products', $startAfterId, $batch,
            ['Code', 'Name', 'Category', 'Brand', 'Price', 'Cost', 'Quantity', 'Stock alert', 'Active'],
            function (int $cursor, int $limit) {
                return Product::whereNull('products.deleted_at')
                    ->where('products.id', '>', $cursor)
                    ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                    ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                    ->leftJoin(DB::raw('(SELECT product_id, SUM(qte) as qty FROM product_warehouse WHERE deleted_at IS NULL GROUP BY product_id) pw'), 'pw.product_id', '=', 'products.id')
                    ->orderBy('products.id')
                    ->limit($limit)
                    ->get([
                        'products.id', 'products.code', 'products.name', 'products.price',
                        'products.cost', 'products.stock_alert', 'products.is_active',
                        'categories.name as category_name', 'brands.name as brand_name',
                        DB::raw('COALESCE(pw.qty, 0) as quantity'),
                    ]);
            },
            fn ($product) => [
                (string) $product->code,
                (string) $product->name,
                (string) ($product->category_name ?? ''),
                (string) ($product->brand_name ?? ''),
                (float) $product->price,
                (float) $product->cost,
                (float) $product->quantity,
                (float) $product->stock_alert,
                ((int) $product->is_active) === 1 ? 'Yes' : 'No',
            ]);
    }

    public function exportCustomers(int $startAfterId = 0, int $batch = 200): array
    {
        return $this->exportTab('Customers', $startAfterId, $batch,
            ['Code', 'Name', 'Email', 'Phone', 'City', 'Country'],
            function (int $cursor, int $limit) {
                return Customer::whereNull('deleted_at')
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit($limit)
                    ->get(['id', 'code', 'name', 'email', 'phone', 'city', 'country']);
            },
            fn ($client) => [
                (string) $client->code,
                (string) $client->name,
                (string) ($client->email ?? ''),
                (string) ($client->phone ?? ''),
                (string) ($client->city ?? ''),
                (string) ($client->country ?? ''),
            ]);
    }

    /**
     * Shared batch body: cursor 0 recreates the tab (ensure + clear + header),
     * every batch appends its mapped rows.
     */
    private function exportTab(string $tab, int $startAfterId, int $batch, array $header, callable $fetch, callable $mapRow): array
    {
        $spreadsheetId = (string) $this->settings->spreadsheet_id;
        if ($spreadsheetId === '') {
            return ['ok' => false, 'error' => 'No target spreadsheet — create one or paste its ID first.'];
        }

        try {
            if ($startAfterId === 0) {
                $this->client->ensureTab($spreadsheetId, $tab);
                $this->client->clearRange($spreadsheetId, $tab.'!A:Z');
                $this->client->appendRows($spreadsheetId, $tab.'!A1', [$header]);
            }

            $records = $fetch($startAfterId, $batch);
            $rows = [];
            $lastId = $startAfterId;
            foreach ($records as $record) {
                $lastId = (int) $record->id;
                $rows[] = $mapRow($record);
            }

            $this->client->appendRows($spreadsheetId, $tab.'!A1', $rows);
        } catch (\Throwable $e) {
            $this->log(strtolower($tab).'.export', 'error', $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $hasMore = count($rows) === $batch;
        if (! $hasMore) {
            $this->settings->forceFill(['last_sync_at' => now()])->save();
            $this->log(strtolower($tab).'.export', 'info', $tab.' export finished');
        }

        return [
            'ok' => true,
            'processed' => count($rows),
            'last_id' => $lastId,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Runs every tab's export to completion, server-side (the scheduled
     * nightly auto-export — no browser loop involved). Per-tab failures are
     * reported without aborting the other tabs.
     *
     * @return array<string, string> tab => "N rows" | "failed: reason"
     */
    public function exportAllTabs(): array
    {
        $summary = [];
        foreach (['Sales' => 'exportSales', 'Products' => 'exportProducts', 'Customers' => 'exportCustomers'] as $tab => $method) {
            $cursor = 0;
            $total = 0;
            do {
                $result = $this->{$method}($cursor, 500);
                if (! ($result['ok'] ?? false)) {
                    $summary[$tab] = 'failed: '.($result['error'] ?? 'unknown');
                    continue 2;
                }
                $total += (int) ($result['processed'] ?? 0);
                $cursor = (int) ($result['last_id'] ?? 0);
            } while ($result['has_more'] ?? false);
            $summary[$tab] = $total.' rows';
        }

        return $summary;
    }

    // -----------------------------------------------------------------

    public function stats(): array
    {
        return [
            'sales_total' => Sale::whereNull('deleted_at')->count(),
            'products_total' => Product::whereNull('deleted_at')->count(),
            'customers_total' => Customer::whereNull('deleted_at')->count(),
        ];
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            GoogleSheetLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break an export
        }
    }
}
