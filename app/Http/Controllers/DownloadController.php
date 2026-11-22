<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DownloadController extends Controller
{
    public function index(Request $request)
    {
        $dir = $this->downloadsDir($request);

        $builds = [];

        if (is_dir($dir)) {
            foreach (scandir($dir) as $file) {
                // matches swiftchat-1.0.0+24.apk
                if (!preg_match('/^swiftchat-(\d+(?:\.\d+)*)\+(\d+)\.apk$/i', $file, $m)) {
                    continue;
                }

                $path = $dir . DIRECTORY_SEPARATOR . $file;

                $builds[] = [
                    'file'    => $file,
                    'version' => $m[1],
                    'build'   => (int) $m[2],
                    'size'    => $this->formatSize(filesize($path)),
                    'date'    => date('M j, Y', filemtime($path)),
                    'url'     => url('downloads/' . rawurlencode($file)),
                ];
            }
        }

        // newest build first
        usort($builds, fn ($a, $b) => $b['build'] <=> $a['build']);

        return view('download', [
            'latest' => $builds[0] ?? null,
            'older'  => array_slice($builds, 1),
        ]);
    }

    /**
     * Folder where the APKs are uploaded (the one served at /downloads/).
     * Override with DOWNLOADS_PATH in .env if it ever lives somewhere else.
     */
    private function downloadsDir(Request $request): string
    {
        if ($custom = env('DOWNLOADS_PATH')) {
            return rtrim($custom, '/\\');
        }

        $docRoot = $request->server('DOCUMENT_ROOT');

        if ($docRoot && is_dir($docRoot . '/downloads')) {
            return rtrim($docRoot, '/\\') . '/downloads';
        }

        return public_path('downloads');
    }

    private function formatSize(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
}