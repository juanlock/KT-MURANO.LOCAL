<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * IT Admin module, phase 1 (the user, 2026-11-28: HTD-IT's IT Admin as ONE Murano module "para aquellas compañías que necesiten
 * administrar su red", its own tables, empty -- no HTD data): locations (HTD's location + office in one), IP networks, IT assets
 * (with MACs and additional IPs), the IP history, and the network scan ("Scan this network": a script / cmd line run on an
 * office PC uploads what it found with a one-use token; nothing changes until it is reviewed and applied).
 * Business module `itadmin`, off by default. Permissions itadmin_view / itadmin_manage / itadmin_scan for every company's IT
 * Admin and Manager. Later phases: ISP / firewalls / VPN, IT services, rooms, gallery, providers, licenses, passwords,
 * purchases, knowledge base, applications, IT dashboard. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'itadmin_view' => ['IT Admin: see locations, networks and devices', 'IT Admin: ver ubicaciones, redes y equipos'],
        'itadmin_manage' => ['IT Admin: add, edit and delete', 'IT Admin: agregar, editar y eliminar'],
        'itadmin_scan' => ['IT Admin: scan networks and apply the scans', 'IT Admin: escanear redes y aplicar los escaneos'],
    ];

    private const TEXTS = [
        'IT_Admin' => ['IT Admin', 'IT Admin'],
        'IT_Locations' => ['Locations', 'Ubicaciones'],
        'IT_Networks' => ['Networks IP', 'Redes IP'],
        'IT_Scans' => ['Scan imports', 'Escaneos importados'],
        'IT_Assets' => ['Assets', 'Equipos'],
        'Perm_ITAdmin_View' => ['See locations, networks and devices', 'Ver ubicaciones, redes y equipos'],
        'Perm_ITAdmin_Manage' => ['Add, edit and delete', 'Agregar, editar y eliminar'],
        'Perm_ITAdmin_Scan' => ['Scan networks and apply the scans', 'Escanear redes y aplicar los escaneos'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('it_locations')) {
            Schema::create('it_locations', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedInteger('warehouse_id')->nullable();           // the Murano location it is, when there is one
                $t->string('name', 191);
                $t->string('code', 20)->nullable();                         // short name for the scan files (HTD's slug)
                $t->text('address')->nullable();
                $t->string('phone', 40)->nullable();
                $t->unsignedInteger('manager_employee_id')->nullable();
                $t->unsignedSmallInteger('sort_order')->default(100);
                $t->string('status', 20)->default('Active');
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('it_networks')) {
            Schema::create('it_networks', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('location_id')->nullable()->index();
                $t->string('name', 191);
                $t->string('network_address', 45);
                $t->unsignedTinyInteger('cidr');
                $t->string('type', 20)->nullable();                         // LAN | WAN | GUEST | LAN-HOME | VPN
                $t->boolean('is_wired')->default(true);
                $t->boolean('is_wifi')->default(false);
                $t->string('vlan', 50)->nullable();
                $t->string('gateway', 45)->nullable();
                $t->string('dns1', 45)->nullable();
                $t->string('dns2', 45)->nullable();
                $t->boolean('dhcp_enabled')->default(false);
                $t->string('dhcp_start', 45)->nullable();
                $t->string('dhcp_end', 45)->nullable();
                foreach (['24', '5', '6'] as $b) {
                    $t->boolean("wifi{$b}_enabled")->default(false);
                    $t->string("wifi{$b}_ssid", 191)->nullable();
                    $t->text("wifi{$b}_password")->nullable();              // encrypted (model cast)
                }
                $t->unsignedBigInteger('linked_asset_id')->nullable();      // the device that serves / manages it
                $t->text('notes')->nullable();
                $t->string('status', 20)->default('Active');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('it_assets')) {
            Schema::create('it_assets', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('location_id')->nullable()->index();
                $t->string('asset_tag', 60);
                $t->string('type', 50);
                $t->string('manufacturer', 191)->nullable();
                $t->string('model', 191)->nullable();
                $t->string('serial_number', 191)->nullable();
                foreach (['ip' => 'ip', 'wifi' => 'wifi_ip', 'wan' => 'wan_ip', 'mgmt' => 'mgmt_ip'] as $k => $col) {
                    $t->string("{$col}_address", 45)->nullable();
                    $t->unsignedBigInteger($k === 'ip' ? 'ip_network_id' : "{$k}_network_id")->nullable();
                }
                $t->boolean('no_ip')->default(false);
                $t->unsignedBigInteger('uplink_asset_id')->nullable();
                $t->string('uplink_medium', 20)->nullable();
                $t->unsignedInteger('employee_id')->nullable();             // assigned to (a Murano employee)
                $t->string('assigned_note', 191)->nullable();
                $t->date('purchase_date')->nullable();
                $t->date('warranty_end')->nullable();
                $t->string('status', 20)->default('Active');
                $t->string('work_mode', 10)->default('Office');
                $t->text('notes')->nullable();
                $t->text('source_note')->nullable();                        // where each change came from (scans...)
                $t->longText('details')->nullable();                        // the type's own fields (JSON)
                $t->timestamps();
                $t->index(['company_id', 'type']);
            });
        }
        if (! Schema::hasTable('it_asset_macs')) {
            Schema::create('it_asset_macs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('asset_id')->index();
                $t->string('mac', 17)->index();
                $t->string('label', 60)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('it_asset_extra_ips')) {
            Schema::create('it_asset_extra_ips', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('asset_id')->index();
                $t->unsignedBigInteger('network_id')->nullable();
                $t->string('ip_address', 45);
                $t->string('label', 100)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('it_ip_history')) {
            Schema::create('it_ip_history', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('ip', 45);
                $t->unsignedBigInteger('network_id')->nullable();
                $t->unsignedBigInteger('asset_id')->nullable();
                $t->string('asset_tag', 190)->nullable();
                $t->string('hostname', 190)->nullable();
                $t->string('mac', 17)->nullable();
                $t->string('event', 12);                                    // assigned | removed | seen
                $t->string('interface', 10)->nullable();
                $t->string('source', 190)->nullable();
                $t->unsignedBigInteger('scan_id')->nullable();
                $t->unsignedInteger('user_id')->nullable();
                $t->dateTime('first_at');
                $t->dateTime('last_at');
                $t->unsignedInteger('times_seen')->default(1);
                $t->timestamps();
                $t->index(['network_id', 'ip']);
            });
        }
        if (! Schema::hasTable('it_network_scans')) {
            Schema::create('it_network_scans', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('network_id')->index();
                $t->unsignedInteger('created_by')->nullable();
                $t->string('token_hash', 64)->nullable()->index();
                $t->text('token_enc')->nullable();
                $t->dateTime('expires_at')->nullable();
                $t->string('status', 12)->default('waiting');               // waiting | uploaded | reviewed | discarded
                $t->string('scanner_computer', 120)->nullable();
                $t->string('scanner_ip', 45)->nullable();
                $t->string('scanner_local_ip', 45)->nullable();
                $t->boolean('directly_connected')->nullable();
                $t->unsignedInteger('hosts_scanned')->default(0);
                $t->unsignedInteger('found_count')->default(0);
                $t->dateTime('scanned_at')->nullable();
                $t->dateTime('uploaded_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('it_network_scan_results')) {
            Schema::create('it_network_scan_results', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('scan_id')->index();
                $t->string('ip', 45);
                $t->string('mac', 17)->nullable();
                $t->string('hostname', 190)->nullable();
                $t->string('vendor', 190)->nullable();
                $t->string('method', 20)->nullable();
                $t->string('decision', 10)->nullable();                     // ignored
                $t->timestamps();
            });
        }

        foreach (self::PERMS as $name => [$en]) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $en, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $pids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->pluck('id') as $rid) {
            foreach ($pids as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
        foreach (self::PERMS + self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        foreach (['it_network_scan_results', 'it_network_scans', 'it_ip_history', 'it_asset_extra_ips', 'it_asset_macs', 'it_assets', 'it_networks', 'it_locations'] as $t) {
            Schema::dropIfExists($t);
        }
        $pids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $pids)->delete();
        DB::table('permissions')->whereIn('id', $pids)->delete();
    }
};
