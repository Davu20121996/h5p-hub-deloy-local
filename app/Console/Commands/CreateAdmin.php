<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'admin:create {username}';

    protected $description = 'Create an administrator with a password entered securely';

    public function handle(): int
    {
        $data = ['username' => $this->argument('username'), 'password' => $this->secret('Password (at least 12 characters)')];
        $validator = Validator::make($data, ['username' => 'required|alpha_dash:ascii|max:100|unique:users,username', 'password' => 'required|string|min:12|max:255']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $user = new User;
        $user->name = $data['username'];
        $user->username = $data['username'];
        $user->email = $data['username'].'@admin.invalid';
        $user->password = $data['password'];
        $user->is_admin = true;
        $user->save();
        $this->info('Administrator created. Login at /admin/login.');

        return self::SUCCESS;
    }
}
