<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlackoutDate extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'resource_id',
        'start_time',
        'end_time',
        'reason'
    ];

    public function resources(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
