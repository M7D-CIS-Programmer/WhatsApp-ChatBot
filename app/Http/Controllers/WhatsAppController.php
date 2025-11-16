<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\TwiML\MessagingResponse;
use Twilio\Rest\Client;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WhatsAppController extends Controller
{
    /**
     * معالجة webhook من Twilio WhatsApp
     */
    public function handleWebhook(Request $request)
    {
        // Log الطلب الأولي للتشخيص
        Log::info('WhatsApp Webhook Hit - Test Start', [
            'request_data' => $request->all(),
            'headers' => $request->headers->all()
        ]);

        // جمع البيانات من الطلب
        $from = $request->input('From'); // رقم المستخدم
        $bodyRaw = $request->input('Body') ?? '';
        $trimmedBody = trim((string)$bodyRaw);
        $body = $trimmedBody !== ''
            ? (function_exists('mb_strtolower') ? mb_strtolower($trimmedBody, 'UTF-8') : strtolower($trimmedBody))
            : '';
        $buttonPayload = $request->input('ButtonPayload') ?? 
            $request->input('Payload') ?? 
            $request->input('ButtonText') ?? '';
        
        // دعم الرد على الرسائل (Message Context)
        $messageSid = $request->input('MessageSid'); // معرف الرسالة للرد عليها
        $repliedToSid = $request->input('ReferredBy'); // معرف الرسالة التي تم الرد عليها

        // إنشاء TwiML Response (مطلوب دائماً للـ webhook acknowledgment)
        $response = new MessagingResponse();

        // التحقق من وجود رقم المرسل (From) - هذا مهم جداً لتجنب الأخطاء
        if (empty($from)) {
            Log::warning('WhatsApp Webhook: From number is missing', [
                'request_data' => $request->all()
            ]);
            // إرجاع TwiML response حتى لو لا يوجد From (لتجنب retries)
            $response->message('يرجى التأكد من إرسال الرسالة من رقم WhatsApp صالح.');
            Log::info('Test: Empty From response sent');
            return $this->xmlResponse($response);
        }

        // التحقق من إعدادات Twilio
        $twilioSid = env('TWILIO_SID');
        $twilioToken = env('TWILIO_AUTH_TOKEN');
        $twilioFrom = env('TWILIO_WHATSAPP_FROM');

        if (empty($twilioSid) || empty($twilioToken) || empty($twilioFrom)) {
            Log::error('WhatsApp Webhook: Twilio credentials missing', [
                'has_sid' => !empty($twilioSid),
                'has_token' => !empty($twilioToken),
                'has_from' => !empty($twilioFrom)
            ]);
            $response->message('حدث خطأ في الإعدادات. يرجى التحقق من إعدادات Twilio.');
            Log::info('Test: Credentials error response sent');
            return $this->xmlResponse($response);
        }

        // Log الطلب (في بيئة التطوير فقط)
        if (config('app.debug')) {
            Log::info('WhatsApp Webhook Received', [
                'from' => $from,
                'body' => $body,
                'button_payload' => $buttonPayload
            ]);
        }

        // إرسال رد اختباري بسيط للتأكد من الـ webhook (احذف ده بعد الاختبار)
        // if (config('app.debug')) {
        //     $response->message('Test: Webhook شغال! رسالتك: ' . $body);
        //     Log::info('Test response sent - Debug mode');
        // }

        // تحديد ما إذا كان المستخدم يريد القائمة الرئيسية
        $hasButtonClick = !empty($buttonPayload);
        $userState = $this->getUserState($from);
        // تحديد ما إذا كانت هذه أول رسالة من المستخدم (لا توجد حالة محفوظة بعد)
        $stateCacheKey = 'whatsapp_user_state_' . md5($from);
        $hasExistingState = Cache::has($stateCacheKey);

        // أول رسالة دائماً تعيد القائمة بدون النظر للمحتوى
        $isFirstMessage = (!$hasExistingState);

        // منطق الانتظار: إذا كان المستخدم في وضع الانتظار، لا نرد إلا إذا أرسل 0 أو ٠ أو قائمة
        if ($this->isOnHold($from)) {
            if (in_array($body, ['0', '٠', 'قائمة'], true)) {
                // كسر الانتظار والعودة للقائمة
                $this->clearHold($from);
                $this->setUserState($from, 'main');
                $this->sendInteractiveMenu($from, $twilioSid, $twilioToken, $twilioFrom, $response);
            } else {
                // لا نرد أثناء الانتظار
                return $this->xmlResponse($response);
            }
        }

        // معالجة الطلبات
        try {
            if ($isFirstMessage) {
                // إرسال القائمة الرئيسية
                $this->setUserState($from, 'main');
                $this->sendInteractiveMenu($from, $twilioSid, $twilioToken, $twilioFrom, $response);
            } else {
                // معالجة اختيار المستخدم
                $userChoice = $hasButtonClick ? (string)$buttonPayload : (string)$body;
                
                if (!empty(trim($userChoice))) {
                    // إذا كان في حالة halt ويكتب شيء غير 0 أو ٠ أو قائمة، فعّل الانتظار 6 ساعات وتوقف عن الرد
                    if ($userState === 'halt' && !in_array($userChoice, ['0', '٠', 'قائمة'], true)) {
                        $this->setHold($from, now()->addHours(6));
                        return $this->xmlResponse($response);
                    }

                    $this->handleUserChoice($from, $userChoice, $userState, $twilioSid, $twilioToken, $twilioFrom, $response);
                } else {
                    // إذا كانت الرسالة فارغة، نرسل القائمة الرئيسية
                    $this->setUserState($from, 'main');
                    $this->sendInteractiveMenu($from, $twilioSid, $twilioToken, $twilioFrom, $response);
                }
            }
        } catch (Exception $e) {
            // تسجيل الخطأ بالتفصيل
            Log::error('WhatsApp Webhook Error', [
                'error' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'from' => $from,
                'body' => $body,
                'payload' => $buttonPayload
            ]);

            // محاولة إرسال رسالة خطأ للمستخدم
            try {
                $errorMessage = 'عذراً، حدث خطأ في معالجة رسالتك. يرجى المحاولة مرة أخرى.';
                $response->message($errorMessage);
                Log::info('Error fallback response sent');
            } catch (Exception $responseError) {
                Log::error('Failed to send error message response', [
                    'error' => $responseError->getMessage()
                ]);
            }
        }

        // إرجاع TwiML XML response دائماً (مع log للتأكيد)
        Log::info('WhatsApp Webhook - Response sent', [
            'from' => $from,
            'body' => $body,
            'response_body' => str_replace("\n", '', (string) $response)  // للتشخيص
        ]);
        return $this->xmlResponse($response);
    }

    /**
     * إرسال القائمة الرئيسية مع أزرار تفاعلية وتصميم جميل
     */
    private function sendInteractiveMenu(string $to, string $sid, string $token, string $from, MessagingResponse $response): void
    {
        try {
            // نقسم القائمة إلى رسالتين: مقدمة + الخيارات
            $intro  = "🌿 أهلاً وسهلاً في Greentop Business Solutions" . PHP_EOL;
            $intro .= "الشركة المتخصصة في الخدمات اللوجستية وتوظيف الكباتن على تطبيقات طلبات، كريم، وأشيائي 🚗📦" . PHP_EOL . PHP_EOL;
            $intro .= "يسعدنا تواصلك معنا!" . PHP_EOL . PHP_EOL;
            $intro .= "اختر من القائمة أدناه حتى نقدر نساعدك بشكل أسرع 👇";

            $options  = "1️⃣ التوظيف ككابتن (طلبات / كريم / أشيائي)" . PHP_EOL;
            $options .= "2️⃣ خدمات النقل واللوجستيك للشركات والمتاجر" . PHP_EOL;
            $options .= "3️⃣ شراكات وتعاون تجاري" . PHP_EOL;
            $options .= "4️⃣ الدعم الفني والتواصل مع موظف" . PHP_EOL;
            $options .= "5️⃣ معلومات عامة (العنوان، الموقع، ساعات العمل)";

            // التحقق من صحة البيانات قبل الإرسال
            if (empty($to) || empty($intro) || empty($options) || empty($from)) {
                Log::error('Invalid parameters for sendInteractiveMenu', [
                    'to' => $to,
                    'from' => $from,
                    'intro_length' => strlen($intro ?? ''),
                    'options_length' => strlen($options ?? '')
                ]);
                throw new Exception('Invalid parameters for sending menu');
            }

            $twilio = new Client($sid, $token);
            
            // محاولة استخدام Content Template إذا كان متوفراً (مع أزرار تفاعلية) – استخدم SID محدد
            $contentSid = env('TWILIO_GREETING_TEMPLATE_SID');  // غيّر للـ SID المحدد من اللي عندك (مثل HX8204...)
            
            if (!empty($contentSid)) {
                try {
                    // إرسال Interactive Message عبر REST API (بعد TwiML Response)
                    $this->sendTemplateMessage($to, $contentSid, [], $sid, $token, $from);
                    Log::info('Interactive menu sent via Content Template', ['to' => $to, 'sid' => $contentSid]);
                    // نرسل رسالتين توضيحيتين أيضاً عبر TwiML للرد السريع
                    $response->message($intro);
                    $response->message($options);
                } catch (Exception $templateException) {
                    Log::warning('Content Template failed, using formatted text', [
                        'error' => $templateException->getMessage()
                    ]);
                    // لا return هنا – استمر للـ TwiML
                }
            } else {
                Log::info('No Content SID found, using TwiML fallback');
            }

            // إرسال الرسالتين المنسقتين عبر TwiML Response دائماً (لا return هنا لضمان الـ response)
            $response->message($intro);
            $response->message($options);
            
            Log::info('Interactive menu sent successfully via TwiML fallback', ['to' => $to]);

        } catch (Exception $e) {
            Log::error('Failed to send interactive menu', [
                'error' => $e->getMessage(),
                'to' => $to,
                'trace' => $e->getTraceAsString()
            ]);
            $response->message('عذراً، حدث خطأ في إرسال القائمة. أعد المحاولة.');
            return;
        }
        // لا return هنا – دع الـ handleWebhook يرجع الـ response
    }

    /**
     * إرسال رسالة باستخدام Content Template محدد (جديدة للأمان)
     */
    private function sendTemplateMessage(string $to, string $templateSid, array $parameters = [], string $sid, string $token, string $from): void
    {
        if (empty($templateSid) || empty($to) || empty($from)) {
            Log::error('Invalid params for sendTemplateMessage', ['sid' => $templateSid ?? 'null']);
            return;
        }

        try {
            $twilio = new Client($sid, $token);
            $twilio->messages->create(
                $to,
                [
                    'from' => $from,
                    'contentSid' => $templateSid,
                    'contentVariables' => json_encode($parameters),  // متغيرات لو عايز
                ]
            );
            Log::info('Template sent successfully', ['template' => $templateSid, 'to' => $to]);
        } catch (Exception $e) {
            Log::error('Failed to send template', ['error' => $e->getMessage(), 'template' => $templateSid]);
            throw $e;  // ارفع الخطأ للـ fallback
        }
    }

    /**
     * إرسال رسالة عبر Twilio مع دعم الرد على الرسائل
     */
    private function sendMessage(string $to, string $messageBody, string $sid, string $token, string $from, MessagingResponse $response, ?string $repliedToMessageSid = null): void
    {
        // التحقق من صحة البيانات قبل الإرسال (مهم جداً)
        if (empty($to) || empty($messageBody) || empty($from)) {
            Log::error('Invalid parameters for sendMessage', [
                'to' => $to,
                'from' => $from,
                'body_length' => strlen($messageBody ?? '')
            ]);
            $response->message('حدث خطأ في إرسال الرسالة. يرجى المحاولة مرة أخرى.');
            return;
        }

        try {
            // إضافة الرسالة في TwiML للرد الفوري
            $message = $response->message($messageBody);
            
            // إذا كان هناك رد على رسالة محددة، يمكن إضافة Context
            // ملاحظة: TwiML لا يدعم Message Context مباشرة، يجب إرساله عبر REST API
            if (!empty($repliedToMessageSid)) {
                // يمكن حفظ MessageSid للرد لاحقاً
                Log::info('Message reply context', [
                    'replied_to' => $repliedToMessageSid,
                    'to' => $to
                ]);
            }

            Log::info('Message sent successfully via TwiML', [
                'to' => $to,
                'message_length' => strlen($messageBody),
                'has_reply_context' => !empty($repliedToMessageSid)
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send message via Twilio', [
                'error' => $e->getMessage(),
                'to' => $to,
                'body_preview' => substr($messageBody, 0, 50)
            ]);
            $response->message('عذراً، حدث خطأ في إرسال الرسالة.');
            return;
        }
    }

    /**
     * معالجة اختيار المستخدم
     */
    private function handleUserChoice(string $from, string $choice, string $currentState, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        // تنظيف الخيار
        $choiceRaw = trim((string)$choice);
        $choice = $choiceRaw !== ''
            ? (function_exists('mb_strtolower') ? mb_strtolower($choiceRaw, 'UTF-8') : strtolower($choiceRaw))
            : '';
        
        // إذا كان الخيار فارغاً، نعيد القائمة الرئيسية
        if (empty($choice)) {
            $this->setUserState($from, 'main');
            $this->sendInteractiveMenu($from, $sid, $token, $twilioFrom, $response);
            return;
        }

        // الرجوع للقائمة الرئيسية فقط عبر 0 أو ٠ أو قائمة
        if (in_array($choice, ['0', '٠', 'قائمة'], true)) {
            $this->setUserState($from, 'main');
            $this->sendInteractiveMenu($from, $sid, $token, $twilioFrom, $response);
            return;
        }

        // حسب الحالة الحالية
        switch ($currentState) {
            case 'main':
                $this->handleMainMenuChoice($from, $choice, $sid, $token, $twilioFrom, $response);
                break;

            case 'employment_select':
                $this->handleEmploymentSelection($from, $choice, $sid, $token, $twilioFrom, $response);
                break;

            case 'halt':
                break;

            default:
                // حالة غير معروفة - العودة للقائمة الرئيسية
                $this->setUserState($from, 'main');
                $this->sendInteractiveMenu($from, $sid, $token, $twilioFrom, $response);
                break;
        }
    }

    /**
     * معالجة الاختيار من القائمة الرئيسية مع دعم الأزرار التفاعلية
     */
    private function handleMainMenuChoice(string $from, string $choice, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        // معالجة Button Payloads (من الأزرار التفاعلية)
        $buttonMapping = [
            'employment' => '1',
            'logistics' => '2',
            'partnerships' => '3',
            'support' => '4',
            'info' => '5',
        ];

        // إذا كان الخيار من زر تفاعلي، نحوله إلى رقم
        if (isset($buttonMapping[$choice])) {
            $choice = $buttonMapping[$choice];
        }

        switch ($choice) {
            case '1':
            case 'التوظيف':
            case 'كابتن':
                $this->sendEmploymentInfo($from, $sid, $token, $twilioFrom, $response);
                break;

            case '2':
            case 'الخدمات':
            case 'اللوجستيك':
                $this->sendLogisticsServices($from, $sid, $token, $twilioFrom, $response);
                break;

            case '3':
            case 'شراكات':
            case 'تعاون':
                $this->sendPartnerships($from, $sid, $token, $twilioFrom, $response);
                break;

            case '4':
            case 'الدعم':
            case 'موظف':
                $this->sendSupport($from, $sid, $token, $twilioFrom, $response);
                break;

            case '5':
            case 'معلومات':
            case 'العامة':
                $this->sendGeneralInfo($from, $sid, $token, $twilioFrom, $response);
                break;

            default:
                $this->sendInvalidChoicePrompt($from, $sid, $token, $twilioFrom, $response);
                break;
        }
    }

    private function sendEmploymentInfo(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $formUrl = 'https://forms.gle/HEohmw4YUdR3KRSB8';
        $message = "💪 ممتاز! خطوة رائعة نحو فرصة جديدة 🚗" . PHP_EOL;
        $message .= "نحن نقوم حالياً بتوظيف كباتن على تطبيقات:" . PHP_EOL;
        $message .= "طلبات، كريم، وأشيائي." . PHP_EOL . PHP_EOL;
        $message .= "حتى نكمل معك عملية التسجيل، الرجاء إرسال المعلومات التالية:" . PHP_EOL . PHP_EOL;
        $message .= "🔹 الاسم الكامل" . PHP_EOL;
        $message .= "🔹 رقم الهاتف" . PHP_EOL;
        $message .= "🔹 المدينة" . PHP_EOL;
        $message .= "🔹 التطبيق الذي ترغب بالعمل عليه (طلبات / كريم / أشيائي):" . PHP_EOL . PHP_EOL;
        $message .= "    1️⃣ لمعرفة تسعيرة طلبات" . PHP_EOL;
        $message .= "    2️⃣ لمعرفة تسعيرة كريم" . PHP_EOL;
        $message .= "    3️⃣ لمعرفة تسعيرة أشيائي" . PHP_EOL;
        $message .= "(اكتب رقم الخيار أو اسم التطبيق كما هو)" . PHP_EOL . PHP_EOL;
        $message .= "أو يمكنك تعبئة نموذج التسجيل مباشرة عبر الرابط التالي:" . PHP_EOL;
        $message .= "🌐 " . $formUrl . PHP_EOL . PHP_EOL;
        $message .= "بعد إرسال التفاصيل، سيتواصل معك أحد موظفينا قريبًا بإذن الله ☎️" . PHP_EOL . PHP_EOL;
        $message .= "شكراً لتواصلك مع Greentop Business Solutions 🌱" . PHP_EOL;
        $message .= "نسعد دائماً بخدمتك وتوفير أفضل الحلول لك 🚀" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        $this->setUserState($to, 'employment_select');
    }

    private function sendLogisticsServices(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $message = "📦 شكراً لاهتمامك بخدماتنا اللوجستية!" . PHP_EOL;
        $message .= "نحن نوفر حلولاً احترافية في النقل والتوصيل وإدارة الأسطول للشركات والمتاجر." . PHP_EOL . PHP_EOL;
        $message .= "اختر نوع الخدمة المطلوبة:" . PHP_EOL;
        $message .= "🔹 توصيل طلبات داخل المدينة" . PHP_EOL;
        $message .= "🔹 نقل بضائع وشحن بين المحافظات" . PHP_EOL;
        $message .= "🔹 إدارة أسطول وتوصيل لشركات ومتاجر" . PHP_EOL . PHP_EOL;
        $message .= "اكتب لنا احتياجك بالتفصيل وسيتواصل معك فريقنا لتقديم عرض مناسب 💼" . PHP_EOL . PHP_EOL;
        $message .= "شكراً لتواصلك مع Greentop Business Solutions 🌱" . PHP_EOL;
        $message .= "نسعد دائماً بخدمتك وتوفير أفضل الحلول لك 🚀" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        $this->setUserState($to, 'halt');
    }

    private function handleEmploymentSelection(string $from, string $choice, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $map = [
            '1' => 'طلبات',
            '2' => 'كريم',
            '3' => 'أشيائي',
            'طلبات' => 'طلبات',
            'talabat' => 'طلبات',
            'Talabat' => 'طلبات',
            'كريم' => 'كريم',
            'careem' => 'كريم',
            'Careem' => 'كريم',
            'أشيائي' => 'أشيائي',
            'اشيائي' => 'أشيائي',
            'ashiay' => 'أشيائي',
            'Ashiay' => 'أشيائي',
            'ashyaei' => 'أشيائي',
            'Ashyaei' => 'أشيائي',
            'asheay' => 'أشيائي',
            'Asheay' => 'أشيائي',
        ];

        $normalized = $choice;
        if (isset($map[$normalized])) {
            $app = $map[$normalized];
            $this->sendEmploymentRegistrationWithImage($from, $app, $sid, $token, $twilioFrom, $response);
            $this->setUserState($from, 'halt');
            return;
        }

        $this->sendInvalidChoicePrompt($from, $sid, $token, $twilioFrom, $response);
    }

    private function sendInvalidChoicePrompt(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $message = "اكتب استفسارك وسيتم الرد عليك من قبل موظفينا في أسرع وقت، أو قم بكتابة \"قائمة\" للعودة إلى القائمة الرئيسية.";
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        $this->setUserState($to, 'halt');
    }

    private function employmentImageUrl(string $app): ?string
    {
        $normalized = trim($app);
        $variantsMap = [
            'طلبات' => ['طلبات', 'talabat', 'Talabat'],
            'كريم' => ['كريم', 'careem', 'Careem'],
            'أشيائي' => ['أشيائي', 'اشيائي', 'ashiay', 'ashyaei', 'Ashyaei', 'Ashiay'],
        ];

        $keys = isset($variantsMap[$normalized]) ? $variantsMap[$normalized] : [$normalized];
        $extensions = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];

        foreach ($keys as $name) {
            foreach ($extensions as $ext) {
                $filename = $name . '.' . $ext;
                $relPath = 'images/' . $filename;
                $diskPath = public_path($relPath);
                if (file_exists($diskPath)) {
                    $publicUrl = asset('images') . '/' . rawurlencode($filename);
                    Log::info('Employment image found', ['app' => $app, 'file' => $relPath, 'url' => $publicUrl]);
                    return $publicUrl;
                }
            }
        }

        // محاولة استخدام صورة افتراضية إن وجدت
        foreach (['default.jpg', 'default.jpeg', 'default.png'] as $fallback) {
            $relPath = 'images/' . $fallback;
            if (file_exists(public_path($relPath))) {
                $publicUrl = asset('images') . '/' . rawurlencode($fallback);
                Log::warning('Employment image not found, using fallback', ['app' => $app, 'file' => $relPath, 'url' => $publicUrl]);
                return $publicUrl;
            }
        }

        Log::warning('Employment image not found and no fallback available', ['app' => $app]);
        return null;
    }

    private function sendEmploymentRegistrationWithImage(string $to, string $app, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $formUrl = 'https://forms.gle/HEohmw4YUdR3KRSB8';
        $imageUrl = $this->employmentImageUrl($app);
        // توحيد اسم التطبيق للعرض في الرسالة
        $display = trim($app);
        if (in_array($display, [' Asheay', 'ashyaei', 'Ashyaei', 'ashiay', 'Ashiay', 'اشيائي'])) {
            $display = 'أشيائي';
        } elseif (in_array($display, ['talabat', 'Talabat'])) {
            $display = 'طلبات';
        } elseif (in_array($display, ['careem', 'Careem'])) {
            $display = 'كريم';
        }

        $caption  = "📊 التسعيرة الحالية لتطبيق " . $display . PHP_EOL;
        $caption .= "قد تختلف الأسعار حسب المدينة وأوقات الذروة. للمزيد من التفاصيل تواصل معنا." . PHP_EOL . PHP_EOL;
        $caption .= "رابط التسجيل: " . $formUrl . PHP_EOL . PHP_EOL;
        $caption .= "إذا عندك أي استفسار اترك رسالة، وسيتم التواصل معك قريباً" . PHP_EOL . PHP_EOL;
        $caption .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        if ($imageUrl) {
            $message = $response->message($caption);
            $message->media($imageUrl);
            Log::info('Employment image sent', ['url' => $imageUrl]);
            return;
        }
        $this->sendMessage($to, $caption, $sid, $token, $twilioFrom, $response);
    }

    private function sendPartnerships(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $message = "يسعدنا اهتمامك بالتعاون معنا! 🌟" . PHP_EOL . PHP_EOL;
        $message .= "الرجاء إرسال نبذة قصيرة عن شركتك أو نوع الشراكة المطلوبة،" . PHP_EOL;
        $message .= "وفريق إدارة الأعمال لدينا سيتواصل معك خلال 24 ساعة لمناقشة التفاصيل." . PHP_EOL . PHP_EOL;
        $message .= "شكراً لتواصلك مع Greentop Business Solutions 🌱" . PHP_EOL;
        $message .= "نسعد دائماً بخدمتك وتوفير أفضل الحلول لك 🚀" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        $this->setUserState($to, 'halt');
    }

    private function sendSupport(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $message = "تمام 🙌" . PHP_EOL;
        $message .= "جاري تحويلك الآن إلى أحد موظفينا المختصين 💬" . PHP_EOL;
        $message .= "سيتم التواصل معك في أسرع وقت، شكراً لك." . PHP_EOL . PHP_EOL;
        $message .= "شكراً لتواصلك مع Greentop Business Solutions 🌱" . PHP_EOL;
        $message .= "نسعد دائماً بخدمتك وتوفير أفضل الحلول لك 🚀" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        $this->setUserState($to, 'halt');
    }

    private function sendGeneralInfo(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $website = 'https://web.facebook.com/profile.php?id=61564140600908&__tn__=%3C';
        $phone1 = '+962781616600';
        $phone2 = '+962799335323';
        $email = 'greentopbs@gmail.com';
        $message = "🏢 معلومات Greentop Business Solutions" . PHP_EOL . PHP_EOL;
        $message .= "📍 العنوان: عمّان – الأردن - شارع الجامعة -بجانب سنترو للمفروشات - مجمع الاقصى رقم 256 ط2 مكتب 203" . PHP_EOL;
        $message .= "⏰ ساعات العمل:" . PHP_EOL;
        $message .= "السبت إلى الخميس: 10:00 ص – 5:00 م" . PHP_EOL;
        $message .= "الجمعة : عطلة رسمية" . PHP_EOL . PHP_EOL;
        $message .= "🌐 موقعنا الإلكتروني: " . $website . PHP_EOL . PHP_EOL;
        $message .= "📞 رقم التواصل: " . $phone1 . " - " . $phone2 . PHP_EOL;
        $message .= "📧 الإيميل: " . $email . PHP_EOL . PHP_EOL;
        $message .= "يسعدنا خدمتك في أي وقت 🌿" . PHP_EOL . PHP_EOL;
        $message .= "شكراً لتواصلك مع Greentop Business Solutions 🌱" . PHP_EOL;
        $message .= "نسعد دائماً بخدمتك وتوفير أفضل الحلول لك 🚀" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        $this->setUserState($to, 'halt');
    }

    /**
     * إرسال رابط الفورم للتسجيلات (طلبات، كريم، اشيائي) بتصميم جميل
     */
    private function sendRegistrationForm(string $to, string $type, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        // الحصول على رابط الفورم من متغير البيئة أو استخدام رابط افتراضي
        $formUrl = env('REGISTRATION_FORM_URL', 'https://forms.gle/HEohmw4YUdR3KRSB8');
        
        // تحديد أيقونة حسب النوع
        $icon = '📋'; // افتراضي
        switch($type) {
            case 'طلبات':
                $icon = '📦';
                break;
            case 'كريم':
                $icon = '🧴';
                break;
            case 'اشيائي':
                $icon = '📝';
                break;
        }
        
        $message = "━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        $message .= $icon . " *تسجيل " . $type . "*" . PHP_EOL;
        $message .= "━━━━━━━━━━━━━━━━━━" . PHP_EOL . PHP_EOL;
        $message .= "شكراً لك لاختيارك " . $type . "!" . PHP_EOL . PHP_EOL;
        $message .= "👉 يرجى ملء الفورم التالي:" . PHP_EOL . PHP_EOL;
        $message .= "🔗 " . $formUrl . PHP_EOL . PHP_EOL;
        $message .= "━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        $message .= "✅ بعد إكمال الفورم، سيتم التواصل معك قريباً" . PHP_EOL . PHP_EOL;
        $message .= "💬 للعودة للقائمة الرئيسية، أرسل \"0\" أو \"قائمة\"" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        // بعد إرسال نموذج التسجيل، ندخل في وضع الانتظار
        $this->setUserState($to, 'halt');
    }

    /**
     * إرسال رسالة الشكاوى والاستفسارات بتصميم جميل مع أزرار تفاعلية
     */
    private function sendComplaintsAndInquiries(string $to, string $sid, string $token, string $twilioFrom, MessagingResponse $response): void
    {
        $phone1 = "0781616600";
        $phone2 = "0799335323";
        
        $message = "━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        $message .= "📞 *شكاوى وإستفسارات*" . PHP_EOL;
        $message .= "━━━━━━━━━━━━━━━━━━" . PHP_EOL . PHP_EOL;
        $message .= "👋 أهلاً بك في قسم الشكاوى والإستفسارات!" . PHP_EOL . PHP_EOL;
        $message .= "📱 للإستفسار وتقديم الشكاوي، يرجى الاتصال على أحد الأرقام التالية:" . PHP_EOL . PHP_EOL;
        $message .= "━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        $message .= "📞 *الرقم الأول:*" . PHP_EOL;
        $message .= "   " . $phone1 . PHP_EOL . PHP_EOL;
        $message .= "📞 *الرقم الثاني:*" . PHP_EOL;
        $message .= "   " . $phone2 . PHP_EOL . PHP_EOL;
        $message .= "━━━━━━━━━━━━━━━━━━" . PHP_EOL;
        $message .= "💡 *يمكنك الضغط على الرقم للاتصال مباشرة*" . PHP_EOL . PHP_EOL;
        $message .= "💬 للعودة للقائمة الرئيسية، أرسل \"0\" أو \"قائمة\"" . PHP_EOL . PHP_EOL;
        $message .= "في حال تريد العودة الى القائمة الرئيسية اضغط \"0\" او اكتب \"قائمة\"";
        
        $this->sendMessage($to, $message, $sid, $token, $twilioFrom, $response);
        // بعد إرسال معلومات الشكاوى، ندخل في وضع الانتظار
        $this->setUserState($to, 'halt');
    }

    /**
     * الحصول على حالة المستخدم
     */
    private function getUserState(string $phoneNumber): string
    {
        $key = 'whatsapp_user_state_' . md5($phoneNumber);
        return Cache::get($key, 'main');
    }

    /**
     * حفظ حالة المستخدم
     */
    private function setUserState(string $phoneNumber, string $state): void
    {
        $key = 'whatsapp_user_state_' . md5($phoneNumber);
        Cache::put($key, $state, now()->addHours(24));
    }

    /**
     * تعيين وضع الانتظار للمستخدم حتى وقت محدد
     */
    private function setHold(string $phoneNumber, \DateTimeInterface $until): void
    {
        $key = 'whatsapp_user_hold_until_' . md5($phoneNumber);
        // نخزن وقت الانتهاء كقيمة، ونستخدم نفس الوقت كـ expiration للكي
        Cache::put($key, $until, $until);
    }

    /**
     * التحقق مما إذا كان المستخدم في وضع الانتظار
     */
    private function isOnHold(string $phoneNumber): bool
    {
        $key = 'whatsapp_user_hold_until_' . md5($phoneNumber);
        $until = Cache::get($key);
        if (empty($until)) {
            return false;
        }
        // إذا كان الآن قبل وقت الانتهاء، فالمستخدم ما زال في وضع الانتظار
        return now()->lessThan($until);
    }

    /**
     * مسح وضع الانتظار للمستخدم
     */
    private function clearHold(string $phoneNumber): void
    {
        $key = 'whatsapp_user_hold_until_' . md5($phoneNumber);
        Cache::forget($key);
    }

    /**
     * إرجاع TwiML response مع الـ headers الصحيحة
     */
    private function xmlResponse(MessagingResponse $response)
    {
        Log::info('XML Response generated', ['body' => str_replace("\n", '', (string) $response)]);
        return response($response, 200)
            ->header('Content-Type', 'text/xml; charset=utf-8');
    }

}