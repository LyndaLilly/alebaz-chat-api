<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Seller extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'market';
    protected $table = 'sellers';
    protected $guarded = [];

    public function professionalProfile()
    {
        return $this->hasOne(ProfessionalProfile::class, 'seller_id', 'id');
    }

    public function otherProfile()
    {
        return $this->hasOne(OtherProfile::class, 'seller_id', 'id');
    }
}