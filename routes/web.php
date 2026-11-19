<?php

use Illuminate\Support\Facades\Route;

require __DIR__.'/member.php';

Route::get('/', function () {
    return view('welcome');
});
