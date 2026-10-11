<?php

namespace App\Models;

use App\Models\Concerns\HasDuringRange;
use App\Models\Concerns\SerializesUtcTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasDuringRange, SerializesUtcTimestamps;

    protected $fillable = ['resource_id', 'external_type', 'external_id', 'metadata', 'id', 'starts_at', 'ends_at', 'idempotency_key'];

    protected $casts = ['lock_version' => 'integer', 'metadata' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    protected $hidden = ['during'];

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        $attributes['metadata'] = (object) ($this->metadata ?? []);

        return $attributes;
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
