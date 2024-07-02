<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}