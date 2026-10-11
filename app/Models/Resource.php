<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    use HasFactory;

    protected $fillable = ['external_type', 'external_id'];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function blackouts(): HasMany
    {
        return $this->hasMany(Blackout::class);
    }
}
