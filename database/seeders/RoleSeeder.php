<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Insert some stuff
        DB::table('roles')->insert(
            [[
                'id' => 1,
                // The SuperAdmin users' role ("Owner" until 2026-11-02).
                'name' => 'SuperAdmin',
                'label' => 'SuperAdmin',
                'status' => 1,
                'description' => 'SuperAdmin: every permission, every company.',
            ]]
        );
    }
}
