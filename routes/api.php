<?php

use Hwkdo\IntranetAppBewerbungen\Http\Controllers\Api\LegacyBewerbungenAiController;
use Illuminate\Support\Facades\Route;

Route::post('/api/bewerbungen/ai/enqueue', [LegacyBewerbungenAiController::class, 'enqueue'])
    ->middleware('auth:api');

Route::get('/api/bewerbungen/ai/definitionen', [LegacyBewerbungenAiController::class, 'definitionen'])
    ->middleware('auth:api');
