<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfTemplate extends Model
{
    protected $fillable = ['doc_type', 'settings'];

    protected $casts = ['settings' => 'array'];

    /**
     * Everything the Invoice PDF customizer can change, with the values the
     * shipped templates were designed around. Unknown/missing keys always fall
     * back here, so old saves survive new options.
     */
    public const DEFAULTS = [
        // Colors
        'primary_color' => '#1a56db',
        'secondary_color' => '#3b82f6',
        'text_color' => '#1f2937',
        'background_color' => '#ffffff',
        // Typography (DomPDF-safe families only)
        'font_family' => 'DejaVu Sans',
        'font_size' => 9,          // pt
        // Layout
        'margin_v' => 10,          // mm (top/bottom)
        'margin_h' => 15,          // mm (left/right)
        'logo_show' => true,
        'logo_width' => 120,       // px
        'logo_height' => 60,       // px (max-height cap; aspect ratio is kept)
        // Table
        'table_borders' => true,
        'table_striped' => true,
        // Sections
        'show_status' => true,     // status / payment badges in the header
        'show_customer' => true,   // bill-to block
        'show_company' => true,    // from block
        'show_notes' => true,
        'show_footer_text' => true,
        'show_thank_you' => true,
        // Text overrides ('' = keep the translated default)
        'labels' => [
            'title' => '',
            'thank_you' => '',
        ],
        'footer_text' => '',       // '' = use System Settings invoice footer
    ];

    public const TYPES = ['sale', 'quotation', 'purchase'];

    /** Saved settings for a document type, merged over the defaults. */
    public static function settingsFor(string $type): array
    {
        static $cache = [];
        if (isset($cache[$type])) {
            return $cache[$type];
        }
        $saved = [];
        try {
            $row = static::where('doc_type', $type)->first();
            $saved = is_array($row?->settings) ? $row->settings : [];
        } catch (\Throwable $e) {
            // Table not migrated yet — render with defaults.
        }
        $merged = array_replace(self::DEFAULTS, $saved);
        $merged['labels'] = array_replace(self::DEFAULTS['labels'], (array) ($saved['labels'] ?? []));

        return $cache[$type] = $merged;
    }
}
