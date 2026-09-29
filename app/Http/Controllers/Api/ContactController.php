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

    // Look up any phone number and check if it's on SwiftChat,
    // even if it's not in the caller's contacts.
    public function lookup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:6|max:20',
            'country_code' => 'nullable|string|max:5', // e.g. "234", used for local numbers like 0801...
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $authUser = $request->user();

        $candidates = $this->phoneCandidates(
            $request->phone_number,
            $request->country_code
        );

        if (empty($candidates)) {
            return response()->json(['found' => false]);
        }

        $user = User::whereIn('phone_number', $candidates)
            ->where('id', '!=', $authUser->id)
            ->where('is_verified', true)
            ->first();

        if (!$user) {
            return response()->json(['found' => false]);
        }

        // If that person blocked me, behave as if they don't exist.
        $blockedMe = Contact::where('user_id', $user->id)
            ->where('contact_user_id', $authUser->id)
            ->where('is_blocked', true)
            ->exists();

        if ($blockedMe) {
            return response()->json(['found' => false]);
        }

        // Do I have this person saved under my own name for them?
        $saved = Contact::where('user_id', $authUser->id)
            ->where(function ($q) use ($user, $candidates) {
                $q->where('contact_user_id', $user->id)
                ->orWhereIn('phone_number', $candidates);
            })
            ->first();

        return response()->json([
            'found' => true,
            'user' => [
                'id'           => $user->id,
                'phone_number' => $user->phone_number,
                'name'         => $user->name,
                'saved_name'   => $saved?->saved_name,
                'display_name' => $saved?->saved_name ?: $user->name,
                'username'     => $user->username,
                'avatar_url'   => $user->avatar_url,
                'is_contact'   => (bool) $saved,
            ],
        ]);
    }

    // Builds the possible stored formats for a typed number.
    private function phoneCandidates(string $raw, ?string $countryCode = null): array
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);

        if ($digits === '') {
            return [];
        }

        $candidates = ['+' . $digits];

        // Local format with a leading 0 -> use the country code if the app sends it
        if ($countryCode && str_starts_with($digits, '0')) {
            $cc = preg_replace('/[^0-9]/', '', $countryCode);
            $candidates[] = '+' . $cc . ltrim($digits, '0');
        }

        // "00234..." style international prefix
        if (str_starts_with($digits, '00')) {
            $candidates[] = '+' . substr($digits, 2);
        }

        return array_values(array_unique($candidates));
    }

    // List contacts, with optional filters
    // List contacts, with optional filters
public function index(Request $request)
{
    $authUser = $request->user();

    // Self-heal: link any contacts that were saved before the person
    // registered on SwiftChat (contact_user_id still null).
    $unlinked = Contact::where('user_id', $authUser->id)
        ->whereNull('contact_user_id')
        ->pluck('phone_number');

    if ($unlinked->isNotEmpty()) {
        $matches = User::whereIn('phone_number', $unlinked)
            ->where('id', '!=', $authUser->id)
            ->where('is_verified', true)
            ->pluck('id', 'phone_number');

        foreach ($matches as $phone => $userId) {
            Contact::where('user_id', $authUser->id)
                ->where('phone_number', $phone)
                ->whereNull('contact_user_id')
                ->update([
                    'contact_user_id' => $userId,
                    'is_registered'   => true,
                ]);
        }
    }

    $query = Contact::with('contactUser')
        ->where('user_id', $authUser->id);

    if ($request->boolean('registered_only')) {
        $query->where('is_registered', true);
    }

    if ($request->boolean('favorites_only')) {
        $query->where('is_favorite', true);
    }

    if (!$request->boolean('include_blocked')) {
        $query->where('is_blocked', false);
    }

    $contacts = $query->orderBy('saved_name')->get()->map(function ($contact) {
        $data = $contact->toArray();

        // What YOU saved them as always wins over their own profile name.
        $data['display_name'] = $contact->saved_name
            ?: ($contact->contactUser?->name ?? $contact->phone_number);

        // Their own profile name, in case the app wants to show both.
        $data['profile_name'] = $contact->contactUser?->name;

        return $data;
    });

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