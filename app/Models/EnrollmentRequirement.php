<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EnrollmentRequirement extends Model
{
    protected $fillable = [
        'enrollment_id',
        'document_type',
        'document_label',
        'path',
        'status',
        'feedback',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * The enrollment record this document belongs to.
     */
    public function enrollment()
    {
        return $this->belongsTo(StudentEnrollment::class, 'enrollment_id');
    }

    /**
     * Public URL for the uploaded file.
     */
    public function getUrlAttribute(): string
    {
        // asset(), not Storage::url(): Storage::url() gave a root-relative
        // "/storage/..." path that breaks when the site runs from a
        // subfolder (e.g. XAMPP's /capstone-name/public), leaving the
        // document preview blank. Same approach as every other upload.
        return asset('storage/' . $this->path);
    }
}