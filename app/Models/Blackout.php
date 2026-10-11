<?php

namespace App\Models;

use App\Models\Concerns\HasDuringRange;
use App\Models\Concerns\SerializesUtcTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blackout extends Model
{
    use HasDuringRange, HasFactory, SerializesUtcTimestamps;

    protected $fillable = ['resource_id', 'during', 'note'];

    protected $hidden = ['during'];

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        foreach ($this->parseDuring($this->during) as $key => $date) {
            $attributes[$key] = $this->serializeDate($date);
        }

        return $attributes;
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
