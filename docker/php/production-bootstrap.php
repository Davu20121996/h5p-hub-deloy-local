<?php

use App\Lti\KeyStore;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! File::exists(config('lti.key_directory').'/active')) {
    $app->make(KeyStore::class)->generate();
    echo "Initial LTI signing key created.\n";
}

if (User::where('is_admin', true)->exists()) {
    echo "Administrator already exists; bootstrap skipped.\n";
    exit(0);
}

$data = [
    'username' => (string) getenv('ADMIN_USERNAME'),
    'password' => (string) getenv('ADMIN_PASSWORD'),
];
$validator = Validator::make($data, [
    'username' => 'required|alpha_dash:ascii|max:100|unique:users,username',
    'password' => 'required|string|min:12|max:255',
]);
if ($validator->fails()) {
    fwrite(STDERR, "Set a valid ADMIN_USERNAME and ADMIN_PASSWORD (12–255 characters) in Dockhand for the first deployment.\n");
    exit(1);
}

$user = new User;
$user->name = $data['username'];
$user->username = $data['username'];
$user->email = $data['username'].'@admin.invalid';
$user->password = $data['password'];
$user->is_admin = true;
$user->save();
echo "Initial administrator created.\n";
