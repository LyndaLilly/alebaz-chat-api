<?php
require __DIR__.'/broadcasting.php';
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
