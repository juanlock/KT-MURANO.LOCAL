<?php

namespace App\Services;

/**
 * Storefront product labels — the corner badge on a product card.
 *
 * Stores type them freely in the product form, but the handful of common ones
 * get a dedicated colour and a translated caption, so "new" typed by the admin
 * reads "Nuevo" to a Spanish shopper. Anything unrecognised is shown exactly
 * as typed, in a neutral style.
 */
class ProductLabelService
{
    /**
     * Recognised label => [css modifier, translation key].
     * The modifiers map to .product-badge-* rules in storefront.css.
     */
    public const KNOWN = [
        'new' => ['new', 'LabelNew'],
        'used' => ['used', 'LabelUsed'],
        'refurbished' => ['refurb', 'LabelRefurbished'],
        'refurb' => ['refurb', 'LabelRefurbished'],
        'sale' => ['deal', 'LabelSale'],
        'deal' => ['deal', 'LabelSale'],
        'hot' => ['deal', 'LabelHot'],
        'limited' => ['limited', 'LabelLimited'],
        'exclusive' => ['limited', 'LabelExclusive'],
    ];

    /** Suggestions offered in the product form (still free text). */
    public const SUGGESTIONS = ['New', 'Used', 'Refurbished', 'Sale', 'Hot', 'Limited', 'Exclusive'];

    /** How many badges a card can show before it starts covering the image. */
    public const MAX_VISIBLE = 3;

    /**
     * Turn a product's stored labels into render-ready badges:
     * [['text' => 'Nuevo', 'class' => 'product-badge-new'], …]
     *
     * @return array<int, array{text: string, class: string}>
     */
    public static function badges($labels): array
    {
        if (is_string($labels)) {
            $labels = json_decode($labels, true);
        }
        if (! is_array($labels)) {
            return [];
        }

        $out = [];
        foreach ($labels as $label) {
            $text = trim((string) (is_array($label) ? ($label['text'] ?? '') : $label));
            if ($text === '') {
                continue;
            }

            $key = mb_strtolower($text);
            [$modifier, $transKey] = self::KNOWN[$key] ?? [null, null];

            $out[] = [
                'text' => $transKey ? __('messages.'.$transKey) : $text,
                'class' => 'product-badge-'.($modifier ?? 'custom'),
            ];

            if (count($out) >= self::MAX_VISIBLE) {
                break;
            }
        }

        return $out;
    }
}
