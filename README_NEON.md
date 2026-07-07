# orders_api - Neon PostgreSQL + Render

هذه نسخة API محوّلة للعمل مع:

```text
Render PHP Docker
Neon PostgreSQL
```

## أهم التعديلات

- استخدام `pdo_pgsql` بدل MySQL.
- ملف `config/database.php` يقرأ بيانات Neon من Render Environment Variables.
- تحويل القيم المنطقية إلى `TRUE / FALSE` في استعلامات PostgreSQL.
- تحويل `lastInsertId()` إلى `RETURNING id`.
- تحويل البحث إلى `ILIKE` ليتوافق مع PostgreSQL.
- إزالة أو استبدال دوال MySQL مثل `TIMESTAMPDIFF`, `DATE_ADD`, `SHOW TABLES`.
- إصلاح مسارات وحقول التوجيه: `notes_to_executor`, `allowed_start`, `allowed_end`.
- إضافة `Dockerfile` جاهز لـ Render.

## الفحص الذي تم

تم فحص جميع ملفات PHP بالأمر:

```bash
php -l
```

والنتيجة: لا توجد أخطاء Syntax.

## ملاحظة مهمة

لا يمكن ضمان عمل كل Endpoint 100% إلا بعد تجربته على Render مع قاعدة Neon الفعلية، لأن الأخطاء المتبقية إن وجدت ستكون غالبًا من اختلاف أسماء الأعمدة أو بيانات ناقصة في الجداول.
