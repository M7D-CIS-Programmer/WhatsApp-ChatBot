<?php
use Illuminate\Http\Request;
use App\Http\Controllers\WhatsAppController;

Route::post('/whatsapp-webhook', [WhatsAppController::class, 'handleWebhook']);

// Route::post('/status', function (Request $request) {
//     // Log الحالة، مثلاً:
//     \Log::info('Message Status: ' . $request->input('MessageStatus') . ' for SID: ' . $request->input('MessageSid'));
//     return response('OK'); // أو TwiML بسيط
// });

