<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Random\RandomException;

#[Signature('apikey:generate {--name= : The name of the app this key belongs to}')]
#[Description('Generate a new API key')]
class GenerateApiKey extends Command
{
    /**
     * Execute the console command.
     * @throws RandomException
     */
    public function handle()
    {
        if (!$this->option('name')) {
            $this->error('Please specify a name for this key');
            return self::FAILURE;
        }

        $rawKey = bin2hex(random_bytes(32)); // 64 character cryptographically secure random string
        ApiKey::create(['name' => $this->option('name'), 'key' => hash('sha256', $rawKey), 'is_active' => true]);

        $this->info('Your API key (save this, it won\'t be shown again):');
        $this->info($rawKey);
    }
}
