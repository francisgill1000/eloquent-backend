<?php

use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/invoice/generate', [InvoiceController::class, 'generatePdf']);

Route::get('/', function () {
    return view('welcome');
});
