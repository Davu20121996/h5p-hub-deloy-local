<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetHubLearningDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('ResetHubLearningDataSeeder runs only in local or testing environments.');
        }

        DB::transaction(function (): void {
            foreach (['lti_logins', 'lti_launches', 'lti_resource_links', 'h5p_contents', 'tenant_identity', 'lti_identities'] as $table) {
                DB::table($table)->delete();
            }
        });
    }
}
