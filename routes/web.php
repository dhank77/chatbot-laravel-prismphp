<?php

use App\Http\Controllers\AgentChatbotController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('/chatbot', [ChatbotController::class, 'index']);
Route::post('/chatbot', [ChatbotController::class, 'chat']);
Route::post('/chatbot/agent', [ChatbotController::class, 'agentChat']);

// Route untuk agent-based chatbot (alternatif)
Route::get('/chatbot/agent', [AgentChatbotController::class, 'index']);
Route::post('/chatbot/agent', [AgentChatbotController::class, 'chat']);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
