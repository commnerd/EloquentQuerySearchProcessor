<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Commnerd\QuerySearchProcessor\QuerySearchProcessor;

abstract class Model extends BaseModel
{
    use QuerySearchProcessor;
}