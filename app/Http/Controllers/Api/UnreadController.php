<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UnreadService;
use Illuminate\Http\Request;

class UnreadController extends Controller
{
    /**
     * Total unread messages for the logged-in user.
     * The app uses this to set the number on the app icon.
     */
    public function count(Request $request)
    {
        return response()->json([
            'count' => UnreadService::forUser($request->user()->id),
        ]);
    }
}