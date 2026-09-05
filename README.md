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

## نصب روی اندروید (بدون نیاز به APK)

1. آدرس سایت را در **کروم اندروید** باز کنید
2. دکمه **«نصب اپ اندروید»** بالای پنل را بزنید (یا منوی ⋮ ← نصب برنامه)
3. آیکن طلایی میلانو روی گوشی نصب می‌شود و تمام‌صفحه اجرا می‌شود

## فایل نصب APK

هر بار که تغییری پوش شود، گیت‌هاب‌اکشن به‌صورت خودکار فایل `MeelanoPanel.apk` را می‌سازد:

- تب **Actions** ← آخرین اجرای موفق **Build Android APK** ← دانلود **MeelanoPanel-apk**
- فایل را به گوشی منتقل و نصب کنید (در صورت هشدار، «نصب از منبع ناشناس» را تأیید کنید)

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
