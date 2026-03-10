<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerProfile extends Model
{
    protected $connection = 'market';
    protected $table = 'buyer_profiles';
    protected $guarded = [];
}