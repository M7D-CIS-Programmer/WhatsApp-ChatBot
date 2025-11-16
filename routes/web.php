<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Twilio\Rest\Client;
use App\Http\Controllers\WhatsAppController;


// Route::post('/whatsapp/webhook', function (Request $request) {
//     $from = $request->input('From');
//     $body = strtolower(trim($request->input('Body')));

//     $client = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));

//     // الأسئلة الجاهزة مع الردود
//     $responses = [
//         'السلام عليكم' => "مرحبًا بك في شركة التوصيل!\nاختر أحد المواضيع التالية:\n1️⃣ موقعنا\n2️⃣ الراتب الشهري\n3️⃣ هل يوجد overtime",
//         'موقعنا' => '📍 موقعنا في عمان.',
//         'الراتب الشهري' => '💰 الراتب يبدأ من 400 دينار.',
//         'هل يوجد overtime' => '⏰ نعم، يوجد وقت إضافي مدفوع الأجر.'
//     ];

//     // الرد الافتراضي
//     $reply = $responses[$body] ?? 'عذرًا، لم أفهم رسالتك. حاول اختيار أحد الخيارات المحددة.';

//     $client->messages->create(
//         $from,
//         [
//             'from' => env('TWILIO_WHATSAPP_FROM'),
//             'body' => $reply
//         ]
//     );

//     return response('Message sent');
// });

Route::get('/', function () {
    return response()->json(['message' => 'WhatsApp Bot API is running']);
});
