<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    protected $fillable = [
        'name',
        'company',
        'email',
        'notes',
        'status',
    ];
}
