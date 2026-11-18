<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A ready-made website's contact form works for the company that uses it (user's rule, 2026-11-17: "al importar usando
 * una de las plantillas también debe automáticamente encender el formulario y haberse creado el form contáctenos").
 *
 * When the company's copy has a contact form (a <form> with a message box -- not a comment, search, newsletter or
 * checkout form): the Forms module is switched on for the company, its "Contáctenos" / "Contact us" form is created
 * (or the one made before is reused: Name, Email, Phone, Message; notifies the company, replies go to the visitor) and
 * every contact <form> of the copy is replaced by that Murano form (embed script, /embed/murano-forms.js), so what
 * visitors send lands in Forms. A template's static form posted nowhere.
 */
class WebsiteTemplateForms
{
    private const SKIP = '/comment|reply|search|newsletter|subscribe|checkout|login|signin|register|coupon|cart/i';

    /** @return array{forms: int, pages: int, form_id: ?int, key: ?string} */
    public static function apply(int $companyId, string $abs): array
    {
        $out = ['forms' => 0, 'pages' => 0, 'form_id' => null, 'key' => null];
        $pages = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.html?$/i', $f->getFilename())) {
                $html = (string) file_get_contents($f->getPathname());
                if (self::contactForms($html)) {
                    $pages[] = $f->getPathname();
                }
            }
        }
        if (! $pages) {
            return $out;
        }

        $form = self::ensureForm($companyId);
        $out['form_id'] = (int) $form->id;
        $out['key'] = $form->public_key;
        $script = rtrim(PublicUrl::base($companyId), '/').'/embed/murano-forms.js';
        foreach ($pages as $path) {
            $html = (string) file_get_contents($path);
            $n = 0;
            $html = preg_replace_callback('/<form\b.*?<\/form>/is', function ($m) use ($form, &$n) {
                if (! self::isContact($m[0])) {
                    return $m[0];
                }
                $n++;

                return '<div class="murano-contact-form" data-murano-form="'.$form->public_key.'" style="width:100%"></div>';
            }, $html);
            if ($n) {
                if (! str_contains($html, 'murano-forms.js')) {
                    $tag = '<script src="'.htmlspecialchars($script, ENT_QUOTES).'" async></script>';
                    $html = stripos($html, '</body>') !== false ? preg_replace('/<\/body>/i', $tag."\n</body>", $html, 1) : $html.$tag;
                }
                file_put_contents($path, $html);
                $out['forms'] += $n;
                $out['pages']++;
            }
        }

        return $out;
    }

    private static function contactForms(string $html): int
    {
        preg_match_all('/<form\b.*?<\/form>/is', $html, $m);

        return count(array_filter($m[0], fn ($f) => self::isContact($f)));
    }

    /**
     * A contact form: it has a message box, or its tag says "contact" (D'Piel's, Ciruplastic's: name / e-mail / phone
     * only) -- never a comment / search / newsletter / checkout... form, nor one without an e-mail or phone box.
     */
    private static function isContact(string $form): bool
    {
        preg_match('/<form\b[^>]*>/i', $form, $open);
        $open = $open[0] ?? '';
        if (preg_match(self::SKIP, $open)) {
            return false;
        }
        if (stripos($form, '<textarea') !== false) {
            return true;
        }

        return preg_match('/contact/i', $open) && preg_match('/email|correo|phone|tel[eé]fono|celular/i', $form);
    }

    /** The company's website contact form: the one made before, else a new one; the Forms module switched on. */
    private static function ensureForm(int $companyId): object
    {
        return DB::transaction(function () use ($companyId) {
            $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first();
            $es = ($s->default_language ?? 'en') === 'es';
            $flags = json_decode((string) ($s->module_flags ?? ''), true) ?: [];
            if (($flags['forms'] ?? true) === false && $s) {
                $flags['forms'] = true;
                DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags), 'updated_at' => now()]);
            }

            $names = ['Contáctenos', 'Contact us'];
            $form = DB::table('forms')->where('company_id', $companyId)->whereIn('name', $names)->whereNull('deleted_at')->orderBy('id')->first();
            if ($form) {
                if (! $form->status) {
                    DB::table('forms')->where('id', $form->id)->update(['status' => true, 'updated_at' => now()]);
                }

                return DB::table('forms')->find($form->id);
            }

            $fields = [
                ['key' => 'name', 'type' => 'text', 'label' => $es ? 'Nombre' : 'Name', 'required' => true, 'width' => 'half'],
                ['key' => 'email', 'type' => 'email', 'label' => $es ? 'Correo electrónico' : 'Email', 'required' => true, 'width' => 'half'],
                ['key' => 'phone', 'type' => 'text', 'label' => $es ? 'Teléfono' : 'Phone', 'required' => false, 'width' => 'half'],
                ['key' => 'message', 'type' => 'textarea', 'label' => $es ? 'Mensaje' : 'Message', 'required' => true, 'rows' => 5],
            ];
            $settings = array_merge(FormSchema::defaultSettings(), [
                'reply_to_field' => 'email',
                'logo' => 'none',                 // inside the website: its own header is above
                'page_background' => '',          // the website shows through around the box
            ], $es ? ['language' => 'es', 'submit_text' => 'Enviar', 'success_message' => '¡Gracias! Recibimos su mensaje.',
                'notify_subject' => 'Nuevo mensaje desde el sitio web: {form}'] : ['notify_subject' => 'New message from the website: {form}']);
            $id = DB::table('forms')->insertGetId([
                'company_id' => $companyId, 'name' => $es ? 'Contáctenos' : 'Contact us',
                'public_key' => \App\Http\Controllers\Api\Forms\FormsController::newKey(), 'status' => true,
                'fields' => json_encode(FormSchema::fields($fields)), 'settings' => json_encode(FormSchema::settings($settings)),
                'next_number' => 1, 'created_by' => optional(auth('api')->user() ?? auth()->user())->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('forms')->find($id);
        });
    }
}
