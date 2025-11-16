# دليل إعداد WhatsApp Bot مع أزرار تفاعلية

## 📋 ملخص

هذا المشروع يستخدم Twilio وLaravel لإرسال رسائل WhatsApp مع دعم الأزرار التفاعلية.

## 🔧 الإعدادات الحالية

### 1. متغيرات البيئة (.env)

```env
TWILIO_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_WHATSAPP_FROM=whatsapp:+1234567890
TWILIO_CONTENT_SID=HX... (اختياري - للأزرار التفاعلية)
```

### 2. طريقة العمل الحالية

**الحالة الافتراضية**: يتم إرسال رسالة نصية منظمة بشكل احترافي تعمل فوراً دون إعداد إضافي.

**إذا كان `TWILIO_CONTENT_SID` موجوداً**: سيتم محاولة إرسال Content Template مع أزرار تفاعلية.

## 🎯 كيفية إظهار الأزرار التفاعلية الحقيقية

لإظهار أزرار تفاعلية فعلية في WhatsApp (وليس فقط نص منظم)، يجب إنشاء **Content Template** في Twilio Console:

### الخطوات:

1. **تسجيل الدخول إلى Twilio Console**
   - اذهب إلى [console.twilio.com](https://console.twilio.com)
   - سجل الدخول بحسابك

2. **الانتقال إلى Content Templates**
   - من القائمة الجانبية: **Messaging** → **Content Templates**
   - أو اذهب مباشرة: `https://console.twilio.com/us1/develop/sms/content-templates`

3. **إنشاء Content Template جديد**
   - اضغط على **Create New Template**
   - اختر **WhatsApp** كقناة الرسائل
   - أدخل معلومات القالب:
     - **Name**: `interactive_menu` (أو أي اسم تفضله)
     - **Language**: `ar` (العربية)
     - **Category**: `MARKETING` أو `UTILITY`

4. **إضافة محتوى الرسالة**
   - **Header**: (اختياري) يمكن تركه فارغ أو إضافة نص
   - **Body**: 
     ```
     🌐 أهلاً بك! 👋

     اختر موضوعاً من القائمة التالية:
     ```
   - **Footer**: (اختياري) يمكن إضافة نص في الأسفل

5. **إضافة أزرار تفاعلية (الأهم!)**
   - في قسم **Buttons**، اختر **Quick Reply Buttons**
   - أضف 3 أزرار:
     - **Button 1**:
       - **Button Text**: `☀️ الطقس`
       - **Button Payload**: `weather`
     - **Button 2**:
       - **Button Text**: `⚽ الرياضة`
       - **Button Payload**: `sports`
     - **Button 3**:
       - **Button Text**: `📱 التكنولوجيا`
       - **Button Payload**: `tech`

6. **تقديم القالب للموافقة**
   - بعد إنشاء القالب، يجب تقديمه للموافقة من WhatsApp
   - انتظر الموافقة (قد يستغرق عدة ساعات أو أيام)

7. **الحصول على Content SID**
   - بعد الموافقة، انسخ الـ **Content SID** (يبدأ بـ `HX...`)
   - أضفه في `.env`:
     ```env
     TWILIO_CONTENT_SID=HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
     ```

8. **اختبار**
   - أرسل "hi" إلى Bot الخاص بك
   - يجب أن تظهر الأزرار التفاعلية تحت الرسالة!

## 📝 ملاحظات مهمة

### ⚠️ قيود WhatsApp Content Templates:
- يجب الموافقة على القوالب من WhatsApp قبل استخدامها
- القوالب MARKETING لها قيود إضافية (24 ساعة window)
- القوالب UTILITY يمكن إرسالها في أي وقت

### ✅ المميزات الحالية:
- رسالة نصية منظمة تعمل فوراً
- دعم اختيارات متعددة (weather/طقس/1)
- معالجة شاملة للأخطاء
- Logging مفصل للتطوير

### 🔄 التطوير المستقبلي:
- يمكن إضافة المزيد من الخيارات
- يمكن إضافة صور أو فيديو
- يمكن إضافة List Messages (لأكثر من 3 خيارات)

## 🐛 حل المشاكل

### المشكلة: الأزرار لا تظهر
**الحل**: 
1. تحقق من أن `TWILIO_CONTENT_SID` صحيح
2. تأكد من أن Content Template حصل على الموافقة
3. تحقق من الـ logs في `storage/logs/laravel.log`

### المشكلة: خطأ HTTP 400
**الحل**: 
- تأكد من وجود `body` في جميع الرسائل
- تحقق من أن `From` موجود وصحيح

### المشكلة: خطأ TypeError
**الحل**: 
- تأكد من أن جميع المتغيرات في `.env` مملوءة
- تحقق من أن رقم WhatsApp صحيح

## 📚 مراجع إضافية

- [Twilio WhatsApp API Documentation](https://www.twilio.com/docs/whatsapp)
- [WhatsApp Content Templates Guide](https://www.twilio.com/docs/whatsapp/content-templates)
- [Interactive Messages in WhatsApp](https://www.twilio.com/docs/whatsapp/api/messages/interactive)

---

**تم التحديث**: 2025-10-31

