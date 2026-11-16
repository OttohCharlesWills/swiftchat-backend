<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExternalCronController extends Controller
{
   
public function sendScheduledMessages(Request $request)
{
    $expectedUsername = (string) config('services.wixchat_cron.username');
    $expectedPassword = (string) config('services.wixchat_cron.password');

    $providedUsername = (string) $request->getUser();
    $providedPassword = (string) $request->getPassword();

    if (
        $expectedUsername === '' ||
        $expectedPassword === '' ||
        !hash_equals($expectedUsername, $providedUsername) ||
        !hash_equals($expectedPassword, $providedPassword)
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    $lock = Cache::lock('wixchat-scheduled-messages', 55);

    if (!$lock->get()) {
        return response()->json([
            'success' => true,
            'message' => 'Another execution is already running.',
        ]);
    }

    try {
        Artisan::call('messages:send-scheduled');

        $output = Artisan::output();

        Log::info('External scheduled message runner executed', [
            'output' => $output,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled message processing completed.',
            'output' => trim($output),
        ]);

    } catch (\Throwable $e) {
        Log::error('External scheduled message runner failed', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Scheduled message processing failed.',
        ], 500);

    } finally {
        $lock->release();
    }
}

}
