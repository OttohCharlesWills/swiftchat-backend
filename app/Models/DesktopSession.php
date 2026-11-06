<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DesktopSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'code',
        'duration',
        'expires_at',
        'status',
        'attempts',
        'verified_at',
        'ip_address',
        'user_agent',
        'desktop_token_id',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'code',
        'token',
        'desktop_token_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Mark the session as expired and invalidate its
     * associated Sanctum token.
     */
    public function expire(): void
    {
        $this->revokeSanctumToken();

        $this->update([
            'status' => 'expired',
        ]);
    }

    /**
     * Revoke the desktop session and its Sanctum token.
     */
    public function revoke(): void
    {
        $this->revokeSanctumToken();

        $this->update([
            'status' => 'revoked',
        ]);
    }

    /**
     * Delete the exact Sanctum token belonging to this
     * desktop session.
     */
    protected function revokeSanctumToken(): void
    {
        if (!$this->desktop_token_id) {
            return;
        }

        $this->user
            ->tokens()
            ->where('id', $this->desktop_token_id)
            ->delete();

        /*
         * Clear the reference so we don't keep trying to
         * delete the same token.
         */
        $this->update([
            'desktop_token_id' => null,
        ]);
    }
}
