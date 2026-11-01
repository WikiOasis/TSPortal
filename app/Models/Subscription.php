<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    public const UPDATED_AT = null;

    public const OBJECTS = ['case', 'investigation'];

    protected $fillable = ['user_id', 'object_type', 'object_id', 'source'];
}
