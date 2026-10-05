<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings for more than salons (KcolTech: IT appointments employee <-> client):
 *  - trays (stations) per company: each existing station goes to the company
 *    that owns it (demo record) or uses it; another company that also booked
 *    on it gets its own copy and its bookings move to the copy. A default
 *    station of another company is cleared.
 *  - bookings.mode (office | remote | onsite) + meeting_link + site_address,
 *    shown when Settings -> Booking -> "Appointment mode" is on.
 *  - settings.booking_invoice_mode: what completing a booking does --
 *    cash (sale paid in cash, as before) | unpaid (sale on credit) | none.
 *  - reminders: settings.booking_reminders_enabled + booking_reminder_hours
 *    ("24,1"), bookings.reminders_sent (hours already sent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trays', 'company_id')) {
            Schema::table('trays', function (Blueprint $table) {
                $table->unsignedInteger('company_id')->nullable()->index()->after('id');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'mode')) {
                $table->string('mode', 10)->nullable()->after('warehouse_id');
            }
            if (! Schema::hasColumn('bookings', 'meeting_link')) {
                $table->string('meeting_link', 500)->nullable()->after('mode');
            }
            if (! Schema::hasColumn('bookings', 'site_address')) {
                $table->text('site_address')->nullable()->after('meeting_link');
            }
            if (! Schema::hasColumn('bookings', 'reminders_sent')) {
                $table->string('reminders_sent', 50)->nullable()->after('site_address');
            }
        });

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'booking_mode_enabled')) {
                $table->boolean('booking_mode_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'booking_invoice_mode')) {
                $table->string('booking_invoice_mode', 10)->default('cash');
            }
            if (! Schema::hasColumn('settings', 'booking_reminders_enabled')) {
                $table->boolean('booking_reminders_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'booking_reminder_hours')) {
                $table->string('booking_reminder_hours', 30)->default('24,1');
            }
        });

        $this->assignStationsToCompanies();
    }

    private function assignStationsToCompanies(): void
    {
        $now = now();
        foreach (DB::table('trays')->whereNull('company_id')->orderBy('id')->get() as $tray) {
            $usage = DB::table('bookings as b')->join('clients as c', 'c.id', '=', 'b.customer_id')
                ->where('b.tray_id', $tray->id)->selectRaw('c.company_id, count(*) n')
                ->groupBy('c.company_id')->orderByDesc('n')->pluck('n', 'company_id');
            $owner = DB::table('demo_records')->where('record_type', 'trays')->where('record_id', $tray->id)->value('company_id')
                ?: $usage->keys()->first();
            if (! $owner) {
                continue; // unused, unowned: stays shared until someone edits it
            }
            DB::table('trays')->where('id', $tray->id)->update(['company_id' => $owner]);

            foreach ($usage->keys() as $companyId) {
                if ((int) $companyId === (int) $owner) {
                    continue;
                }
                $copyId = DB::table('trays')->insertGetId([
                    'company_id' => $companyId, 'name' => $tray->name, 'description' => $tray->description,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('bookings')->where('tray_id', $tray->id)
                    ->whereIn('customer_id', DB::table('clients')->where('company_id', $companyId)->select('id'))
                    ->update(['tray_id' => $copyId]);
            }
        }

        // A default station must be the company's own.
        foreach (DB::table('settings')->whereNotNull('booking_default_tray_id')->get(['id', 'company_id', 'booking_default_tray_id']) as $s) {
            $trayCompany = DB::table('trays')->where('id', $s->booking_default_tray_id)->value('company_id');
            if ($trayCompany && (int) $trayCompany !== (int) $s->company_id) {
                DB::table('settings')->where('id', $s->id)->update(['booking_default_tray_id' => null]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['booking_mode_enabled', 'booking_invoice_mode', 'booking_reminders_enabled', 'booking_reminder_hours'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['mode', 'meeting_link', 'site_address', 'reminders_sent'] as $c) {
                if (Schema::hasColumn('bookings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        // trays.company_id stays (copies made by up() keep their bookings).
    }
};
