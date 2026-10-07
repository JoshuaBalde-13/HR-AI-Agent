<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RobotEvent extends Model
{
    protected $fillable = [
        'role',
        'type',
        'message',
        'priority',
        'related_type',
        'related_id',
        'is_read',
    ];
}