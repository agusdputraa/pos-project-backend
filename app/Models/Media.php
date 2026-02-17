<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'store_id',
        'uploaded_by',
        'filename',
        'path',
        'disk',
        'mime_type',
        'size',
        'folder',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected $appends = ['url'];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getUrlAttribute(): string
    {
        // Route through the API media proxy to bypass server 403 on /storage/ paths
        return rtrim(config('app.url'), '/') . '/api/media/' . $this->path;
    }

    // ==========================================
    // HELPERS
    // ==========================================

    public function getStoragePath(): string
    {
        return "stores/{$this->store_id}/media/{$this->path}";
    }

    public function deleteFile(): bool
    {
        return Storage::disk($this->disk)->delete($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeInFolder($query, ?string $folder)
    {
        if ($folder === null) {
            return $query->whereNull('folder');
        }
        return $query->where('folder', $folder);
    }

    public function scopeImages($query)
    {
        return $query->where('mime_type', 'like', 'image/%');
    }
}
