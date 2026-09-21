<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolClass extends Model
{
    use SoftDeletes;

    protected $table = 'school_classes';

    protected $fillable = ['name', 'code', 'level', 'description', 'is_active'];

    protected $casts = [
        'level' => 'integer',
        'is_active' => 'boolean',
    ];

    public function sections()
    {
        return $this->hasMany(ClassSection::class, 'class_id', 'id');
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class, 'class_id', 'id');
    }

    /**
     * The class a student moves into at promotion: the next one up by level.
     * Null at the top of the school, which is what makes a leaver a leaver.
     */
    public function nextClass()
    {
        return static::whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('level', '>', $this->level)
            ->orderBy('level')
            ->first();
    }
}
