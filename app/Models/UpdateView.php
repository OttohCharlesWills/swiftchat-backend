<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UpdateView extends Model
{
    use HasFactory;

    protected $fillable = [
        'update_id',
        'viewer_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function update()
    {
        return $this->belongsTo(Update::class);
    }

    public function viewer()
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}

