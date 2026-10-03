<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stickers\StickerImporter;
use Illuminate\Http\Request;

class StickerAdminController extends Controller
{
    /**
     * POST /admin/stickers/import   (multipart: file=<zip|wastickers>, official=0|1)
     * Header: X-Sticker-Admin-Key
     */
    public function import(Request $request, StickerImporter $importer)
    {
        $request->validate([
            'file' => 'required|file|max:51200',
            'official' => 'sometimes|boolean',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        abort_unless(in_array($ext, ['zip', 'wastickers'], true), 422, 'Upload a .zip or .wastickers file.');

        try {
            $result = $importer->import($file->getRealPath(), [
                'source' => 'upload',
                'official' => $request->has('official') ? $request->boolean('official') : null,
                'fallback_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}