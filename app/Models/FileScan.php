<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FileScan extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'site_url',
        'theme',
        'file_path',
        'file_type',
        'file_size',
        'scan_date',
        'scan_date',
        'issues_found',
        'status',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scan_date' => 'datetime',
        'processed_at' => 'datetime',
        'issues_found' => 'array',
    ];

    /**
     * Get the suggestion associated with the file scan.
     */
    public function suggestion()
    {
        return $this->hasOne(FileSuggestion::class);
    }

    /**
     * Get the files for this scan.
     */
    public function files()
    {
        return $this->hasMany(ScanFile::class);
    }
}
