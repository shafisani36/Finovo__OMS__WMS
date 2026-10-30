<?php

use Illuminate\Support\Facades\Route;

// The backend is an API; the React app (Frontend/) is the user interface.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => url('/api/v1')]));
