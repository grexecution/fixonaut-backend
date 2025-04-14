<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FileUploadChunk extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_identifier',
        'upload_id',
        'chunk_index',
        'chunk_size',
        'chunk_path',
        'is_received',
        'received_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'chunk_index' => 'integer',
        'chunk_size' => 'integer',
        'is_received' => 'boolean',
        'received_at' => 'datetime'
    ];

    /**
     * Get the file upload that owns this chunk.
     */
    public function fileUpload()
    {
        return $this->belongsTo(FileUpload::class, 'upload_id');
    }
}
