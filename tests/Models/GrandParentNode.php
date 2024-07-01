<?php

namespace Tests\Models;

class GrandParentNode extends Model
{
    protected $table = 'grand_parent_nodes';
    protected $fillable = [
        'name',
        'grand_parent_input',
    ];
}