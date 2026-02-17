<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class MediaController extends Controller
{
    /**
     * List media files for a store.
     * Optionally filter by folder.
     */
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        // 1. Fetch Files (Non-directory)
        $filesQuery = Media::where('store_id', $store->id)
            ->where('mime_type', '!=', 'directory');

        if ($request->has('folder')) {
            $filesQuery->inFolder($request->folder ?: null);
        } else {
            $filesQuery->whereNull('folder');
        }

        if ($request->boolean('images_only')) {
            $filesQuery->images();
        }

        $files = $filesQuery->orderBy('created_at', 'desc')->get();

        // 2. Fetch Folders (Directory placeholders and Legacy)
        $currentFolder = $request->folder ?: null;

        // New system: Fetch directory records
        $folders = Media::where('store_id', $store->id)
            ->where('mime_type', 'directory')
            ->where('folder', $currentFolder)
            ->orderBy('filename', 'asc')
            ->pluck('filename');

        // Legacy system: Fetch distinct folder columns (only for root)
        if (!$currentFolder) {
            $legacyFolders = Media::where('store_id', $store->id)
                ->whereNotNull('folder')
                ->where('mime_type', '!=', 'directory')
                ->distinct()
                ->pluck('folder');

            // Merge and unique
            $folders = $folders->merge($legacyFolders)->unique()->values();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'files' => $files,
                'folders' => $folders,
            ],
        ]);
    }

    // ... (store method remains mostly the same, maybe validation?)

    /**
     * Create a new virtual folder.
     */
    public function createFolder(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:100',
            'folder' => 'nullable|string|max:100',
        ]);

        $folderName = Str::slug($request->name);
        $parentFolder = $request->folder;

        // check if exists
        $exists = Media::where('store_id', $store->id)
            ->where('mime_type', 'directory')
            ->where('filename', $folderName)
            ->where('folder', $parentFolder)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Folder already exists'], 422);
        }

        // Create directory record
        Media::create([
            'store_id' => $store->id,
            'uploaded_by' => auth()->id(),
            'filename' => $folderName, // Used as display name
            'path' => 'virtual', // Placeholder
            'disk' => 'public',
            'mime_type' => 'directory', // Marker
            'size' => 0,
            'folder' => $parentFolder, // Support nesting
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Folder created.',
            'data' => [
                'folder' => $folderName,
            ],
        ]);
    }

    /**
     * Upload a new image.
     * Auto-compresses images on upload.
     */
    public function store(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            'folder' => 'nullable|string|max:100',
        ]);

        $file = $request->file('file');
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.webp';
        $extension = 'webp';
        $mimeType = 'image/webp';

        // Generate unique filename
        $filename = Str::uuid() . '.' . $extension;
        $storagePath = "stores/{$store->id}/media";

        if ($request->folder) {
            $storagePath .= '/' . Str::slug($request->folder);
        }

        $fullPath = "{$storagePath}/{$filename}";

        // Auto-compress image before saving
        try {
            $compressedPath = $this->compressImage($file, $fullPath, $extension);
            $fileSize = Storage::disk('public')->size($compressedPath);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Illuminate\Support\Facades\Log::error("Image compression failed: " . $e->getMessage());

            // Fallback: store original file with original extension
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $extension; // Update filename with correct extension
            $fullPath = "{$storagePath}/{$filename}"; // Update path
            $mimeType = $file->getClientMimeType(); // Use original mime type

            $file->storeAs($storagePath, $filename, 'public');
            $fileSize = $file->getSize();
        }

        $media = Media::create([
            'store_id' => $store->id,
            'uploaded_by' => auth()->id(),
            'filename' => $originalName, // Keep original name for display
            'path' => $fullPath,
            'disk' => 'public',
            'mime_type' => $mimeType,
            'size' => $fileSize,
            'folder' => $request->folder,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File uploaded successfully.',
            'data' => $media,
        ], 201);
    }

    /**
     * Show single media file.
     */
    public function show(string $storeSlug, Media $media)
    {
        return response()->json([
            'success' => true,
            'data' => $media,
        ]);
    }

    /**
     * Update media details (e.g. move to folder)
     */
    public function update(Request $request, string $storeSlug, string $mediaId)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $media = Media::where('id', $mediaId)->first();

        if (!$media) {
            return response()->json(['success' => false, 'message' => 'Media not found'], 404);
        }

        if ((int) $media->store_id !== (int) $store->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized access'], 403);
        }

        $request->validate([
            'folder' => 'nullable|string|max:100',
        ]);

        // Move file (virtual move)
        if ($request->has('folder')) {
            $media->folder = $request->folder;
        }

        $media->save();

        return response()->json([
            'success' => true,
            'message' => 'Media updated successfully',
            'data' => $media
        ]);
    }

    /**
     * Delete a media file.
     */
    public function destroy(string $storeSlug, string $mediaId)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $media = Media::where('id', $mediaId)->first();

        if (!$media) {
            \Illuminate\Support\Facades\Log::error("Media deletion failed: Media ID {$mediaId} not found.");
            return response()->json(['success' => false, 'message' => 'Media not found'], 404);
        }

        if ((int) $media->store_id !== (int) $store->id) {
            \Illuminate\Support\Facades\Log::error("Media deletion failed: Media ID {$mediaId} (Store: {$media->store_id}) does not belong to store {$store->slug} (ID: {$store->id}).");
            return response()->json(['success' => false, 'message' => 'Unauthorized deletion'], 403);
        }

        // Delete physical file
        try {
            $media->deleteFile();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to delete physical file for Media ID {$mediaId}: " . $e->getMessage());
            // Continue to delete record anyway to clean up DB
        }

        // Delete database record
        $media->delete();

        return response()->json([
            'success' => true,
            'message' => 'File deleted successfully.',
        ]);
    }



    /**
     * Compress image before storing.
     */
    private function compressImage($file, string $fullPath, string $extension): string
    {
        // Use Intervention Image for compression
        // Note: Requires intervention/image package
        // composer require intervention/image

        $manager = new ImageManager(new Driver());
        $image = $manager->read($file->getPathname());

        // Resize if too large (max 1920px width)
        if ($image->width() > 1920) {
            $image->scale(width: 1920);
        }

        // Determine quality based on format
        $quality = 80;
        if ($extension === 'png') {
            $quality = 8; // PNG compression level 0-9
        }

        // Create directory if it doesn't exist
        $directory = dirname(storage_path("app/public/{$fullPath}"));
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        // Save compressed image
        $savePath = storage_path("app/public/{$fullPath}");

        // Force WebP conversion with 80% quality
        $image->toWebp(80)->save($savePath);

        return $fullPath;
    }
}
