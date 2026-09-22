<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuPosition extends Model
{
    protected $fillable = ['item_key', 'parent_key', 'sort_order', 'label', 'icon', 'route', 'external', 'is_custom', 'template', 'dynamic_table', 'hidden', 'location'];
}
