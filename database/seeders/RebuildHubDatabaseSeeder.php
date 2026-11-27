<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RebuildHubDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $database = DB::connection();
        if (! app()->environment('local', 'testing') || $database->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Seeder này chỉ chạy trên PostgreSQL trong môi trường local hoặc testing.');
        }

        $database->transaction(function () use ($database): void {
            $database->statement('LOCK TABLE users IN ACCESS EXCLUSIVE MODE');

            $adminAccounts = $database->table('users')->where('is_admin', true);
            if (! (clone $adminAccounts)->where('active', true)->exists()) {
                throw new RuntimeException('Cần ít nhất một Admin hệ thống đang hoạt động trước khi reset.');
            }

            $admins = $adminAccounts->get([
                'id', 'name', 'email', 'email_verified_at', 'password', 'remember_token',
                'created_at', 'updated_at', 'username', 'is_admin', 'active',
            ]);
            $lastUserId = max(
                $database->table('users')->max('id'),
                $database->scalar("SELECT pg_sequence_last_value(pg_get_serial_sequence('users', 'id')::regclass)"),
            );

            $database->getSchemaBuilder()->dropAllTables();

            if (Artisan::call('migrate', ['--database' => $database->getName(), '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Chạy lại migration thất bại. Database đã được hoàn tác.');
            }

            $database->table('users')->insert($admins->map(fn (object $admin): array => (array) $admin)->all());
            $database->selectOne("SELECT setval(pg_get_serial_sequence('users', 'id'), ?, true)", [$lastUserId]);
        });
    }
}
