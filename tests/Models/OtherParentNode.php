<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtherParentNode extends Model
{
    protected $fillable = [
        'name',
    ];

    public function other(): BelongsTo
    {
        return $this->belongsTo(AnotherModel::class);
    }
}