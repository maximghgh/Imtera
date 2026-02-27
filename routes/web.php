<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/login', 'app');

Route::view('/dashboard', 'app');
Route::view('/profile', 'app');
Route::view('/review', 'app');
Route::view('/setting', 'app');
