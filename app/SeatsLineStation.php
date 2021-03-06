<?php

namespace App;

use App\Scopes\SortByOrderScope;
use Illuminate\Database\Eloquent\Model;

class SeatsLineStation extends Model
{
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new SortByOrderScope);
    }
}
