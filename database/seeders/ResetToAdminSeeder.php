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
            $adminEmail = trim((string) env('RESET_ADMIN_EMAIL'));
            $admins = DB::table('users')->where('is_admin', true)->where('active', true)
                ->when($adminEmail !== '', fn ($query) => $query->where('email', $adminEmail))
                ->lockForUpdate()->limit(2)->pluck('id');

            if ($admins->count() !== 1) {
                throw new RuntimeException('Cần xác định đúng một Admin hệ thống đang hoạt động. Kiểm tra tài khoản hoặc đặt RESET_ADMIN_EMAIL.');
            }

            $this->call(ResetHubLearningDataSeeder::class);
            DB::table('lti_platforms')->delete();

            foreach (['sessions', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
                DB::table($table)->delete();
            }

            DB::table('users')->where('id', '!=', $admins->sole())->delete();
            DB::table('tenants')->delete();
        });
    }
}
