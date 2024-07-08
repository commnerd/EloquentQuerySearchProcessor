<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentNode extends Model
{
    protected $fillable = [
        'name',
        'parent_input',
        'due_date'
    ];

    public function grandparent(): BelongsTo
    {
        return $this->belongsTo(GrandParentNode::class, 'grand_parent_node_id');
    }

    public function other(): HasMany
    {
        return $this->hasMany(AnotherModel::class);
    }
}