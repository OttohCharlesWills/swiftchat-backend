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

    // Renamed from update() — that name collides with Eloquent's own
    // built-in Model::update(array $attributes, array $options) method,
    // which every model inherits. Declaring a same-named method with a
    // different signature is a fatal "declaration must be compatible"
    // error, and it crashes the moment this class is loaded at all
    // (so every endpoint touching UpdateView broke, not just this one).
    public function updatePost()
    {
        return $this->belongsTo(Update::class, 'update_id');
    }

    public function viewer()
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}