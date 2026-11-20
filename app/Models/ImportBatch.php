<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use HasEncryptedRouteKey;

    protected $fillable = ['type', 'district_id', 'file_name', 'uploaded_by', 'total_rows', 'added', 'updated', 'rejected', 'error_file_path', 'summary'];

    protected $casts = ['summary' => 'array'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** District chosen for a district-wise School Master upload (null for Location uploads and old files). */
    public function district()
    {
        return $this->belongsTo(District::class);
    }
}
