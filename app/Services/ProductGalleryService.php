<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Intervention\Image\ImageManagerStatic as Image;

class ProductGalleryService
{
    public function syncAfterCreate(Request $request, Product $product, string $primaryFilename): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        $sort = 0;

        if ($primaryFilename !== '' && $primaryFilename !== 'no-image.png') {
            ProductImage::firstOrCreate(
                ['product_id' => $product->id, 'image_path' => $primaryFilename],
                ['is_main' => true, 'sort_order' => $sort++]
            );
        }

        $this->storeGalleryUploads($request, $product, $sort);
        $this->normalizeGallery($product->id);

        if (($primaryFilename === '' || $primaryFilename === 'no-image.png')
            && ProductImage::where('product_id', $product->id)->exists()) {
            ProductImage::where('product_id', $product->id)->update(['is_main' => false]);
            $first = ProductImage::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->first();
            if ($first) {
                $first->update(['is_main' => true]);
            }
        }

        $this->applyCreateGalleryMainIndex($request, $product);
        $this->syncLegacyColumnFromMain($product);
    }

    public function syncAfterUpdate(Request $request, Product $product): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        $state = null;
        if ($request->filled('product_gallery_json')) {
            $decoded = json_decode((string) $request->input('product_gallery_json'), true);
            $state = is_array($decoded) ? $decoded : null;
        }

        $hasMainPendingIndex = is_array($state)
            && array_key_exists('main_pending_index', $state)
            && is_numeric($state['main_pending_index']);

        $galleryMetaMeaningful = is_array($state) && (
            count($state['remove'] ?? []) > 0
            || count($state['order'] ?? []) > 0
            || (isset($state['main_id']) && (int) $state['main_id'] > 0)
            || $hasMainPendingIndex
        );

        $maxImageIdBefore = (int) ProductImage::where('product_id', $product->id)->max('id');

        if ($galleryMetaMeaningful) {
            $this->applyGalleryState($product, $state);
        }

        $nextSort = (int) ProductImage::where('product_id', $product->id)->max('sort_order');
        $this->storeGalleryUploads($request, $product, $nextSort + 1);

        if ($hasMainPendingIndex) {
            $this->applyPendingGalleryMain($product, $maxImageIdBefore, $state);
        }

        if ($request->hasFile('image')) {
            $this->alignGalleryToLegacyColumn($product);
        } elseif (! $galleryMetaMeaningful && ! $request->hasFile('gallery_images') && ! $request->filled('gallery_urls')) {
            $this->alignGalleryToLegacyColumn($product);
        }

        $this->normalizeGallery($product->id);
        $this->syncLegacyColumnFromMain($product);
    }

    public static function deleteAllForProduct(Product $product): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        $images = ProductImage::where('product_id', $product->id)->get();
        foreach ($images as $img) {
            static::deleteImageFileIfSafe($img->image_path);
            $img->delete();
        }
    }

    public static function duplicateImages(Product $source, Product $copy): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        $source->loadMissing('images');
        if ($source->images->isEmpty()) {
            return;
        }

        $dir = public_path('/images/products');
        foreach ($source->images as $img) {
            $oldName = $img->image_path;
            if ($oldName === '' || $oldName === 'no-image.png') {
                continue;
            }
            $src = $dir.'/'.$oldName;
            $newName = $oldName;
            if (file_exists($src)) {
                $ext = strtolower(pathinfo($oldName, PATHINFO_EXTENSION));
                if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    $ext = 'jpg';
                }
                $newName = rand(11111111, 99999999).'.'.$ext;
                @copy($src, $dir.'/'.$newName);
            }
            ProductImage::create([
                'product_id' => $copy->id,
                'image_path' => $newName,
                'is_main' => (bool) $img->is_main,
                'sort_order' => (int) $img->sort_order,
            ]);
        }
    }

    protected function applyGalleryState(Product $product, array $state): void
    {
        foreach ($state['remove'] ?? [] as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $row = ProductImage::where('product_id', $product->id)->where('id', $id)->first();
            if ($row) {
                static::deleteImageFileIfSafe($row->image_path);
                $row->delete();
            }
        }

        foreach ($state['order'] ?? [] as $row) {
            if (empty($row['id'])) {
                continue;
            }
            ProductImage::where('product_id', $product->id)
                ->where('id', (int) $row['id'])
                ->update(['sort_order' => (int) ($row['sort_order'] ?? 0)]);
        }

        $mainId = isset($state['main_id']) ? (int) $state['main_id'] : 0;
        if ($mainId > 0) {
            ProductImage::where('product_id', $product->id)->update(['is_main' => false]);
            ProductImage::where('product_id', $product->id)->where('id', $mainId)->update(['is_main' => true]);
        }
    }

    /**
     * New gallery entries: uploaded files (gallery_images[]) and pasted links
     * (gallery_urls[]). gallery_new_manifest (JSON [{kind:'file'|'url'}...])
     * gives their combined order so rows are created in the order the admin
     * arranged them — main_index / main_pending_index rely on that order.
     */
    protected function storeGalleryUploads(Request $request, Product $product, int $startSort): void
    {
        $files = $request->file('gallery_images', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        $files = array_values($files);

        $urls = $request->input('gallery_urls', []);
        if (! is_array($urls)) {
            $urls = $urls ? [$urls] : [];
        }
        $urls = array_values(array_map(fn ($u) => trim((string) $u), $urls));

        $manifest = null;
        if ($request->filled('gallery_new_manifest')) {
            $decoded = json_decode((string) $request->input('gallery_new_manifest'), true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }
        if (! is_array($manifest)) {
            $manifest = array_merge(
                array_fill(0, count($files), ['kind' => 'file']),
                array_fill(0, count($urls), ['kind' => 'url'])
            );
        }

        $path = public_path('/images/products');
        $sort = $startSort;
        $fi = 0;
        $ui = 0;

        foreach ($manifest as $entry) {
            $kind = is_array($entry) ? ($entry['kind'] ?? '') : (string) $entry;

            if ($kind === 'url') {
                $url = $urls[$ui++] ?? null;
                $url = static::sanitizeRemoteImageUrl($url);
                if ($url === null) {
                    continue;
                }
                $exists = ProductImage::where('product_id', $product->id)->where('image_path', $url)->exists();
                if ($exists) {
                    continue;
                }
                ProductImage::create([
                    'product_id' => $product->id,
                    'image_path' => $url,
                    'is_main' => false,
                    'sort_order' => $sort++,
                ]);

                continue;
            }

            $file = $files[$fi++] ?? null;
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $ext = 'jpg';
            }
            $filename = rand(11111111, 99999999).'.'.$ext;

            $imageResize = Image::make($file->getRealPath());
            // Downscale per the Resize Product Images setting (off = original size)
            helpers::apply_product_image_resize($imageResize)->save($path.'/'.$filename);

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $filename,
                'is_main' => false,
                'sort_order' => $sort++,
            ]);
        }
    }

    /**
     * A pasted image link must be an absolute http(s) URL that fits the
     * image_path column; anything else is dropped silently.
     */
    public static function sanitizeRemoteImageUrl($raw): ?string
    {
        $url = trim((string) $raw);
        if ($url === '' || strlen($url) > 255) {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    protected function alignGalleryToLegacyColumn(Product $product): void
    {
        $legacy = trim((string) (explode(',', (string) $product->image)[0] ?? ''));
        if ($legacy === '' || $legacy === 'no-image.png') {
            return;
        }

        if (! ProductImage::where('product_id', $product->id)->exists()) {
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $legacy,
                'is_main' => true,
                'sort_order' => 0,
            ]);

            return;
        }

        ProductImage::where('product_id', $product->id)->update(['is_main' => false]);
        $hit = ProductImage::where('product_id', $product->id)->where('image_path', $legacy)->first();
        if ($hit) {
            $hit->update(['is_main' => true]);
        } else {
            $max = (int) ProductImage::where('product_id', $product->id)->max('sort_order');
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => $legacy,
                'is_main' => true,
                'sort_order' => $max + 1,
            ]);
        }
    }

    protected function normalizeGallery(int $productId): void
    {
        $rows = ProductImage::where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows->values() as $i => $row) {
            if ((int) $row->sort_order !== $i) {
                $row->update(['sort_order' => $i]);
            }
        }

        $rows = ProductImage::where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
        $mainRows = $rows->filter(fn ($r) => (bool) $r->is_main)->values();
        if ($mainRows->count() > 1) {
            $keep = $mainRows->sort(function ($a, $b) {
                $so = (int) $a->sort_order <=> (int) $b->sort_order;

                return $so !== 0 ? $so : (int) $a->id <=> (int) $b->id;
            })->first();
            foreach ($rows as $row) {
                $row->update(['is_main' => (int) $row->id === (int) $keep->id]);
            }
            $rows = ProductImage::where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
        }

        if (! $rows->contains(fn ($r) => $r->is_main)) {
            $rows->first()->update(['is_main' => true]);
        }
    }

    /**
     * Create flow: optional main_index in product_gallery_json (0-based, gallery row order).
     */
    protected function applyCreateGalleryMainIndex(Request $request, Product $product): void
    {
        if (! $request->filled('product_gallery_json')) {
            return;
        }

        $decoded = json_decode((string) $request->input('product_gallery_json'), true);
        if (! is_array($decoded) || ! array_key_exists('main_index', $decoded) || ! is_numeric($decoded['main_index'])) {
            return;
        }

        $idx = (int) $decoded['main_index'];
        $rows = ProductImage::where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
        if ($rows->isEmpty() || $idx < 0 || $idx >= $rows->count()) {
            return;
        }

        ProductImage::where('product_id', $product->id)->update(['is_main' => false]);
        $rows[$idx]->update(['is_main' => true]);
    }

    /**
     * Update flow: main image is a newly uploaded row (no id yet). Indices match gallery_images[] order.
     */
    protected function applyPendingGalleryMain(Product $product, int $maxIdBefore, array $state): void
    {
        if (! array_key_exists('main_pending_index', $state) || ! is_numeric($state['main_pending_index'])) {
            return;
        }

        $idx = (int) $state['main_pending_index'];
        if ($idx < 0) {
            return;
        }

        $newRows = ProductImage::where('product_id', $product->id)
            ->where('id', '>', $maxIdBefore)
            ->orderBy('id')
            ->get();

        if ($idx >= $newRows->count()) {
            return;
        }

        ProductImage::where('product_id', $product->id)->update(['is_main' => false]);
        $newRows[$idx]->update(['is_main' => true]);
    }

    protected function syncLegacyColumnFromMain(Product $product): void
    {
        $product->refresh();

        $count = ProductImage::where('product_id', $product->id)->count();
        if ($count === 0) {
            if ($product->image !== 'no-image.png') {
                $product->image = 'no-image.png';
                $product->saveQuietly();
            }

            return;
        }

        $main = ProductImage::where('product_id', $product->id)->where('is_main', true)->orderBy('sort_order')->first()
            ?? ProductImage::where('product_id', $product->id)->orderBy('sort_order')->first();

        if ($main && $main->image_path !== '' && $main->image_path !== 'no-image.png') {
            if ($product->image !== $main->image_path) {
                $product->image = $main->image_path;
                $product->saveQuietly();
            }
        }
    }

    protected static function deleteImageFileIfSafe(?string $filename): void
    {
        if ($filename === null || $filename === '' || $filename === 'no-image.png') {
            return;
        }
        if (product_image_is_remote($filename)) {
            return; // pasted link — nothing on disk
        }

        $full = public_path('/images/products/'.$filename);
        if (file_exists($full)) {
            @unlink($full);
        }
    }
}
