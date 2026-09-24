<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'bio' => 'sometimes|nullable|string|max:255',
            'profile_link' => 'sometimes|nullable|url|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->filled('name')) {
            $user->name = $request->input('name');
        }

        if ($request->has('bio')) {
            $user->bio = $request->input('bio');
        }

        if ($request->has('profile_link')) {
            $user->profile_link = $request->input('profile_link');
        }

        $user->save();

        return response()->json($user->fresh());
    }

    public function updateAvatar(Request $request)
{
    $validator = Validator::make($request->all(), [
        'file' => 'required|image|max:5120', // 5MB
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $user = $request->user();

    $uploaded = cloudinary()->uploadApi()->upload(
        $request->file('file')->getRealPath(),
        ['folder' => 'avatars']
    );

    $user->avatar_url = $uploaded['secure_url'];
    $user->save();

    return response()->json($user->fresh());
}

    public function show(Request $request, \App\Models\User $user)
    {
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
            'bio' => $user->bio,
            'profile_link' => $user->profile_link,
            'phone_number' => $user->phone_number,
        ]);
    }
}