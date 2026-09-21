<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use SoftDeletes;

    protected $table = 'subjects';

    protected $fillable = ['name', 'code', 'class_id', 'type', 'credit', 'pass_mark', 'is_active'];

    protected $casts = [
        'class_id' => 'integer',
        'credit' => 'decimal:2',
        'pass_mark' => 'integer',
        'is_active' => 'boolean',
    ];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id', 'id');
    }
}
