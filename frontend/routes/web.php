<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SensorDataController;
use App\Http\Controllers\FrameController;

Route::get('/', [SensorDataController::class, 'index'])->name('home');
Route::get('/sensores', [SensorDataController::class, 'index'])->name('sensores.index');
Route::get('/sensores/estadisticas', [SensorDataController::class, 'estadisticas'])->name('sensores.estadisticas');
Route::get('/sensores/cargar-vista-estadisticas/{vista}', [SensorDataController::class, 'vistaEstadisticas'])->name('sensores.cargar-vista-estadisticas');
Route::get('/sensores/cargar-vista-control/{vista}', [SensorDataController::class, 'vistaControl'])->name('sensores.cargar-vista-control');
Route::get('/sensores/control', [SensorDataController::class, 'misionControl'])->name('sensores.control');

Route::get('/api/sensores/latest-frame', [FrameController::class, 'latest'])->name('api.sensores.latest-frame');
Route::get('/api/sensores/latest-frame/image', [FrameController::class, 'latestImage'])
    ->name('api.sensores.latest-frame.image');

Route::get('/sensores/ultimos', [SensorDataController::class, 'ultimos'])->name('sensores.ultimos');
Route::get('/sensores/ultimos-stream', [SensorDataController::class, 'ultimosStream'])->name('sensores.ultimosStream');
Route::get('/sensores/live', [SensorDataController::class, 'live'])->name('sensores.live');
Route::post('/sensores/probar-conexion', [SensorDataController::class, 'probarConexion'])
    ->middleware('throttle:3,1')
    ->name('sensores.probar-conexion');
Route::get('/sensores/ventana', [SensorDataController::class, 'ventana']);
Route::post('/sensores/calibrar-aceleracion', [SensorDataController::class, 'calibrarAceleracion'])
	->name('sensores.calibrar-aceleracion');

Route::get('/sensores/riesgo-bioma', [SensorDataController::class, 'riesgoBioma']);
