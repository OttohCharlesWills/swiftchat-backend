<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupCallParticipant extends Model
{
    protected $fillable = [
        'group_call_id', 'user_id', 'status', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function call()
    {
        return $this->belongsTo(GroupCall::class, 'group_call_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}