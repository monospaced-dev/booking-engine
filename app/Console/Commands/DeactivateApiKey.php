<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apikey:deactivate {--name= : The name of the app this key belongs to}')]
#[Description('Deactivates an API key')]
class DeactivateApiKey extends Command
{
    /*
     * Execute the console command
     *
     * @throws RandomException
     */
    public function handle()
    {
        if (!$this->option('name')) {
            $this->error('Please specify a name for the Api Key needing to be deactivated');
            return self::FAILURE;
        }

        $apiKey = ApiKey::where('name', $this->option('name'))->firstOrFail();
        $apiKey->update(['is_active' => false]);
        $apiKey->save();

        $this->info('Api key for ' . $this->option('name') . ' has been deactivated');
    }
}
