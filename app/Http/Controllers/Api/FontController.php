<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Font;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FontController extends Controller
{
    // List all active fonts for the "choose your font" screen
    public function index()
    {
        $fonts = Font::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($fonts);
    }

    // User selects a font
    public function select(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'font_id' => 'required|exists:fonts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $font = Font::where('is_active', true)->findOrFail($request->font_id);

        $request->user()->update(['font_id' => $font->id]);

        return response()->json([
            'message' => 'Font updated.',
            'font'    => $font,
        ]);
    }
}