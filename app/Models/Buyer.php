<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Buyer extends Authenticatable
{
    use HasApiTokens;

    protected $connection = 'market'; 
    protected $table = 'buyers';        
    protected $guarded = [];

      public function buyerProfile()
    {
        return $this->hasOne(BuyerProfile::class, 'buyer_id', 'id');
    }
}