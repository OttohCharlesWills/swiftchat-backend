<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ThemeController extends Controller
{
    // List preset themes + this user's own custom themes
    public function index(Request $request)
    {
        $themes = Theme::where('is_active', true)
            ->where(function ($q) use ($request) {
                $q->where('type', 'preset')
                  ->orWhere('user_id', $request->user()->id);
            })
            ->get()
            ->map(fn ($theme) => $this->withEffectiveColor($theme));

        return response()->json($themes);
    }

    // Create a custom theme
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'             => 'required|string|max:255',
            'background_color' => ['required', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'text_color'       => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'], // null = auto
            'accent_color'     => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $theme = Theme::create([
            'user_id'          => $request->user()->id,
            'name'             => $request->name,
            'background_color' => $request->background_color,
            'text_color'       => $request->text_color, // stays null unless user manually overrides
            'accent_color'     => $request->accent_color,
            'type'             => 'custom',
        ]);

        return response()->json([
            'message' => 'Theme created.',
            'theme'   => $this->withEffectiveColor($theme),
        ], 201);
    }

    // User selects a theme (preset or their own custom one)
    public function select(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'theme_id' => 'required|exists:themes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $theme = Theme::where('is_active', true)
            ->where(function ($q) use ($request) {
                $q->where('type', 'preset')
                  ->orWhere('user_id', $request->user()->id);
            })
            ->findOrFail($request->theme_id);

        $request->user()->update(['theme_id' => $theme->id]);

        return response()->json([
            'message' => 'Theme updated.',
            'theme'   => $this->withEffectiveColor($theme),
        ]);
    }

    // Helper to attach the calculated/effective text color to the response
    private function withEffectiveColor(Theme $theme)
    {
        $theme->effective_text_color = $theme->effective_text_color; // triggers accessor
        return $theme;
    }
}