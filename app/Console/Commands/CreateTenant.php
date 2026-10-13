<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenant:create {name : Unique tenant name}')]
#[Description('Create a tenant')]
class CreateTenant extends Command
{
    public function handle(): int
    {
        $name = $this->argument('name');
        if (trim($name) === '' || mb_strlen($name) > 255) {
            $this->error('Tenant name must contain 1 to 255 characters.');

            return self::FAILURE;
        }
        $tenant = Tenant::firstOrCreate(['name' => $name]);
        if (! $tenant->wasRecentlyCreated) {
            $this->error('A tenant with that name already exists.');

            return self::FAILURE;
        }
        $this->info('Tenant created: '.$tenant->name);

        return self::SUCCESS;
    }
}
