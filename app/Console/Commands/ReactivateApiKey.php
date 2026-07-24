<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apikey:reactivate {--name= : The name of the app this key belongs to}')]
#[Description('Reactivates an API key')]
class ReactivateApiKey extends Command
{
    /*
     * Execute the console command
     *
     * @throws RandomException
     */
    public function handle()
    {
        if (!$this->option('name')) {
            $this->error('Please specify a name for the Api Key needing to be reactivated.');
            return self::FAILURE;
        }

        $apiKey = ApiKey::where('name', $this->option('name'))->firstOrFail();
        $apiKey->update(['is_active' => true]);
        $apiKey->save();

        $this->info('Api key for ' . $this->option('name') . ' has been reactivated');
    }
}
