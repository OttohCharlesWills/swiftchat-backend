<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FontLibraryController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'category' => 'nullable|in:sans-serif,serif,display,handwriting,monospace',
            'sort' => 'nullable|in:popularity,alpha,date,style,trending',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $apiKey = config('services.google_fonts.key');

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Google Fonts API key is not configured.',
            ], 503);
        }

        try {
            $sort = $request->input('sort', 'popularity');

            $fonts = Cache::remember(
                "wixchat.google_fonts.catalog.v1.{$sort}",
                now()->addHours(24),
                function () use ($apiKey, $sort) {
                    $response = Http::timeout(30)->get(
                        'https://www.googleapis.com/webfonts/v1/webfonts',
                        [
                            'key' => $apiKey,
                            'sort' => $sort,
                            'fields' => 'items(family,category,variants,subsets,files,version,lastModified)',
                        ]
                    );

                    $response->throw();

                    return $response->json('items', []);
                }
            );

            $collection = collect($fonts);

            // Search by font name
            if ($request->filled('q')) {
                $query = mb_strtolower($request->q);

                $collection = $collection->filter(
                    fn ($font) => str_contains(
                        mb_strtolower($font['family']),
                        $query
                    )
                );
            }

            // Filter by category
            if ($request->filled('category')) {
                $collection = $collection->where(
                    'category',
                    $request->category
                );
            }

            // Pagination
            $page = $request->integer('page', 1);
            $perPage = $request->integer('per_page', 30);

            $total = $collection->count();

            $results = $collection
                ->forPage($page, $perPage)
                ->values();

            return response()->json([
                'success' => true,
                'data' => $results,
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load the font library. Please try again.',
            ], 502);
        }
    }
}
