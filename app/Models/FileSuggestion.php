<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FileSuggestion extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_scan_id',
        'file_path',
        'line_number',
        'code_snippet',
        'suggestion',
        'status',
        'ai_model',
        'token_count',
        'last_modified_at',
        'metadata',
        'created_at',
        'updated_at',
        'error',
        'retry_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'line_number' => 'integer',
        'metadata' => 'array',
        'last_modified_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    /**
     * Get the file scan that owns the suggestion.
     */
    public function fileScan()
    {
        return $this->belongsTo(FileScan::class, 'file_scan_id');
    }

    /**
     * Check if this suggestion is pending processing.
     * 
     * @return bool
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this suggestion has been processed.
     * 
     * @return bool
     */
    public function isProcessed()
    {
        return $this->status === 'processed';
    }

    /**
     * Check if this suggestion has failed processing.
     * 
     * @return bool
     */
    public function hasFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Increment the retry count.
     * 
     * @return void
     */
    public function incrementRetry()
    {
        $this->retry_count++;
        $this->save();
    }
}
