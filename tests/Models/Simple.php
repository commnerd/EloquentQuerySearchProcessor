<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Simple extends Model
{

    protected $fillable = [
        'name',
        'input',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentNode::class);
    }

    public function other_parent(): BelongsTo
    {
        return $this->belongsTo(OtherParentNode::class);
    }
}