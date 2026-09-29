<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Intranet (per company, staff only) -- lives inside the admin app (/next):
 *  - intranet_posts   employee news / announcements / info posts
 *  - intranet_files   document library (files stored privately under
 *                     storage/app/intranet/company_{id}/files, streamed by an
 *                     authenticated endpoint -- never a public URL)
 *  - intranet_albums + intranet_photos   photo gallery
 *  - intranet_events  company events (the calendar also shows employee
 *                     birthdays and HRM holidays, read from their own tables)
 *  - intranet_pages   static pages (slug unique per company)
 *
 * Also:
 *  - permissions `intranet_view` (read the intranet: every role of every
 *    company except "Customer") and `intranet_manage` (Owner / Admin /
 *    Admin-ALL). Company 999 is the template copied for new companies, so its
 *    roles carry the grants forward.
 *  - sidebar rows ("Intranet" + "Intranet_Management" right after Dashboard,
 *    with their children) for every company that saved a layout.
 *  - the UI strings.
 */
return new class extends Migration
{
    private const MODULES = [
        'intranet' => ['/app/intranet/home', '/app/intranet/calendar', '/app/intranet/files', '/app/intranet/gallery'],
        'intranet_management' => ['/app/intranet-admin/news', '/app/intranet-admin/files', '/app/intranet-admin/gallery', '/app/intranet-admin/events', '/app/intranet-admin/pages'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('intranet_posts')) {
            Schema::create('intranet_posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('title', 190);
                $table->string('category', 30)->default('news'); // news | announcement | info
                $table->string('excerpt', 500)->nullable();
                $table->longText('body')->nullable();
                $table->string('cover_image', 255)->nullable();
                $table->boolean('pinned')->default(false);
                $table->boolean('published')->default(true);
                $table->dateTime('published_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'published', 'published_at']);
            });
        }
        if (! Schema::hasTable('intranet_files')) {
            Schema::create('intranet_files', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('title', 190);
                $table->text('description')->nullable();
                $table->string('folder', 120)->nullable();
                $table->string('original_name', 255);
                $table->string('path', 255); // relative to storage/app
                $table->string('mime', 120)->nullable();
                $table->string('extension', 12)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->unsignedInteger('downloads')->default(0);
                $table->timestamps();
                $table->index(['company_id', 'folder']);
            });
        }
        if (! Schema::hasTable('intranet_albums')) {
            Schema::create('intranet_albums', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->string('title', 190);
                $table->text('description')->nullable();
                $table->date('album_date')->nullable();
                $table->unsignedBigInteger('cover_photo_id')->nullable();
                $table->boolean('published')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('intranet_photos')) {
            Schema::create('intranet_photos', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->unsignedBigInteger('album_id')->index();
                $table->string('title', 190)->nullable();
                $table->string('image', 255);
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('intranet_events')) {
            Schema::create('intranet_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->unsignedInteger('user_id')->nullable();
                $table->string('title', 190);
                $table->text('description')->nullable();
                $table->string('location', 190)->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at')->nullable();
                $table->boolean('all_day')->default(false);
                $table->string('color', 20)->nullable();
                $table->timestamps();
                $table->index(['company_id', 'starts_at']);
            });
        }
        if (! Schema::hasTable('intranet_pages')) {
            Schema::create('intranet_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->string('title', 190);
                $table->string('slug', 190);
                $table->longText('content')->nullable();
                $table->boolean('published')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'slug']);
            });
        }

        $this->permissions();
        $this->translations();
        $this->menuPositions();
    }

    private function permissions(): void
    {
        $now = now();
        $ids = [];
        foreach ([
            'intranet_view' => ['Intranet (view)', 'Read the company intranet: news, files, gallery, calendar and pages'],
            'intranet_manage' => ['Intranet Management', 'Manage the company intranet content'],
        ] as $name => [$label, $description]) {
            $ids[$name] = DB::table('permissions')->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name, 'label' => $label, 'description' => $description,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
        }

        $viewRoles = DB::table('roles')->where('name', '!=', 'Customer')->whereNull('deleted_at')->pluck('id');
        foreach ($viewRoles as $roleId) {
            DB::table('permission_role')->updateOrInsert(['permission_id' => $ids['intranet_view'], 'role_id' => $roleId]);
        }
        $manageRoles = DB::table('roles')->whereIn('name', ['Owner', 'Admin', 'Admin-ALL'])->whereNull('deleted_at')->pluck('id');
        foreach ($manageRoles as $roleId) {
            DB::table('permission_role')->updateOrInsert(['permission_id' => $ids['intranet_manage'], 'role_id' => $roleId]);
        }
    }

    private function menuPositions(): void
    {
        if (! Schema::hasTable('menu_positions')) {
            return;
        }
        $now = now();
        $companies = DB::table('menu_positions')->distinct()->pluck('company_id');
        foreach ($companies as $companyId) {
            $rows = DB::table('menu_positions')->where('company_id', $companyId);
            if ((clone $rows)->whereIn('item_key', array_keys(self::MODULES))->whereNull('parent_key')->exists()) {
                continue;
            }
            $dash = (clone $rows)->where('item_key', 'dashboard')->whereNull('parent_key')->first(['sort_order', 'location']);
            $after = $dash ? (int) $dash->sort_order : -1;
            $location = $dash->location ?? 'sidebar';

            DB::table('menu_positions')->where('company_id', $companyId)->whereNull('parent_key')
                ->where('sort_order', '>', $after)->increment('sort_order', count(self::MODULES));

            $i = 0;
            foreach (self::MODULES as $moduleKey => $children) {
                DB::table('menu_positions')->insert([
                    'company_id' => $companyId, 'item_key' => $moduleKey, 'parent_key' => null,
                    'sort_order' => $after + (++$i), 'external' => 0, 'is_custom' => 0, 'hidden' => 0,
                    'location' => $location, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($children as $n => $childKey) {
                    if (DB::table('menu_positions')->where('company_id', $companyId)->where('item_key', $childKey)->exists()) {
                        continue;
                    }
                    DB::table('menu_positions')->insert([
                        'company_id' => $companyId, 'item_key' => $childKey, 'parent_key' => $moduleKey,
                        'sort_order' => $n, 'external' => 0, 'is_custom' => 0, 'hidden' => 0,
                        'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $keys = [
            // menu / page titles
            'Intranet' => ['Intranet', 'Intranet', 'Intranet'],
            'Intranet_Management' => ['Intranet Management', 'Gestión de Intranet', 'Gestion de l\'intranet'],
            'Intranet_Home' => ['Home', 'Inicio', 'Accueil'],
            'Intranet_Calendar' => ['Calendar', 'Calendario', 'Calendrier'],
            'Intranet_Files' => ['Files', 'Archivos', 'Fichiers'],
            'Intranet_Gallery' => ['Gallery', 'Galería', 'Galerie'],
            'Intranet_News' => ['News', 'Noticias', 'Actualités'],
            'Intranet_Events' => ['Events', 'Eventos', 'Événements'],
            'Intranet_Pages' => ['Pages', 'Páginas', 'Pages'],
            'Intranet_View' => ['View intranet', 'Ver intranet', 'Voir l\'intranet'],
            'Intranet_Manage' => ['Manage intranet', 'Administrar intranet', 'Gérer l\'intranet'],
            // common fields
            'Intranet_Title' => ['Title', 'Título', 'Titre'],
            'Intranet_Category' => ['Category', 'Categoría', 'Catégorie'],
            'Intranet_Cat_News' => ['News', 'Noticia', 'Actualité'],
            'Intranet_Cat_Announcement' => ['Announcement', 'Anuncio', 'Annonce'],
            'Intranet_Cat_Info' => ['Info', 'Información', 'Info'],
            'Intranet_Excerpt' => ['Summary', 'Resumen', 'Résumé'],
            'Intranet_Body' => ['Content', 'Contenido', 'Contenu'],
            'Intranet_Cover' => ['Cover image', 'Imagen de portada', 'Image de couverture'],
            'Intranet_Remove_Cover' => ['Remove cover', 'Quitar portada', 'Retirer la couverture'],
            'Intranet_Pinned' => ['Pinned', 'Fijado', 'Épinglé'],
            'Intranet_Published' => ['Published', 'Publicado', 'Publié'],
            'Intranet_Published_At' => ['Publish date', 'Fecha de publicación', 'Date de publication'],
            'Intranet_Draft' => ['Draft', 'Borrador', 'Brouillon'],
            'Intranet_Author' => ['Author', 'Autor', 'Auteur'],
            'Intranet_Add_Post' => ['New post', 'Nueva publicación', 'Nouvelle publication'],
            'Intranet_Edit_Post' => ['Edit post', 'Editar publicación', 'Modifier la publication'],
            'Intranet_Read_More' => ['Read more', 'Leer más', 'Lire la suite'],
            'Intranet_All_News' => ['All news', 'Todas las noticias', 'Toutes les actualités'],
            'Intranet_Latest_News' => ['Latest news', 'Últimas noticias', 'Dernières actualités'],
            'Intranet_Back' => ['Back', 'Volver', 'Retour'],
            // files
            'Intranet_Upload_Files' => ['Upload files', 'Subir archivos', 'Téléverser des fichiers'],
            'Intranet_Folder' => ['Folder', 'Carpeta', 'Dossier'],
            'Intranet_All_Folders' => ['All folders', 'Todas las carpetas', 'Tous les dossiers'],
            'Intranet_File_Size' => ['Size', 'Tamaño', 'Taille'],
            'Intranet_Downloads' => ['Downloads', 'Descargas', 'Téléchargements'],
            'Intranet_Download' => ['Download', 'Descargar', 'Télécharger'],
            'Intranet_Recent_Files' => ['Recent files', 'Archivos recientes', 'Fichiers récents'],
            'Intranet_Files_Hint' => [
                'PDF, Office documents, text, images and ZIP files, up to 20 MB each. Files are private: only this company\'s staff can download them.',
                'PDF, documentos de Office, texto, imágenes y ZIP, hasta 20 MB cada uno. Los archivos son privados: solo el personal de esta empresa puede descargarlos.',
                'PDF, documents Office, texte, images et ZIP, jusqu\'à 20 Mo chacun. Les fichiers sont privés : seul le personnel de cette société peut les télécharger.',
            ],
            'Intranet_Updated' => ['Updated', 'Actualizado', 'Mis à jour'],
            // gallery
            'Intranet_Albums' => ['Albums', 'Álbumes', 'Albums'],
            'Intranet_Album' => ['Album', 'Álbum', 'Album'],
            'Intranet_Add_Album' => ['New album', 'Nuevo álbum', 'Nouvel album'],
            'Intranet_Edit_Album' => ['Edit album', 'Editar álbum', 'Modifier l\'album'],
            'Intranet_Album_Date' => ['Date', 'Fecha', 'Date'],
            'Intranet_Photos' => ['Photos', 'Fotos', 'Photos'],
            'Intranet_Set_Cover' => ['Use as cover', 'Usar como portada', 'Utiliser comme couverture'],
            'Intranet_Select_Album' => ['Select an album to manage its photos.', 'Seleccione un álbum para administrar sus fotos.', 'Sélectionnez un album pour gérer ses photos.'],
            'Intranet_Gallery_Highlights' => ['Gallery', 'Galería', 'Galerie'],
            // events / calendar
            'Intranet_Add_Event' => ['New event', 'Nuevo evento', 'Nouvel événement'],
            'Intranet_Edit_Event' => ['Edit event', 'Editar evento', 'Modifier l\'événement'],
            'Intranet_Location' => ['Location', 'Ubicación', 'Lieu'],
            'Intranet_Starts' => ['Starts', 'Inicia', 'Début'],
            'Intranet_Ends' => ['Ends', 'Termina', 'Fin'],
            'Intranet_All_Day' => ['All day', 'Todo el día', 'Toute la journée'],
            'Intranet_Color' => ['Color', 'Color', 'Couleur'],
            'Intranet_Upcoming_Events' => ['Upcoming events', 'Próximos eventos', 'Événements à venir'],
            'Intranet_Past_Events' => ['Past events', 'Eventos pasados', 'Événements passés'],
            'Intranet_Birthdays' => ['Birthdays', 'Cumpleaños', 'Anniversaires'],
            'Intranet_Birthdays_Month' => ['Birthdays this month', 'Cumpleaños del mes', 'Anniversaires du mois'],
            'Intranet_Upcoming_Birthdays' => ['Upcoming birthdays', 'Próximos cumpleaños', 'Anniversaires à venir'],
            'Intranet_Birthday_Of' => ['{name}\'s birthday', 'Cumpleaños de {name}', 'Anniversaire de {name}'],
            'Intranet_Today' => ['Today', 'Hoy', 'Aujourd\'hui'],
            'Intranet_Holiday' => ['Holiday', 'Feriado', 'Jour férié'],
            'Intranet_Legend' => ['Legend', 'Leyenda', 'Légende'],
            'Intranet_Show_Birthdays' => ['Birthdays', 'Cumpleaños', 'Anniversaires'],
            'Intranet_Show_Holidays' => ['Holidays', 'Feriados', 'Jours fériés'],
            'Intranet_Show_Events' => ['Events', 'Eventos', 'Événements'],
            // pages
            'Intranet_Add_Page' => ['New page', 'Nueva página', 'Nouvelle page'],
            'Intranet_Edit_Page' => ['Edit page', 'Editar página', 'Modifier la page'],
            'Intranet_Slug' => ['Slug (URL)', 'Slug (URL)', 'Slug (URL)'],
            'Intranet_Slug_Hint' => ['Leave empty to build it from the title.', 'Déjelo vacío para generarlo desde el título.', 'Laissez vide pour le générer à partir du titre.'],
            'Intranet_Company_Pages' => ['Company pages', 'Páginas de la empresa', 'Pages de l\'entreprise'],
            // misc
            'Intranet_Welcome' => ['Welcome to the intranet', 'Bienvenido a la intranet', 'Bienvenue sur l\'intranet'],
            'Intranet_Nothing_Yet' => ['Nothing here yet.', 'Aún no hay nada aquí.', 'Rien pour le moment.'],
            'Intranet_Search' => ['Search', 'Buscar', 'Rechercher'],
            'Intranet_Actions' => ['Actions', 'Acciones', 'Actions'],
            'Intranet_Save' => ['Save', 'Guardar', 'Enregistrer'],
            'Intranet_Cancel' => ['Cancel', 'Cancelar', 'Annuler'],
            'Intranet_Description' => ['Description', 'Descripción', 'Description'],
            'Intranet_Date' => ['Date', 'Fecha', 'Date'],
            'Intranet_Move_Up' => ['Move up', 'Subir', 'Monter'],
            'Intranet_Move_Down' => ['Move down', 'Bajar', 'Descendre'],
            'Intranet_View_Page' => ['View', 'Ver', 'Voir'],
        ];
        $locales = array_diff(Schema::getColumnListing('translations'), ['id', 'key', 'is_default', 'customized_locales', 'created_at', 'updated_at']);
        foreach ($keys as $key => [$en, $es, $fr]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            $row = ['key' => $key, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()];
            foreach ($locales as $l) {
                $row[$l] = $l === 'es' ? $es : ($l === 'fr' ? $fr : $en);
            }
            DB::table('translations')->insert($row);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        $keys = array_keys(self::MODULES);
        foreach (self::MODULES as $children) {
            $keys = array_merge($keys, $children);
        }
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->whereIn('item_key', $keys)->delete();
        }
        foreach (['intranet_view', 'intranet_manage'] as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            if ($permissionId) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }
        Schema::dropIfExists('intranet_pages');
        Schema::dropIfExists('intranet_events');
        Schema::dropIfExists('intranet_photos');
        Schema::dropIfExists('intranet_albums');
        Schema::dropIfExists('intranet_files');
        Schema::dropIfExists('intranet_posts');
    }
};
