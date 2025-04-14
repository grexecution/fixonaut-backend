<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_identifier',
        'site_url',
        'theme',
        'file_path',
        'file_type',
        'file_size',
        'storage_path',
        'status',
        'total_chunks',
        'received_chunks',
        'chunk_status',
        'started_at',
        'completed_at',
        'failure_reason',
        'scan_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'chunk_status' => 'array',
        'file_size' => 'integer',
        'total_chunks' => 'integer',
        'received_chunks' => 'integer',
    ];

    /**
     * Get the chunks for this file upload.
     */
    public function chunks()
    {
        return $this->hasMany(FileUploadChunk::class, 'upload_id');
    }

    /**
     * Get the scan associated with this file upload.
     */
    public function scan()
    {
        return $this->belongsTo(FileScan::class, 'scan_id');
    }
    
    /**
     * Check if all chunks have been received.
     * 
     * @return bool
     */
    public function isComplete()
    {
        return $this->received_chunks === $this->total_chunks;
    }
    
    /**
     * Check if the upload is in progress.
     * 
     * @return bool
     */
    public function isInProgress()
    {
        return $this->status === 'pending' || $this->status === 'processing';
    }
}
