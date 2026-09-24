<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Update extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'caption',
        'cloudinary_public_id',
        'cloudinary_url',
        'cloudinary_resource_type',
        'duration_hours',
        'expires_at',
        'allow_repost',
        'original_update_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'allow_repost' => 'boolean',
        'duration_hours' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function views()
    {
        return $this->hasMany(UpdateView::class);
    }

    public function reposts()
    {
        return $this->hasMany(UpdateRepost::class);
    }

    public function originalUpdate()
    {
        return $this->belongsTo(Update::class, 'original_update_id');
    }

    public function repostedUpdates()
    {
        return $this->hasMany(Update::class, 'original_update_id');
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }
}
