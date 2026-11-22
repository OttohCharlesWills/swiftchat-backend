<?php

namespace App\Http\Controllers;

use App\Mail\ComplaintMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ComplaintController extends Controller
{
    /**
     * Everyone who should receive each complaint.
     */
    private const RECIPIENTS = [
        'willsharches@gmail.com',
        'info.wixtrac@gmail.com',
    ];

    public function store(Request $request)
    {
        // Spam trap: bots fill the hidden field, real people never see it.
        if ($request->filled('website')) {
            return redirect(url('/') . '#complaint')
                ->with('success', 'Thank you. Your complaint has been received.');
        }

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'email'      => ['required', 'email', 'max:190'],
            'message'    => ['required', 'string', 'min:10', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        try {
            Mail::to(self::RECIPIENTS)->send(
                new ComplaintMail(
                    $data['name'],
                    $data['email'],
                    $data['message'],
                    $request->file('attachment'),
                    $request->ip()
                )
            );
        } catch (\Throwable $e) {
            Log::error('Complaint email failed: ' . $e->getMessage());

            return redirect(url('/') . '#complaint')
                ->withInput($request->except('attachment'))
                ->withErrors([
                    'message' => 'Sorry, we could not send your complaint right now. Please try again in a few minutes.',
                ]);
        }

        return redirect(url('/') . '#complaint')
            ->with('success', 'Thank you. Your complaint has been received and we will look into it.');
    }
}