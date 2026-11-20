<?php

namespace App\Console\Commands;

use App\Lti\KeyStore;
use Illuminate\Console\Command;

class GenerateLtiKeys extends Command
{
    protected $signature = 'lti:keys';

    protected $description = 'Generate and activate a new RSA signing key; keep old public keys for verification';

    public function handle(KeyStore $keys): int
    {
        $this->info('Active key: '.$keys->generate());
        $this->line('JWKS: '.route('lti.jwks'));

        return self::SUCCESS;
    }
}
