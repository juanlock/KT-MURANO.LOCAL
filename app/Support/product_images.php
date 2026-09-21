<?php

/**
 * Product image URL helpers.
 *
 * products.image / product_images.image_path historically hold a bare filename
 * under public/images/products. Admins can now also attach an image by pasting
 * a link, in which case the stored value is the absolute http(s) URL itself.
 * Every consumer must go through these helpers instead of concatenating
 * 'images/products/' so both forms render.
 */

if (! function_exists('product_image_is_remote')) {
    function product_image_is_remote($value): bool
    {
        $v = trim((string) $value);

        return $v !== '' && (bool) preg_match('#^(https?:)?//#i', $v);
    }
}

if (! function_exists('product_image_url')) {
    /**
     * Public URL for a product image value (filename or absolute URL).
     * Empty / placeholder values resolve to the no-image placeholder.
     */
    function product_image_url($value): string
    {
        $v = trim((string) $value);
        if ($v === '' || $v === 'no-image.png') {
            return asset('images/products/no-image.png');
        }
        if (product_image_is_remote($v)) {
            return $v;
        }
        if ($v[0] === '/') {
            return $v;
        }

        return asset('images/products/'.$v);
    }
}

if (! function_exists('product_image_url_or_null')) {
    /** Same as product_image_url() but null for empty / placeholder values. */
    function product_image_url_or_null($value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || $v === 'no-image.png') {
            return null;
        }

        return product_image_url($v);
    }
}
