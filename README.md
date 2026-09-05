<div dir="rtl">

# پنل هوشمند میلانو ✨

پنل فارسی (راست‌چین) مدیریت و استعلام بازار میلانو با طراحی لوکس طلایی/سرمه‌ای، انیمیشن‌های زنده، تحلیل هوشمند قیمت (ترب و دیجی‌کالا) و استودیو تصویرسازی سه‌بعدی.

## فایل‌ها

| فایل | توضیح |
|---|---|
| `index.html` | نسخه ارتقایافته پنل (نقطه ورود اصلی PWA و اندروید) |
| `gemini-code-1788110613515.html` | همان نسخه ارتقایافته با نام قبلی |
| `manifest.webmanifest` | مانیفست نصب به‌عنوان اپ (PWA) |
| `service-worker.js` | پشتیبانی آفلاین و کش هوشمند |
| `offline.html` | صفحه حالت آفلاین |
| `icons/` | آیکن‌ها (192، 512، maskable، اپل‌تاچ، فاوآیکن) |
| `assets/icon-master.png` | آیکن مادر طلایی میلانو (1024×1024) |
| `android/` | سورس اپ اندرویدی (WebView + اسپلش + آیکن تطبیقی) |
| `MeelanoPanel.apk` | فایل نصب اندروید (پس از ساخت خودکار) |

## پیش‌نمایش

```bash
python3 -m http.server 8000
# باز کردن http://localhost:8000/index.html
```

## نصب روی اندروید — دو راه

### راه اول: فایل APK (پیشنهادی) 📱

1. فایل **`releases/MeelanoPanel.apk`** همین ریپو را دانلود کنید (همیشه تازه‌ترین بیلد موفق است)
2. یا: تب **Actions** ← آخرین اجرای موفق **Build Android APK** ← بخش **Artifacts** ← دانلود **MeelanoPanel-apk**
3. فایل را به گوشی منتقل و نصب کنید (در صورت هشدار، «نصب از منبع ناشناس» را تأیید کنید)
4. آیکن طلایی میلانو روی گوشی نصب می‌شود + صفحه اسپلش اختصاصی

### راه دوم: نصب مستقیم از مرورگر (PWA) ⚡

1. در ریپو: **Settings** ← **Pages** ← jaz **Deploy from a branch** ← شاخه `arena/01a07301-panelhoshmand` و پوشه `/` را انتخاب و **Save** کنید
2. بعد از چند دقیقه آدرس `https://companymeelano.github.io/panelhoshmand/` فعال می‌شود
3. آن را در **کروم اندروید** باز کنید و دکمه **«نصب اپ اندروید»** بالای پنل را بزنید
4. اپ تمام‌صفحه با آیکن طلایی نصب می‌شود و با قطع اینترنت صفحه آفلاین نشان می‌دهد

ساخت دستی:

```bash
# کپی فایل‌های وب داخل assets
mkdir -p android/app/src/main/assets/www/icons
cp index.html offline.html manifest.webmanifest service-worker.js android/app/src/main/assets/www/
cp icons/*.png icons/*.ico android/app/src/main/assets/www/icons/
cd android && gradle assembleDebug
# خروجی: app/build/outputs/apk/debug/app-debug.apk
```

> نکته: قالب‌بندی (Tailwind)، فونت و استعلام قیمت به اینترنت نیاز دارند؛ اسکلت و آیکن اپ به‌صورت آفلاین هم کار می‌کند.

</div>
