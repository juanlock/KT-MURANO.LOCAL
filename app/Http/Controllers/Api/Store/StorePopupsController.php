<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StorePopup;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StorePopupsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $popups = StorePopup::orderBy('sort_order')->orderByDesc('id')->get()
            ->map(fn (StorePopup $p) => $this->present($p));

        return response()->json([
            'popups' => $popups,
            'locales' => store_locales(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $this->validateData($request);
        $data['image'] = $this->handleImage($request, null);

        $popup = StorePopup::create($data);

        return response()->json(['success' => true, 'id' => $popup->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $popup = StorePopup::findOrFail($id);
        $data = $this->validateData($request);

        $image = $this->handleImage($request, $popup->image);
        if ($image !== null) {
            $data['image'] = $image;
        }

        $popup->update($data);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        StorePopup::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request): array
    {
        // Normalize boolean coming from multipart/form-data.
        if ($request->has('enabled')) {
            $request->merge(['enabled' => (int) filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN)]);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'title_translations' => ['nullable', 'array'],
            'title_translations.*' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:2000'],
            'message_translations' => ['nullable', 'array'],
            'message_translations.*' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(StorePopup::TYPES)],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_label_translations' => ['nullable', 'array'],
            'cta_label_translations.*' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'in:0,1'],
            'trigger' => ['required', Rule::in(StorePopup::TRIGGERS)],
            'delay_seconds' => ['nullable', 'integer', 'min:0', 'max:120'],
            'frequency' => ['required', Rule::in(StorePopup::FREQUENCIES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Keep only locales the storefront can render, and drop blanks so the
        // model falls back to the plain column for those languages. A field the
        // request never mentioned is left out entirely, so partial submits
        // (e.g. the list's enable/disable toggle) don't wipe existing copy.
        foreach (StorePopup::TRANSLATABLE as $field) {
            $key = $field.'_translations';
            if (! $request->has($key)) {
                unset($data[$key]);

                continue;
            }

            $clean = [];
            foreach ((array) $data[$key] as $locale => $value) {
                if (isset(store_locales()[$locale]) && trim((string) $value) !== '') {
                    $clean[$locale] = trim((string) $value);
                }
            }
            $data[$key] = $clean ?: null;
        }

        return $data;
    }

    /**
     * Store an uploaded image under public/images/popups. Returns the new path,
     * or null when no new file was uploaded (keep the existing one).
     */
    private function handleImage(Request $request, ?string $current): ?string
    {
        if (! $request->hasFile('image')) {
            return $current;
        }

        $request->validate(['image' => ['image', 'max:4096']]);

        $dir = public_path('images/popups');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $request->file('image');
        $filename = 'popup_'.time().'_'.mt_rand(1000, 9999).'.'.$file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return 'images/popups/'.$filename;
    }

    private function present(StorePopup $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'title_translations' => (object) ($p->title_translations ?? []),
            'message' => $p->message,
            'message_translations' => (object) ($p->message_translations ?? []),
            'cta_label_translations' => (object) ($p->cta_label_translations ?? []),
            'type' => $p->type,
            'image' => $p->image,
            'image_url' => $p->image ? asset($p->image) : null,
            'cta_label' => $p->cta_label,
            'cta_url' => $p->cta_url,
            'enabled' => (bool) $p->enabled,
            'trigger' => $p->trigger,
            'delay_seconds' => (int) $p->delay_seconds,
            'frequency' => $p->frequency,
            'starts_at' => optional($p->starts_at)->format('Y-m-d\TH:i'),
            'ends_at' => optional($p->ends_at)->format('Y-m-d\TH:i'),
            'sort_order' => (int) $p->sort_order,
        ];
    }
}
