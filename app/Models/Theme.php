<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Theme extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'background_color',
        'text_color',
        'accent_color',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Returns the effective text color: the manual override if set,
     * otherwise auto-calculated based on background luminance.
     */
    public function getEffectiveTextColorAttribute(): string
    {
        return $this->text_color ?? self::calculateContrastColor($this->background_color);
    }

    /**
     * Given a hex background color, return '#000000' or '#FFFFFF'
     * depending on which gives better readability (WCAG-style luminance check).
     */
    public static function calculateContrastColor(string $hexColor): string
    {
        $hex = ltrim($hexColor, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Relative luminance formula (perceived brightness)
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '#000000' : '#FFFFFF';
    }
}