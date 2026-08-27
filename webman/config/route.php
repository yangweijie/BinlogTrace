<?php
use Webman\Route;

// Route::get('/', [\App\Controller\AgentController::class, 'index']);
Route::post('/connect', [\App\Controller\AgentController::class, 'connect']);
Route::post('/query', [\App\Controller\AgentController::class, 'query']);
Route::post('/binlog-dump', [\App\Controller\AgentController::class, 'startDump']);
Route::post('/dump', [\App\Controller\AgentController::class, 'startDump']);
Route::post('/close', [\App\Controller\AgentController::class, 'close']);
Route::get('/ping', [\App\Controller\AgentController::class, 'index']);
Route::post('/ping', [\App\Controller\AgentController::class, 'index']);
