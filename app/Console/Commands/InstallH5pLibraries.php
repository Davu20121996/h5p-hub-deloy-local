<?php

namespace App\Console\Commands;

use App\H5p\LibraryInstaller;
use Illuminate\Console\Command;

class InstallH5pLibraries extends Command
{
    protected $signature = 'h5p:libraries:install {package : Trusted .h5p package containing library files}';

    protected $description = 'Install trusted H5P libraries centrally; unavailable to tenant users';

    public function handle(LibraryInstaller $installer): int
    {
        $path = $this->argument('package');
        if (! is_file($path)) {
            $this->error('Package does not exist.');

            return self::FAILURE;
        }
        try {
            $count = $installer->install($path);
            $this->info("Installed or updated $count libraries.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
