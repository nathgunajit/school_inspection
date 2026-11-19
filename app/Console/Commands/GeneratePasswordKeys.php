<?php

namespace App\Console\Commands;

use App\Services\PasswordCrypto;
use Illuminate\Console\Command;

/** V6 Module 5: create the RSA key pair used to encrypt passwords in the browser before submit. */
class GeneratePasswordKeys extends Command
{
    protected $signature = 'security:keys {--force : Replace an existing key pair (pages already open must be reloaded)}';

    protected $description = 'Create the RSA key pair for browser-side password encryption (storage/app/private/keys)';

    public function handle(PasswordCrypto $crypto): int
    {
        $paths = $crypto->generate((bool) $this->option('force'));
        if (! $paths) {
            $this->info('The key pair already exists. Use --force to replace it.');

            return self::SUCCESS;
        }
        foreach ($paths as $p) {
            $this->line("Written: $p");
        }
        $this->info('RSA-2048 key pair created. Keep password.key private and back it up with the server.');

        return self::SUCCESS;
    }
}
