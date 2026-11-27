<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetToAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('ResetToAdminSeeder chỉ chạy trong môi trường local hoặc testing.');
        }

        DB::transaction(function (): void {
            if (! DB::table('users')->where('is_admin', true)->where('active', true)->exists()) {
                throw new RuntimeException('Cần ít nhất một Admin hệ thống đang hoạt động trước khi reset.');
            }

            $this->call(ResetHubLearningDataSeeder::class);
            DB::table('lti_platforms')->delete();

            foreach (['sessions', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
                DB::table($table)->delete();
            }

            DB::table('users')->where('is_admin', false)->delete();
            DB::table('tenants')->delete();
        });
    }
}
