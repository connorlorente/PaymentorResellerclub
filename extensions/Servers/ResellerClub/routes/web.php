<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\ResellerClub\Livewire\Search;

Route::group(['middleware' => ['web']], function () {
    Route::get('/domains', Search::class)->name('resellerclub.domains');
});
