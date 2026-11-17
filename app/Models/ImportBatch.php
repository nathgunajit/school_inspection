<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $fillable = ['type', 'file_name', 'uploaded_by', 'total_rows', 'added', 'updated', 'rejected', 'error_file_path'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
