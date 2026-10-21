<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * User's rule, 2026-10-20 (safe to run twice; by key / company, not row id):
 * every company's mail server (Settings -> Mail) = AdminSystem's (999), only
 * the Sender Name changes to "Admin {company}". New companies get it on
 * creation (Server::forCompany); AdminSystem's Mail page has "Copy to every
 * company" (Server::deployTemplate) for later changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translations')) {
            $texts = [
                'mail_deploy_all' => ['Copy to every company', 'Copiar a todas las compañías'],
                'mail_deploy_confirm' => [
                    'Save this mail server and copy it to every company? Their current mail settings are replaced; each keeps the sender name "Admin {company}".',
                    '¿Guardar este servidor de correo y copiarlo a todas las compañías? Se reemplaza la configuración de correo de cada una; el nombre del remitente queda "Admin {compañía}".',
                ],
                'mail_deploy_help' => [
                    'AdminSystem\'s mail server is the template: new companies get it automatically. "Copy to every company" sends your changes to all of them.',
                    'El servidor de correo de AdminSystem es la plantilla: las compañías nuevas lo reciben automáticamente. "Copiar a todas las compañías" envía tus cambios a todas.',
                ],
            ];
            foreach ($texts as $key => [$en, $es]) {
                if (! DB::table('translations')->where('key', $key)->exists()) {
                    DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        if (Schema::hasTable('servers')) {
            Server::deployTemplate();
        }
    }

    public function down(): void
    {
        // The previous values are in the backup taken before the deploy.
    }
};
