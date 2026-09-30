<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentEnrollment extends Model
{
    protected $table = 'student_enrollment';

    protected $fillable = [
        'user_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'lrn',
        'grade_level',
        'student_type',
        'section_id',
        'birthday',
        'birth_place',
        'address',
        'mother_name',
        'father_name',
        'guardian_name',
        'emergency_contact',
        'emergency_contact_2',
        'preferred_session',
        'payment_method',
        'payment_plan',
        'proof_of_payment',
        'photo',
        'status',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];

    /**
     * The parent/guardian who submitted this enrollment.
     */
    public function user(): BelongsTo
    {
          return $this->belongsTo(Parents::class, 'user_id');  // ← change this
    }

    /**
     * Uploaded requirement documents (PSA, report card, etc.) for this enrollment.
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(EnrollmentRequirement::class, 'enrollment_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /**
     * Public URL for the child's uploaded photo, or null if none set.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        // null when the file is gone (e.g. uploaded to a server whose
        // storage was later wiped) so the page shows initials instead of a
        // broken image. asset() rather than Storage::url(): it uses the
        // site's actual address, not APP_URL, same as every other upload.
        if (! $this->photo || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($this->photo)) {
            return null;
        }

        return asset('storage/' . $this->photo);
    }

    public function tuitionPlan(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TuitionPlan::class, 'student_enrollment_id');
    }

    /**
     * Check if enrollment is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if enrollment has been approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Scope: only pending enrollments.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: only approved enrollments.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}