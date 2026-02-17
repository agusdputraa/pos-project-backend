<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\UserPreference;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SettingController extends Controller
{
    // ==========================================
    // GET STORE SETTINGS
    // ==========================================
    public function getStoreSettings(string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $settings = $store->settings->pluck('value', 'key');

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    // ==========================================
    // UPDATE STORE SETTINGS
    // ==========================================
    public function updateStoreSettings(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable|string',
        ]);

        foreach ($request->settings as $setting) {
            StoreSetting::updateOrCreate(
                ['store_id' => $store->id, 'key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated.',
        ]);
    }

    // ==========================================
    // GET USER PREFERENCES
    // ==========================================
    public function getUserPreferences(Request $request)
    {
        $prefs = $request->user()->preferences->pluck('value', 'key');

        return response()->json([
            'success' => true,
            'data' => $prefs,
        ]);
    }

    // ==========================================
    // UPDATE USER PREFERENCES
    // ==========================================
    public function updateUserPreferences(Request $request)
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.*.key' => 'required|string',
            'preferences.*.value' => 'nullable|string',
        ]);

        $userId = $request->user()->id;

        foreach ($request->preferences as $pref) {
            UserPreference::updateOrCreate(
                ['user_id' => $userId, 'key' => $pref['key']],
                ['value' => $pref['value']]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated.',
        ]);
    }
}
