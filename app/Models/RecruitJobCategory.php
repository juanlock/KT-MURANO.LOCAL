<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitJobCategory extends Model
{
    use SoftDeletes;

    protected $table = 'recruit_job_categories';

    protected $fillable = ['company_id', 'name', 'description', 'is_active'];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function jobs()
    {
        return $this->hasMany(RecruitJob::class, 'category_id');
    }
}
