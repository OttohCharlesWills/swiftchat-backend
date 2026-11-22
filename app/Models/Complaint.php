<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $fillable = [
        'name',
        'email',
        'message',
        'attachment_path',
        'status',
        'ip_address',
    ];
}