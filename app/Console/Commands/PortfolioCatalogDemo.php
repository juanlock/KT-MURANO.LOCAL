<?php

namespace App\Console\Commands;

use App\Support\CompanyFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Portfolio tablet catalogs -- demos (2026-10-25, user's request):
 *  - La Sazon de Lock (restaurant): a "menu" catalog -- Arroces, Sopas, Postres,
 *    Bebidas with Peruvian dishes (service products created when missing; their
 *    pictures are generated placeholders to replace with real photos); the
 *    Kitchen display is turned on so tablet orders reach it.
 *  - New Era (salon): a "models" catalog over its real services (its categories
 *    are the groups; steps Woman / Man and Adult / Child).
 *  - Urban Cuts (barber): a "models" catalog; barber services created when
 *    missing, with New Era's barber photos copied into its own files.
 * Safe to run again (matched by name); `--fresh` rebuilds the three catalogs.
 *   php artisan portfolio:catalog-demo [--fresh]
 */
class PortfolioCatalogDemo extends Command
{
    protected $signature = 'portfolio:catalog-demo {--fresh : Delete and rebuild the demo catalogs}';

    protected $description = 'Create the demo tablet catalogs (La Sazon de Lock menu; New Era and Urban Cuts models)';

    public function handle(): int
    {
        $sazon = $this->companyId('La Sazon de Lock');
        $newera = $this->companyId('NewEra');
        $urban = $this->companyId('Urban Cuts');
        if ($sazon) {
            $this->menuDemo($sazon);
        }
        if ($newera) {
            $this->neweraDemo($newera);
        }
        if ($urban) {
            $this->urbanDemo($urban, $newera);
        }
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function companyId(string $name): ?int
    {
        $id = DB::table('companies')->whereNull('deleted_at')->where('name', 'like', $name.'%')->value('id');
        if (! $id) {
            $this->warn("$name: company not found -- skipped.");
        }

        return $id ? (int) $id : null;
    }

    /** The catalog (rebuilt with --fresh), its settings merged. */
    private function catalog(int $cid, string $type, string $name, array $settings): object
    {
        $c = DB::table('portfolio_catalogs')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->first();
        if ($c && $this->option('fresh')) {
            DB::table('portfolio_catalog_items')->where('catalog_id', $c->id)->delete();
            DB::table('portfolio_catalog_groups')->where('catalog_id', $c->id)->delete();
            DB::table('portfolio_catalogs')->where('id', $c->id)->delete();
            $c = null;
        }
        if (! $c) {
            do {
                $key = Str::lower(Str::random(10));
            } while (DB::table('portfolio_catalogs')->where('public_key', $key)->exists());
            $id = DB::table('portfolio_catalogs')->insertGetId([
                'company_id' => $cid, 'type' => $type, 'name' => $name, 'public_key' => $key, 'status' => true,
                'settings' => json_encode(array_merge(\App\Support\PortfolioCatalog::defaults($type, 'es'), $settings), JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $c = DB::table('portfolio_catalogs')->find($id);
        }

        return $c;
    }

    private function group(object $c, string $name, int $sort, ?string $image): int
    {
        $id = DB::table('portfolio_catalog_groups')->where('catalog_id', $c->id)->where('name', $name)->value('id');
        if (! $id) {
            $id = DB::table('portfolio_catalog_groups')->insertGetId(['company_id' => $c->company_id, 'catalog_id' => $c->id, 'name' => $name,
                'image' => $image, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()]);
        }

        return (int) $id;
    }

    private function item(object $c, int $gid, int $pid, int $sort, array $options = []): void
    {
        if (DB::table('portfolio_catalog_items')->where('group_id', $gid)->where('product_id', $pid)->exists()) {
            return;
        }
        DB::table('portfolio_catalog_items')->insert(['company_id' => $c->company_id, 'catalog_id' => $c->id, 'group_id' => $gid, 'product_id' => $pid,
            'sort_order' => $sort, 'options' => $options ? json_encode($options) : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A service product of the company (created from one of its rows when missing). */
    private function product(int $cid, string $name, float $price, ?string $image, string $category, string $note = ''): int
    {
        $id = DB::table('products')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->value('id');
        if ($id) {
            return (int) $id;
        }
        $cat = DB::table('categories')->where('company_id', $cid)->where('name', $category)->whereNull('deleted_at')->value('id')
            ?: DB::table('categories')->insertGetId(['company_id' => $cid, 'code' => Str::upper(Str::random(6)), 'name' => $category, 'created_at' => now(), 'updated_at' => now()]);
        $tpl = (array) DB::table('products')->where('company_id', $cid)->orderBy('id')->first();
        unset($tpl['id']);
        $row = array_merge($tpl, [
            'type' => 'is_service', 'code' => 'CAT'.random_int(10000000, 99999999), 'name' => $name, 'cost' => round($price * 0.35, 2),
            'price' => $price, 'wholesale_price' => $price, 'min_price' => $price, 'category_id' => $cat, 'brand_id' => null,
            'TaxNet' => 0, 'tax_method' => '1', 'discount' => null, 'image' => $image ?? 'no-image.png', 'note' => $note ?: null,
            'is_active' => 1, 'not_selling' => 0, 'woocommerce_id' => null, 'quickbooks_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('products')->insertGetId($row);
    }

    /** A placeholder picture: warm gradient + the dish name (until real photos are uploaded). */
    private function picture(int $cid, string $text, string $sub, array $from, array $to, int $w = 640, int $h = 480): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $col = imagecolorallocate($img, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
            imageline($img, 0, $y, $w, $y, $col);
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $soft = imagecolorallocatealpha($img, 255, 255, 255, 60);
        $font = collect(['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'])->first(fn ($f) => is_file($f));
        if ($font) {
            $size = $w > 1000 ? 54 : 34;
            $lines = explode("\n", wordwrap($text, $w > 1000 ? 26 : 16));
            $y0 = (int) ($h / 2 - (count($lines) - 1) * $size * 0.7);
            foreach ($lines as $i => $l) {
                $box = imagettfbbox($size, 0, $font, $l);
                imagettftext($img, $size, 0, (int) (($w - ($box[2] - $box[0])) / 2), $y0 + $i * (int) ($size * 1.4), $white, $font, $l);
            }
            $box = imagettfbbox(16, 0, $font, $sub);
            imagettftext($img, 16, 0, (int) (($w - ($box[2] - $box[0])) / 2), $h - 30, $soft, $font, $sub);
        } else {
            imagestring($img, 5, 20, (int) ($h / 2), $text, $white);
        }
        ob_start();
        imagejpeg($img, null, 82);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return CompanyFiles::putBytes($cid, 'portfolio', Str::slug($text).'.jpg', $bytes);
    }

    private function menuDemo(int $cid): void
    {
        $menu = [
            'Arroces' => [[255, 140, 50], [120, 40, 10], [
                ['Arroz con pollo', 28], ['Arroz chaufa de pollo', 26], ['Arroz chaufa de mariscos', 34], ['Tacu tacu con lomo', 38],
                ['Arroz con mariscos', 36], ['Arroz tapado', 24], ['Chaufa amazónico', 32], ['Arroz con pato', 42],
                ['Arroz a la cubana', 22], ['Arroz chaufa vegetariano', 24], ['Arroz con chancho', 30], ['Arroz norteño', 32],
            ]],
            'Sopas' => [[220, 90, 40], [90, 20, 10], [
                ['Chupe de camarones', 45], ['Sopa criolla', 22], ['Aguadito de pollo', 20], ['Parihuela', 42],
                ['Caldo de gallina', 24], ['Shambar', 22], ['Sopa seca', 26], ['Chilcano de pescado', 22],
                ['Menestrón', 20], ['Sancochado', 32], ['Patasca', 24], ['Caldo de cabeza', 20],
            ]],
            'Postres' => [[200, 60, 120], [60, 10, 50], [
                ['Suspiro limeño', 14], ['Mazamorra morada', 10], ['Arroz con leche', 10], ['Picarones', 15],
                ['Alfajores', 8], ['Turrón de Doña Pepa', 12], ['Crema volteada', 12], ['Tres leches', 14],
                ['Champús', 10], ['King Kong de manjar', 12], ['Helado de lúcuma', 12], ['Cocada', 9],
            ]],
            'Bebidas' => [[40, 140, 200], [10, 30, 70], [
                ['Chicha morada', 8], ['Inca Kola', 6], ['Limonada frozen', 10], ['Jugo de maracuyá', 10],
                ['Pisco sour', 22], ['Chilcano de pisco', 20], ['Emoliente', 6], ['Café pasado', 7],
                ['Agua mineral', 5], ['Cerveza Cusqueña', 12], ['Jugo de naranja', 9], ['Té de anís', 6],
            ]],
        ];
        $slides = [];
        $exists = DB::table('portfolio_catalogs')->where('company_id', $cid)->where('name', 'Carta - La Sazón de Lock')->whereNull('deleted_at')->exists();
        foreach (($exists && ! $this->option('fresh')) ? [] : [['La Sazón de Lock', 'Sabor peruano de verdad'], ['Pide desde tu mesa', 'Toca un plato y agrégalo a tu pedido'], ['Postres de la casa', 'Suspiro, picarones y más']] as $i => [$t, $s]) {
            $slides[] = $this->picture($cid, $t, $s, [[230, 120, 30], [180, 40, 60], [60, 120, 160]][$i], [20, 10, 10], 1600, 800);
        }
        $c = $this->catalog($cid, 'menu', 'Carta - La Sazón de Lock', [
            'title' => 'La Sazón de Lock', 'subtitle' => 'Cocina peruana · Haga su pedido aquí', 'slides' => $slides,
            'accent' => '#f5a623', 'send_kitchen' => true, 'hold_in_pos' => true, 'table_label' => 'Mesa',
        ]);
        $sort = 0;
        foreach ($menu as $group => [$from, $to, $dishes]) {
            $gid = null;
            foreach ($dishes as $i => [$dish, $price]) {
                $pic = DB::table('products')->where('company_id', $cid)->where('name', $dish)->whereNull('deleted_at')->value('image')
                    ?: $this->picture($cid, $dish, 'La Sazón de Lock', $from, $to);
                $pid = $this->product($cid, $dish, $price, $pic, $group);
                $gid ??= $this->group($c, $group, ++$sort, $pic);
                $this->item($c, $gid, $pid, $i + 1);
            }
        }
        // Tablet orders reach the Kitchen display of this demo restaurant.
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['enable_kitchen_display' => 1]);
        $this->line("La Sazon de Lock: menu catalog /c/{$c->public_key}");
    }

    /** Steps Woman / Man, Adult / Child (option ids fixed so the tags below match). */
    private function steps(): array
    {
        return [
            ['id' => 's1', 'name' => '¿Para quién es?', 'options' => [['id' => 'w', 'label' => 'Mujer', 'image' => ''], ['id' => 'm', 'label' => 'Hombre', 'image' => '']]],
            ['id' => 's2', 'name' => 'Edad', 'options' => [['id' => 'a', 'label' => 'Adulto', 'image' => ''], ['id' => 'k', 'label' => 'Niño', 'image' => '']]],
        ];
    }

    private function neweraDemo(int $cid): void
    {
        $byCat = DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.company_id', $cid)->whereNull('p.deleted_at')->where('p.image', 'like', 'files/%')
            ->orderBy('c.name')->orderBy('p.name')->get(['p.id', 'p.name', 'p.image', 'c.name as cat'])->groupBy('cat');
        $slides = $byCat->flatten(1)->filter(fn ($p) => in_array($p->cat, ['Hair & Beauty', 'Braids & Textured Hair', 'Nail Care'], true))->pluck('image')->take(3)->values()->all();
        $c = $this->catalog($cid, 'models', 'Servicios - New Era', [
            'title' => 'New Era Unisex Salon', 'subtitle' => 'Elija su servicio', 'slides' => $slides, 'accent' => '#d4af37',
            'send_kitchen' => false, 'hold_in_pos' => true, 'table_label' => 'Nombre', 'steps' => $this->steps(),
        ]);
        // Who each category is for (Woman / Man) -- the rest fit everyone.
        $gender = ['Barber Mens' => ['m'], 'Braids & Textured Hair' => ['w'], 'Make -up' => ['w'], 'Tanning' => ['w', 'm'], 'Med Spa' => ['w', 'm']];
        $sort = 0;
        foreach ($byCat as $cat => $products) {
            $gid = $this->group($c, $cat, ++$sort, $products->first()->image);
            foreach ($products->values() as $i => $p) {
                $kidsOk = (bool) preg_match('/hair ?cut|braid|trim|kid|child|cornrow|twist|nail|manicure|pedicure/i', $p->name)
                    && ! preg_match('/wax|laser|botox|filler|tattoo|pierc|tan|peel|bikini|brazilian/i', $p->name);
                $opts = [];
                if (isset($gender[$cat])) {
                    $opts['s1'] = $gender[$cat];
                }
                $opts['s2'] = $kidsOk ? ['a', 'k'] : ['a'];
                $this->item($c, $gid, (int) $p->id, $i + 1, $opts);
            }
        }
        $this->line("New Era: models catalog /c/{$c->public_key}");
    }

    private function urbanDemo(int $cid, ?int $neweraId): void
    {
        // New Era's barber photos, copied into Urban Cuts' own files.
        $photos = $neweraId ? DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.company_id', $neweraId)->where('c.name', 'Barber Mens')->where('p.image', 'like', 'files/%')->pluck('p.image')->all() : [];
        $copy = function (int $i) use ($photos, $cid) {
            $src = $photos ? $photos[$i % count($photos)] : null;
            if (! $src || ! is_file(public_path($src))) {
                return null;
            }

            return CompanyFiles::putBytes($cid, 'products', basename($src), (string) file_get_contents(public_path($src)));
        };
        $services = [
            'Cortes' => [['Corte clásico', 25, ['m'], ['a']], ['Fade / degradado', 30, ['m'], ['a']], ['Corte con navaja', 32, ['m'], ['a']],
                ['Corte infantil', 18, ['m', 'w'], ['k']], ['Corte + diseño', 35, ['m'], ['a', 'k']], ['Corte de dama (pelo corto)', 30, ['w'], ['a']]],
            'Barba' => [['Perfilado de barba', 15, ['m'], ['a']], ['Barba con toalla caliente', 22, ['m'], ['a']], ['Afeitado clásico', 20, ['m'], ['a']]],
            'Combos' => [['Corte + barba', 40, ['m'], ['a']], ['Papá e hijo', 38, ['m'], ['a', 'k']], ['Corte + lavado', 32, ['m', 'w'], ['a', 'k']]],
            'Tratamientos' => [['Lavado y peinado', 15, ['m', 'w'], ['a', 'k']], ['Tinte de barba', 25, ['m'], ['a']], ['Mascarilla negra', 20, ['m', 'w'], ['a']]],
        ];
        $c = $this->catalog($cid, 'models', 'Servicios - Urban Cuts', [
            'title' => 'Urban Cuts', 'subtitle' => 'Barbería · Elija su corte', 'accent' => '#e11d48',
            'send_kitchen' => false, 'hold_in_pos' => true, 'table_label' => 'Nombre', 'steps' => $this->steps(),
        ]);
        $n = 0;
        $sort = 0;
        $slides = [];
        foreach ($services as $group => $list) {
            $gid = null;
            foreach ($list as $i => [$name, $price, $who, $age]) {
                $existing = DB::table('products')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->value('image');
                $pic = $existing && $existing !== 'no-image.png' ? $existing : $copy($n++);
                $pid = $this->product($cid, $name, $price, $pic, $group);
                $gid ??= $this->group($c, $group, ++$sort, $pic);
                $this->item($c, $gid, $pid, $i + 1, ['s1' => $who, 's2' => $age]);
                if ($pic && count($slides) < 3 && $i === 0) {
                    $slides[] = $pic;
                }
            }
        }
        $s = json_decode((string) $c->settings, true);
        if (empty($s['slides']) && $slides) {
            $s['slides'] = $slides;
            DB::table('portfolio_catalogs')->where('id', $c->id)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)]);
        }
        $this->line("Urban Cuts: models catalog /c/{$c->public_key}");
    }
}
