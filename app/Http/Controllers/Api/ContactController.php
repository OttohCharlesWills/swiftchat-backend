<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ContactController extends Controller
{
    // Bulk sync contacts uploaded from the user's phone
    public function sync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contacts'                 => 'required|array|min:1',
            'contacts.*.saved_name'    => 'required|string|max:255',
            'contacts.*.phone_number'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $authUser = $request->user();
        $incoming = collect($request->contacts);

        // Normalize all incoming phone numbers to E.164-ish format (+digits only)
        $incoming = $incoming->map(function ($entry) {
            $entry['phone_number'] = $this->normalizePhone($entry['phone_number']);
            return $entry;
        });

        // Get all normalized phone numbers being synced
        $phoneNumbers = $incoming->pluck('phone_number')->unique();

        // Find which of these numbers belong to registered Gist users
        $registeredUsers = User::whereIn('phone_number', $phoneNumbers)
            ->where('id', '!=', $authUser->id) // exclude self
            ->get()
            ->keyBy('phone_number');

        $synced = [];

        foreach ($incoming as $entry) {
            $matchedUser = $registeredUsers->get($entry['phone_number']);

            $contact = Contact::updateOrCreate(
                [
                    'user_id'      => $authUser->id,
                    'phone_number' => $entry['phone_number'],
                ],
                [
                    'saved_name'      => $entry['saved_name'],
                    'contact_user_id' => $matchedUser?->id,
                    'is_registered'   => (bool) $matchedUser,
                ]
            );

            $synced[] = $contact;
        }

        return response()->json([
            'message' => 'Contacts synced.',
            'total_synced'    => count($synced),
            'registered_count'=> collect($synced)->where('is_registered', true)->count(),
        ]);
    }

    // List contacts, with optional filters
    public function index(Request $request)
    {
        $query = Contact::with('contactUser')
            ->where('user_id', $request->user()->id);

        if ($request->boolean('registered_only')) {
            $query->where('is_registered', true);
        }

        if ($request->boolean('favorites_only')) {
            $query->where('is_favorite', true);
        }

        if (!$request->boolean('include_blocked')) {
            $query->where('is_blocked', false);
        }

        $contacts = $query->orderBy('saved_name')->get();

        return response()->json($contacts);
    }

    // Toggle favorite
    public function toggleFavorite(Request $request, $id)
    {
        $contact = Contact::where('user_id', $request->user()->id)->findOrFail($id);
        $contact->update(['is_favorite' => !$contact->is_favorite]);

        return response()->json(['message' => 'Favorite status updated.', 'contact' => $contact]);
    }

    // Toggle block
    public function toggleBlock(Request $request, $id)
    {
        $contact = Contact::where('user_id', $request->user()->id)->findOrFail($id);
        $contact->update(['is_blocked' => !$contact->is_blocked]);

        return response()->json(['message' => 'Block status updated.', 'contact' => $contact]);
    }

    // Normalize a phone number to a consistent format: '+' followed by digits only.
    // NOTE: this assumes the country code is already present in the number
    // (e.g. from the phone's contact country or Flutter-side parsing via
    // a library like phone_numbers_parser). It does NOT convert a purely
    // local number (e.g. "08012345678") into international format on its own.
    private function normalizePhone(string $number): string
    {
        return '+' . preg_replace('/[^0-9]/', '', $number);
    }
}