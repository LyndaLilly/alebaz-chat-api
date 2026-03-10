<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtherProfile extends Model
{
    protected $connection = 'market';
    protected $table = 'other_profiles';
    protected $guarded = [];
}