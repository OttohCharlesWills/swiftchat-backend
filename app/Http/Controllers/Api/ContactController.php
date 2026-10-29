<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

class ContactController extends Controller
{
    /**
     * Get the Google libphonenumber instance.
     */
    private function phoneUtil(): PhoneNumberUtil
    {
        return PhoneNumberUtil::getInstance();
    }

    /**
     * Normalize a phone number into E.164 format.
     *
     * Examples:
     * 08031234567       -> +2348031234567
     * 8031234567        -> +2348031234567
     * 2348031234567     -> +2348031234567
     * +2348031234567    -> +2348031234567
     * 002348031234567   -> +2348031234567
     *
     * Defaults to Nigeria for numbers without an international prefix.
     */
    private function normalizePhone(
        string $number,
        ?string $region = 'NG'
    ): ?string {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        // Remove common formatting characters but preserve a leading +.
        $number = preg_replace('/[^\d+]/', '', $number);

        // Convert international 00 prefix to +.
        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        }

        // Handle Nigerian numbers written as 234XXXXXXXXXX
        // without a leading plus.
        if (preg_match('/^234\d{10}$/', $number)) {
            $number = '+' . $number;
        }

        // A number with + already contains its international prefix.
        $parseRegion = str_starts_with($number, '+')
            ? null
            : $this->resolveRegion($region);

        try {
            $phone = $this->phoneUtil()->parse(
                $number,
                $parseRegion
            );

            if (!$this->phoneUtil()->isValidNumber($phone)) {
                return null;
            }

            return $this->phoneUtil()->format(
                $phone,
                PhoneNumberFormat::E164
            );

        } catch (NumberParseException $e) {
            return null;
        }
    }

    /**
     * Resolve a country region.
     *
     * Accepts ISO region codes such as NG, GH, US.
     * Also supports 234 as a Nigerian calling code.
     */
    private function resolveRegion(?string $region): string
    {
        if (!$region) {
            return 'NG';
        }

        $region = strtoupper(trim($region));

        $callingCodes = [
            '234' => 'NG',
            '233' => 'GH',
            '254' => 'KE',
            '27'  => 'ZA',
            '1'   => 'US',
            '44'  => 'GB',
        ];

        if (isset($callingCodes[$region])) {
            return $callingCodes[$region];
        }

        if (preg_match('/^[A-Z]{2}$/', $region)) {
            return $region;
        }

        return 'NG';
    }

    /**
     * Bulk sync contacts uploaded from the user's phone.
     */
    public function sync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contacts' => 'required|array|min:1',

            'contacts.*.saved_name' => 'required|string|max:255',

            'contacts.*.phone_number' => 'required|string|max:30',

            // Optional ISO region, e.g. NG, GH, US, GB.
            'contacts.*.country_region' => 'nullable|string|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $authUser = $request->user();

        $incoming = collect($request->contacts)->map(function ($entry) {
            $region = $entry['country_region'] ?? 'NG';

            $entry['phone_number'] = $this->normalizePhone(
                $entry['phone_number'],
                $region
            );

            return $entry;
        });

        // Separate invalid numbers instead of storing incorrect numbers.
        $validContacts = $incoming->filter(function ($entry) {
            return $entry['phone_number'] !== null;
        })->values();

        $invalidCount = $incoming->count() - $validContacts->count();

        if ($validContacts->isEmpty()) {
            return response()->json([
                'message' => 'No valid phone numbers were found.',
                'total_synced' => 0,
                'registered_count' => 0,
                'invalid_count' => $invalidCount,
            ]);
        }

        // Find registered users using normalized international numbers.
        $phoneNumbers = $validContacts
            ->pluck('phone_number')
            ->unique()
            ->values();

        $registeredUsers = User::whereIn(
            'phone_number',
            $phoneNumbers
        )
            ->where('id', '!=', $authUser->id)
            ->where('is_verified', true)
            ->get()
            ->keyBy('phone_number');

        $synced = [];

        foreach ($validContacts as $entry) {
            $phone = $entry['phone_number'];

            $matchedUser = $registeredUsers->get($phone);

            $contact = Contact::updateOrCreate(
                [
                    'user_id' => $authUser->id,
                    'phone_number' => $phone,
                ],
                [
                    'saved_name' => $entry['saved_name'],
                    'contact_user_id' => $matchedUser?->id,
                    'is_registered' => (bool) $matchedUser,
                ]
            );

            $synced[] = $contact;
        }

        return response()->json([
            'message' => 'Contacts synced successfully.',
            'total_synced' => count($synced),
            'registered_count' => collect($synced)
                ->where('is_registered', true)
                ->count(),
            'invalid_count' => $invalidCount,
        ]);
    }

    /**
     * Look up a phone number and check if it belongs to a WixChat user.
     */
    public function lookup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:6|max:30',
            'country_code' => 'nullable|string|max:10',
            'country_region' => 'nullable|string|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        $authUser = $request->user();

        $region = $request->country_region
            ?? $request->country_code
            ?? 'NG';

        $normalized = $this->normalizePhone(
            $request->phone_number,
            $region
        );

        if (!$normalized) {
            return response()->json([
                'found' => false,
                'message' => 'Invalid phone number.',
            ]);
        }

        $user = User::where('phone_number', $normalized)
            ->where('id', '!=', $authUser->id)
            ->where('is_verified', true)
            ->first();

        if (!$user) {
            return response()->json([
                'found' => false,
            ]);
        }

        // If that person blocked me, behave as if they don't exist.
        $blockedMe = Contact::where('user_id', $user->id)
            ->where('contact_user_id', $authUser->id)
            ->where('is_blocked', true)
            ->exists();

        if ($blockedMe) {
            return response()->json([
                'found' => false,
            ]);
        }

        // Find the caller's saved contact record.
        $saved = Contact::where('user_id', $authUser->id)
            ->where(function ($query) use ($user, $normalized) {
                $query->where('contact_user_id', $user->id)
                    ->orWhere('phone_number', $normalized);
            })
            ->first();

        return response()->json([
            'found' => true,

            'user' => [
                'id' => $user->id,
                'phone_number' => $user->phone_number,
                'name' => $user->name,
                'saved_name' => $saved?->saved_name,
                'display_name' => $saved?->saved_name ?: $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
                'is_contact' => (bool) $saved,
            ],
        ]);
    }

    /**
     * List contacts.
     */
    public function index(Request $request)
    {
        $authUser = $request->user();

        // Repair existing unlinked contacts whose phone numbers
        // already use the normalized E.164 format.
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
                        'is_registered' => true,
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

        $contacts = $query
            ->orderBy('saved_name')
            ->get()
            ->map(function ($contact) {
                $data = $contact->toArray();

                $data['display_name'] = $contact->saved_name
                    ?: ($contact->contactUser?->name
                        ?? $contact->phone_number);

                $data['profile_name'] = $contact->contactUser?->name;

                return $data;
            });

        return response()->json($contacts);
    }

    /**
     * Toggle favorite.
     */
    public function toggleFavorite(Request $request, $id)
    {
        $contact = Contact::where(
            'user_id',
            $request->user()->id
        )->findOrFail($id);

        $contact->update([
            'is_favorite' => !$contact->is_favorite,
        ]);

        return response()->json([
            'message' => 'Favorite status updated.',
            'contact' => $contact,
        ]);
    }

    /**
     * Toggle block.
     */
    public function toggleBlock(Request $request, $id)
    {
        $contact = Contact::where(
            'user_id',
            $request->user()->id
        )->findOrFail($id);

        $contact->update([
            'is_blocked' => !$contact->is_blocked,
        ]);

        return response()->json([
            'message' => 'Block status updated.',
            'contact' => $contact,
        ]);
    }
}
