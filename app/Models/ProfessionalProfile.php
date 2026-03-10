<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfessionalProfile extends Model
{
    protected $connection = 'market';
    protected $table = 'professional_profiles';
    protected $guarded = [];
}