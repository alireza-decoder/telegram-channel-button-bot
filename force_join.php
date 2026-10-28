<?php
declare(strict_types=1);
/**
 * تنظیمات عضویت اجباری؛ بدون نیاز به تغییر config.php یا webhook.php
 * بعد از واردکردن کانال‌ها enabled را true کنید.
 * ربات باید در همه کانال‌های زیر ادمین باشد تا getChatMember قابل اتکا باشد.
 * هر کاربر باید عضو تمام کانال‌ها باشد.
 */
return [
    'enabled' => false, // true = فعال | false = غیرفعال

    // متن بالای دکمه‌ها - مانند تصویر نمونه
    'title' => '🔒 عضویت الزامی',
    'description' => "برای ادامه ابتدا در کانال‌های مشخص‌شده عضو شوید و سپس روی دکمه بررسی بزنید.\n\n✨ پس از تأیید عضویت، امکانات ربات برای شما فعال می‌شود.",
    'success_text' => '✅ عضویت شما تأیید شد. از منوی زیر استفاده کنید.',

    // عنوان و رنگ دکمه بررسی: success=سبز، primary=آبی، danger=قرمز، default=معمولی
    'check_button_text' => '✅ بررسی عضویت',
    'check_button_style' => 'success',

    // هر تعداد کانال بین ۱ تا ۱۰؛ برای کانال خصوصی chat_id عددی -100... و لینک دعوت بگذارید.
    'channels' => [
        [
            'chat_id' => '@YOUR_CHANNEL',
            'url' => 'https://t.me/YOUR_CHANNEL',
            'button_text' => '📢 عضویت در کانال اصلی',
            'button_style' => 'default',
        ],
        // کانال دوم نمونه؛ در صورت نیاز کامنت‌ها را بردارید و مقادیر را تغییر دهید:
        // [
        //     'chat_id' => '@YOUR_SECOND_CHANNEL',
        //     'url' => 'https://t.me/YOUR_SECOND_CHANNEL',
        //     'button_text' => '📣 عضویت در کانال دوم',
        //     'button_style' => 'primary',
        // ],
    ],
];
