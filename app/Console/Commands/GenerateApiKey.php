<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Random\RandomException;

#[Signature('apikey:generate {--name= : The name of the app this key belongs to} {--tenant= : Required tenant name}')]
#[Description('Generate a new API key')]
class GenerateApiKey extends Command
{
    /**
     * Execute the console command.
     *
     * @throws RandomException
     */
    public function handle()
    {
        if (! $this->option('name')) {
            $this->error('Please specify a name for this key');

            return self::FAILURE;
        }

        $tenant = Tenant::where('name', $this->option('tenant'))->first();
        if (! $this->option('tenant') || ! $tenant) {
            $this->error('Please specify an existing tenant with --tenant.');

            return self::FAILURE;
        }

        $rawKey = bin2hex(random_bytes(32)); // 64 character cryptographically secure random string
        ApiKey::create(['tenant_id' => $tenant->id, 'name' => $this->option('name'), 'key' => hash('sha256', $rawKey), 'is_active' => true]);

        $this->info('Your API key (save this, it won\'t be shown again):');
        $this->info($rawKey);
    }
}
