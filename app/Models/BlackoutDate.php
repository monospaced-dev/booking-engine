<?php

namespace App\Models;

use App\Models\Concerns\SerializesUtcTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlackoutDate extends Model
{
    use HasFactory, SerializesUtcTimestamps;

    protected $fillable = [
        'date',
        'resource_id',
        'start_time',
        'end_time',
        'note',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
