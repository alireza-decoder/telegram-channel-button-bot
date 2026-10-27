<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Manual setWebhook: use secret_token equal to the part AFTER ':' in your BotFather token.
// Only two editable settings are needed; no setup.php or additional stored key.
// Never accept unsigned requests: 'from.id' can be forged if the webhook has no secret.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$parts = explode(':', (string) (cfg()['bot_token'] ?? ''), 2);
$expectedSecret = $parts[1] ?? '';
$actualSecret = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($expectedSecret === '' || !preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $expectedSecret)
    || $actualSecret === '' || !hash_equals($expectedSecret, $actualSecret)) {
    http_response_code(403);
    exit;
}
$body = file_get_contents('php://input');
$update = json_decode($body ?: '', true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// Return 200 to prevent Telegram from retrying a request and accidentally duplicating a post.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

try {
    if (isset($update['callback_query'])) {
        processCallback($update['callback_query']);
    } elseif (isset($update['message'])) {
        processMessage($update['message']);
    }
} catch (Throwable $e) {
    error_log('channel-button-bot: ' . $e->getMessage());
    try {
        sendToAdmin('⚠️ خطای ربات: ' . $e->getMessage());
    } catch (Throwable $ignored) {
        error_log('channel-button-bot: cannot notify admin');
    }
}
echo 'OK';

function processMessage(array $message): void
{
    if (($message['chat']['type'] ?? '') !== 'private' || !isset($message['from']['id'])
        || (string) ($message['chat']['id'] ?? '') !== (string) $message['from']['id']
        || !empty($message['from']['is_bot'])) {
        return;
    }
    setCurrentUser((int) $message['from']['id']);
    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '/start' || str_starts_with($text, '/start ')) {
        store(static function (array &$state): void {
            if (!in_array($state['step'], ['publishing', 'saving_edit', 'reposting'], true)) {
                $state['step'] = 'idle';
                $state['draft'] = null;
            }
        });
        sendToAdmin("سلام! 👋\n\nبرای انتشار در کانالی که مدیرش هستی، «ساخت متن دکمه دار» را بزن.\nبرای تنظیم رنگ و ویرایش دکمه‌های پست‌های قبلی، وارد «تنظیمات بات» شو.\nبرای هر پست می‌توانی چند دکمه با چیدمان دلخواه بسازی.", replyKeyboard());
        return;
    }
    if ($text === '/cancel') {
        $result = store(static function (array &$state): bool {
            if (in_array($state['step'], ['publishing', 'saving_edit', 'reposting'], true)) {
                return false;
            }
            $state['step'] = 'idle';
            $state['draft'] = null;
            return true;
        });
        sendToAdmin($result ? '✅ عملیات فعلی لغو شد.' : '⏳ ارسال در حال انجام است؛ فعلاً قابل لغو نیست.', replyKeyboard());
        return;
    }
    if ($text === 'ساخت متن دکمه دار' || $text === 'ساخت متن دکمه‌دار') {
        $result = store(static function (array &$state): bool {
            if (in_array($state['step'], ['publishing', 'saving_edit', 'reposting'], true)) {
                return false;
            }
            $state['draft'] = freshDraft();
            $state['step'] = 'await_text';
            return true;
        });
        sendToAdmin($result ? "📝 مرحله ۱: محتوای پست\nیکی از این دو را ارسال کن:\n۱) متن پست (بعداً می‌توانی عکس اضافه کنی)\n۲) یک عکس با کپشن یا بدون کپشن\n\nایموجی پرمیوم و قالب‌بندی متن را هم می‌توانی در پیام قرار بدهی.\nبرای لغو: /cancel" : '⏳ لطفاً تا مشخص شدن نتیجه ارسال فعلی صبر کن.');
        return;
    }
    if ($text === 'تنظیمات بات') {
        showSettings();
        return;
    }

    $step = store(static fn (array &$state): string => $state['step']);

    if ($step === 'await_text') {
        $photos = $message['photo'] ?? [];
        if (is_array($photos) && count($photos) > 0) {
            $photo = end($photos);
            $fileId = (string) ($photo['file_id'] ?? '');
            $caption = (string) ($message['caption'] ?? '');
            if ($fileId === '' || utf16Length($caption) > 1024) {
                sendToAdmin('❌ عکس یا کپشن نامعتبر است. کپشن عکس باید حداکثر ۱۰۲۴ واحد کاراکتری باشد.');
                return;
            }
            store(static function (array &$state) use ($message, $fileId, $caption): void {
                if ($state['step'] !== 'await_text') return;
                $state['draft']['photo_file_id'] = $fileId;
                $state['draft']['text'] = $caption;
                $state['draft']['entities'] = $message['caption_entities'] ?? [];
                $state['draft']['source_message_id'] = (int) ($message['message_id'] ?? 0);
                $state['draft']['source_matches'] = true;
                $state['step'] = $caption === '' ? 'await_caption' : 'await_button';
            });
            sendToAdmin($caption === ''
                ? "📝 عکس دریافت شد.\nحالا کپشن را بفرست (حداکثر ۱۰۲۴ کاراکتر)؛ یا «بدون کپشن» را ارسال کن."
                : "✅ عکس و کپشن دریافت شد.\n🔘 عنوان دکمه اول را بفرست.");
            return;
        }
        $original = (string) ($message['text'] ?? '');
        if ($original === '' || utf16Length($original) > 4096) {
            sendToAdmin('❌ یک متن معمولی حداکثر ۴۰۹۶ کاراکتری، یا عکس با کپشن بفرست.');
            return;
        }
        store(static function (array &$state) use ($message, $original): void {
            if ($state['step'] !== 'await_text') return;
            $state['draft']['text'] = $original;
            $state['draft']['entities'] = $message['entities'] ?? [];
            $state['draft']['source_message_id'] = (int) ($message['message_id'] ?? 0);
            $state['draft']['source_matches'] = true;
            $state['step'] = 'await_optional_photo_or_button';
        });
        sendToAdmin("✅ متن دریافت شد.\nاگر می‌خواهی همراهش عکس منتشر شود، همین حالا عکس را ارسال کن. کپشن تصویر از همین متن ساخته می‌شود (تا ۱۰۲۴ کاراکتر).\n\nاگر عکس نمی‌خواهی، مستقیماً عنوان دکمه را بفرست یا «بدون عکس» را ارسال کن.");
        return;
    }
    if ($step === 'await_caption') {
        if ($text === 'بدون کپشن') {
            store(static function (array &$state): void {
                if ($state['step'] === 'await_caption') $state['step'] = 'await_button';
            });
        } else {
            $original = (string) ($message['text'] ?? '');
            if ($original === '' || utf16Length($original) > 1024) {
                sendToAdmin('❌ متن کپشن عکس باید بین ۱ تا ۱۰۲۴ کاراکتر باشد. می‌توانی «بدون کپشن» بفرستی.');
                return;
            }
            store(static function (array &$state) use ($original, $message): void {
                if ($state['step'] !== 'await_caption') return;
                $state['draft']['text'] = $original;
                $state['draft']['entities'] = $message['entities'] ?? [];
                $state['draft']['source_matches'] = false;
                $state['step'] = 'await_button';
            });
        }
        sendToAdmin("✅ متن عکس تنظیم شد.\n🔘 عنوان دکمه اول را بفرست.");
        return;
    }
    if ($step === 'await_optional_photo_or_button') {
        $photos = $message['photo'] ?? [];
        if (is_array($photos) && count($photos) > 0) {
            $photo = end($photos);
            $fileId = (string) ($photo['file_id'] ?? '');
            $draftText = store(static fn (array &$state): string => (string) ($state['draft']['text'] ?? ''));
            if ($fileId === '' || utf16Length($draftText) > 1024) {
                sendToAdmin('❌ متن فعلی برای کپشن عکس طولانی است (بیشتر از ۱۰۲۴). برای انتشار آن بدون تصویر عنوان دکمه را بفرست؛ یا /cancel و با کپشن کوتاه‌تر شروع کن.');
                return;
            }
            store(static function (array &$state) use ($fileId): void {
                if ($state['step'] !== 'await_optional_photo_or_button') return;
                $state['draft']['photo_file_id'] = $fileId;
                $state['draft']['source_matches'] = false;
                $state['step'] = 'await_button';
            });
            sendToAdmin("🖼 عکس دریافت شد و متن قبلی به‌عنوان کپشن استفاده می‌شود.\n🔘 عنوان دکمه اول را بفرست.");
            return;
        }
        if ($text === 'بدون عکس') {
            store(static function (array &$state): void {
                if ($state['step'] === 'await_optional_photo_or_button') $state['step'] = 'await_button';
            });
            sendToAdmin("🔘 عنوان دکمه اول را بفرست.");
            return;
        }
        // Backward compatibility: after plain text, the next text can still be
        // the button label, just as in version 1.1.
        $step = 'await_button';
    }
    if ($step === 'await_button') {
        if ($text === '' || str_contains($text, "\n") || utf16Length($text) > 64) {
            sendToAdmin('❌ متن دکمه باید یک‌خطی و حداکثر ۶۴ کاراکتر باشد.');
            return;
        }
        $parsed = parseButtonLabel($text, $message['entities'] ?? []);
        if ($parsed['text'] === '' || utf16Length($parsed['text']) > 64) {
            sendToAdmin('❌ عنوان دکمه نامعتبر است. کنار ایموجی پرمیوم، متن دکمه هم بنویس.');
            return;
        }
        store(static function (array &$state) use ($parsed): void {
            if (!in_array($state['step'], ['await_button', 'await_optional_photo_or_button'], true)) return;
            $state['draft']['pending_button'] = [
                'text' => $parsed['text'],
                'emoji_id' => $parsed['emoji_id'],
                'url' => '',
                'style' => (string) ($state['settings']['style'] ?? 'success'),
                'new_row' => true,
            ];
            $state['step'] = 'await_button_url';
        });
        sendToAdmin("🔗 لینک همین دکمه را بفرست.\nمثال: https://example.com\n\nبرای دکمه بدون لینک، «بدون لینک» را بفرست.\nبرای لینک از تنظیمات بات، «لینک پیش‌فرض» را بفرست.");
        return;
    }
    if ($step === 'await_button_url') {
        $url = normalizeButtonUrl($text);
        $useDefault = ($text === 'لینک پیش‌فرض');
        $withoutLink = ($text === 'بدون لینک');
        if (!$useDefault && !$withoutLink && $url === null) {
            sendToAdmin('❌ لینک معتبر با https:// یا http:// بفرست؛ یا «بدون لینک» / «لینک پیش‌فرض» را انتخاب کن.');
            return;
        }
        $draft = store(static function (array &$state) use ($useDefault, $withoutLink, $url): ?array {
            if ($state['step'] !== 'await_button_url' || $state['draft'] === null) return null;
            $button = $state['draft']['pending_button'] ?? null;
            if (!is_array($button)) return null;
            $state['draft']['buttons'] = draftButtons($state['draft']);
            if (count($state['draft']['buttons']) >= 30) return null;
            $button['url'] = $withoutLink ? '' : ($useDefault ? (string) ($state['settings']['button_url'] ?? '') : (string) $url);
            $button['new_row'] = count($state['draft']['buttons']) === 0 || !empty($button['new_row']);
            $state['draft']['buttons'][] = $button;
            $state['draft']['pending_button'] = null;
            $state['draft']['id'] = bin2hex(random_bytes(8));
            $state['step'] = 'buttons_manage';
            return $state['draft'];
        });
        if ($draft !== null) sendButtonsManager($draft);
        return;
    }
    if ($step === 'await_edit_title') {
        if ($text === '' || str_contains($text, "\n") || utf16Length($text) > 64) {
            sendToAdmin('❌ عنوان دکمه باید یک‌خطی و حداکثر ۶۴ کاراکتر باشد.');
            return;
        }
        $parsed = parseButtonLabel($text, $message['entities'] ?? []);
        if ($parsed['text'] === '' || utf16Length($parsed['text']) > 64) {
            sendToAdmin('❌ عنوان نامعتبر است.');
            return;
        }
        $draft = store(static function (array &$state) use ($parsed): ?array {
            $i = $state['draft']['editing_index'] ?? -1;
            if ($state['step'] !== 'await_edit_title' || !isset($state['draft']['buttons'][$i])) return null;
            $state['draft']['buttons'][$i]['text'] = $parsed['text'];
            $state['draft']['buttons'][$i]['emoji_id'] = $parsed['emoji_id'];
            $state['draft']['editing_index'] = null;
            $state['draft']['id'] = bin2hex(random_bytes(8));
            $state['step'] = 'buttons_manage';
            return $state['draft'];
        });
        if ($draft !== null) sendButtonsManager($draft);
        return;
    }
    if ($step === 'await_edit_url') {
        $url = normalizeButtonUrl($text);
        $useDefault = ($text === 'لینک پیش‌فرض');
        $withoutLink = ($text === 'بدون لینک');
        if (!$useDefault && !$withoutLink && $url === null) {
            sendToAdmin('❌ لینک معتبر وارد کن یا «بدون لینک» / «لینک پیش‌فرض» را بفرست.');
            return;
        }
        $draft = store(static function (array &$state) use ($url, $useDefault, $withoutLink): ?array {
            $i = $state['draft']['editing_index'] ?? -1;
            if ($state['step'] !== 'await_edit_url' || !isset($state['draft']['buttons'][$i])) return null;
            $state['draft']['buttons'][$i]['url'] = $withoutLink ? '' : ($useDefault ? (string) ($state['settings']['button_url'] ?? '') : (string) $url);
            $state['draft']['editing_index'] = null;
            $state['draft']['id'] = bin2hex(random_bytes(8));
            $state['step'] = 'buttons_manage';
            return $state['draft'];
        });
        if ($draft !== null) sendButtonsManager($draft);
        return;
    }
    if ($step === 'await_repost_channel') {
        $channel = normalizeChannel($text);
        if ($channel === null) {
            sendToAdmin('❌ آدرس کانال معتبر نیست. مانند @MyChannel یا لینک t.me/MyChannel یا -100... بفرست.');
            return;
        }
        try {
            $chat = getValidChannel($channel);
            $source = store(static fn (array &$state): string => (string) ($state['draft']['edit_target']['channel'] ?? ''));
            if ((string) $chat['id'] === $source) {
                sendToAdmin('ℹ️ برای انتشار مجدد، یک کانال دیگر انتخاب کن. برای تغییر همان پست، گزینه ثبت تغییرات را بزن.');
                return;
            }
        } catch (Throwable $e) {
            sendToAdmin('❌ کانال مقصد قابل استفاده نیست: ' . $e->getMessage() . "\nلطفاً کانال دیگری بفرست.");
            return;
        }
        $draft = store(static function (array &$state) use ($chat): ?array {
            if ($state['step'] !== 'await_repost_channel' || empty($state['draft']['edit_target'])) return null;
            $state['draft']['repost_target'] = [
                'channel' => (string) $chat['id'],
                'channel_title' => (string) ($chat['title'] ?? 'کانال'),
            ];
            $state['draft']['id'] = bin2hex(random_bytes(8));
            $state['step'] = 'repost_preview';
            return $state['draft'];
        });
        if ($draft !== null) sendRepostPreview($draft);
        return;
    }
    if ($step === 'await_channel') {
        $channel = normalizeChannel($text);
        if ($channel === null) {
            sendToAdmin('❌ آدرس نامعتبر است. آیدی کانال عمومی مثل @MyChannel، لینک t.me/MyChannel یا شناسه عددی -100... را بفرست.');
            return;
        }
        try {
            $chat = getValidChannel($channel);
        } catch (Throwable $e) {
            sendToAdmin('❌ بررسی کانال ناموفق بود: ' . $e->getMessage() . "\n\nآدرس را اصلاح کن و دوباره بفرست.");
            return;
        }
        $draft = store(static function (array &$state) use ($chat): ?array {
            if ($state['step'] !== 'await_channel' || $state['draft'] === null) return null;
            $state['draft']['channel'] = (string) $chat['id'];
            $state['draft']['channel_title'] = (string) ($chat['title'] ?? 'کانال');
            $state['draft']['style'] = $state['settings']['style'];
            // The per-post URL was collected immediately after the button title.
            $state['step'] = 'preview';
            return $state['draft'];
        });
        if ($draft !== null) {
            sendPreview($draft);
        }
        return;
    }
    if ($step === 'await_post_url') {
        sendToAdmin('🔘 برای تغییر لینک و دکمه‌ها از مدیریت دکمه‌های پست استفاده کن.');
        $draft = store(static function (array &$state): ?array {
            if ($state['step'] !== 'await_post_url' || $state['draft'] === null) return null;
            $state['step'] = 'buttons_manage';
            return $state['draft'];
        });
        if ($draft !== null) sendButtonsManager($draft);
        return;
    }
    if ($step === 'buttons_manage') {
        sendToAdmin('برای افزودن، حذف یا مرتب‌سازی از دکمه‌های زیر استفاده کن.');
        $draft = store(static fn (array &$state): ?array => $state['draft']);
        if ($draft !== null) sendButtonsManager($draft);
        return;
    }
    if ($step === 'await_old_post_link') {
        $parts = parseOldPostLink($text);
        if ($parts === null) {
            sendToAdmin('❌ لینک پست معتبر نیست. مثال: https://t.me/ChannelName/123 یا https://t.me/c/12345678/123');
            return;
        }
        if (currentUserId() !== (int) cfg()['admin_user_id']) {
            sendToAdmin('⛔ ثبت پست قدیمی فقط برای مالک اصلی ربات فعال است.');
            return;
        }
        try {
            $chat = getValidChannel($parts[0]);
        } catch (Throwable $e) {
            sendToAdmin('❌ دسترسی به کانال تأیید نشد: ' . $e->getMessage());
            return;
        }
        $added = store(static function (array &$state) use ($chat, $parts): bool {
            if ($state['step'] !== 'await_old_post_link') return false;
            foreach ($state['published_posts'] as $post) {
                if ((string) ($post['channel'] ?? '') === (string) $chat['id'] && (int) ($post['message_id'] ?? 0) === $parts[1]) {
                    $state['step'] = 'idle'; return false;
                }
            }
            $state['published_posts'][] = [
                'id' => bin2hex(random_bytes(8)), 'channel' => (string) $chat['id'],
                'message_id' => $parts[1], 'channel_title' => (string) ($chat['title'] ?? 'کانال'),
                'published_at' => time(), 'buttons' => [], 'legacy' => true,
            ];
            $state['step'] = 'idle';
            return true;
        });
        sendToAdmin($added
            ? '✅ پست قدیمی ثبت شد. دکمه‌های قبلی خودکار قابل خواندن نیستند؛ در بخش آرشیو دکمه‌های جدید را بساز و پس از تأیید جایگزین کن. ثبت‌کردن به‌تنهایی پست کانال را تغییر نمی‌دهد.'
            : 'ℹ️ این پست قبلاً ثبت شده یا عملیات قدیمی منقضی شده است.');
        showPublishedPosts();
        return;
    }
    if ($step === 'repost_preview') {
        sendToAdmin('👆 انتشار مجدد آماده است. از دکمه‌های تأیید / بازگشت / انصراف زیر پیش‌نمایش استفاده کن.');
        return;
    }
    if ($step === 'reposting' || $step === 'repost_uncertain') {
        sendToAdmin($step === 'reposting' ? '⏳ انتشار مجدد در حال انجام است.'
            : '⚠️ نتیجه انتشار مجدد نامشخص است. کانال مقصد را بررسی کن؛ برای جلوگیری از ارسال تکراری، عملیات دوباره انجام نمی‌شود.');
        return;
    }
    if ($step === 'edit_preview') {
        sendToAdmin('پیش‌نمایش تغییرات آماده است. برای ذخیره یا بازگشت به ویرایش از دکمه‌های زیر پیام استفاده کن.');
        return;
    }
    if ($step === 'saving_edit') {
        sendToAdmin('⏳ ویرایش در حال ذخیره است.');
        return;
    }
    if ($step === 'await_url') {
        $url = normalizeButtonUrl($text);
        if ($url === null) {
            sendToAdmin('❌ یک لینک معتبر با https:// یا http:// وارد کن؛ مثلاً https://example.com');
            return;
        }
        store(static function (array &$state) use ($url): void {
            if ($state['step'] !== 'await_url') return;
            $state['settings']['button_url'] = $url;
            $state['step'] = 'idle';
        });
        sendToAdmin('✅ لینک دکمه ذخیره شد: ' . $url);
        showSettings();
        return;
    }
    if ($step === 'preview') {
        sendToAdmin('👆 پیش‌نمایش آماده است. از دکمه «تأیید و انتشار» یا «لغو» زیر پیام تأیید استفاده کن.');
        return;
    }
    if ($step === 'publishing' || $step === 'uncertain') {
        sendToAdmin($step === 'publishing'
            ? '⏳ درخواست انتشار قبلاً ثبت شده است.'
            : "⚠️ نتیجه انتشار قبلی نامشخص است. کانالت را بررسی کن.\nاگر می‌خواهی دوباره شروع کنی، «ساخت متن دکمه دار» را بزن.");
        return;
    }
    sendToAdmin('از دکمه‌های منوی پایین استفاده کن یا /start را بزن.', replyKeyboard());
}

function processCallback(array $callback): void
{
    $id = (string) ($callback['id'] ?? '');
    $action = (string) ($callback['data'] ?? '');
    if ($id === '') return;
    if ($action === 'public:tap') {
        callbackAnswer($id, 'این دکمه هنوز لینک ندارد.');
        return;
    }
    $userId = (string) ($callback['from']['id'] ?? '');
    if (!ctype_digit($userId) || ($callback['message']['chat']['type'] ?? '') !== 'private'
        || (string) ($callback['message']['chat']['id'] ?? '') !== $userId
        || !empty($callback['from']['is_bot'])) {
        callbackAnswer($id, 'این منو فقط در چت خصوصی شما قابل استفاده است.', true);
        return;
    }
    setCurrentUser((int) $userId);
    // Controls carry the draft ID, making old keyboards harmless after starting a new post.
    if (preg_match('/^(badd|bdone|bmanager|babort):([0-9a-f]{16})$/', $action, $m)) {
        $cmd = $m[1]; $draftId = $m[2];
        $result = store(static function (array &$state) use ($cmd, $draftId): ?array {
            if (!in_array($state['step'], ['buttons_manage', 'preview', 'edit_preview'], true)
                || ($state['draft']['id'] ?? '') !== $draftId) return null;
            if ($cmd === 'badd') {
                if (count(draftButtons($state['draft'])) >= 30) return ['limit' => true];
                $state['step'] = 'await_button';
                return ['add' => true];
            }
            if ($cmd === 'babort') {
                $state['step'] = 'idle'; $state['draft'] = null;
                return ['abort' => true];
            }
            if ($cmd === 'bmanager') {
                $state['step'] = 'buttons_manage';
                return $state['draft'];
            }
            if (count(draftButtons($state['draft'])) < 1) return ['empty' => true];
            if (!empty($state['draft']['edit_target'])) {
                $state['draft']['id'] = bin2hex(random_bytes(8));
                $state['step'] = 'edit_preview';
                return ['edit_preview' => $state['draft']];
            }
            // Invalidate earlier publish buttons even if their preview is still visible.
            $state['draft']['id'] = bin2hex(random_bytes(8));
            if (!empty($state['draft']['channel'])) {
                $state['step'] = 'preview';
                return ['preview' => $state['draft']];
            }
            $state['step'] = 'await_channel';
            return ['channel' => true];
        });
        callbackAnswer($id, $result === null ? 'این منو دیگر فعال نیست.' : '✅');
        if ($result === null) return;
        if (isset($result['add'])) sendToAdmin('🔘 عنوان دکمه بعدی را بفرست. برای لغو /cancel را بزن.');
        elseif (isset($result['limit'])) sendToAdmin('❌ حداکثر ۳۰ دکمه برای هر پست قابل ثبت است.');
        elseif (isset($result['empty'])) sendToAdmin('❌ حداقل یک دکمه لازم است.');
        elseif (isset($result['abort'])) sendToAdmin('✅ عملیات لغو شد و پست‌های کانال تغییری نکردند.', replyKeyboard());
        elseif (isset($result['edit_preview'])) sendPublishedEditPreview($result['edit_preview']);
        elseif (isset($result['preview'])) sendPreview($result['preview']);
        elseif (isset($result['channel'])) sendToAdmin("📣 حالا آدرس کانال مقصد را ارسال کن.\n@MyChannel یا https://t.me/MyChannel یا -100...\nهم شما و هم ربات باید در کانال مجوز ارسال پست داشته باشید.");
        else sendButtonsManager($result);
        return;
    }
    if (preg_match('/^(bup|bdown|brow|bdel|bedit|btitle|burl|bpalette):([0-9a-f]{16}):(\d{1,2})$/', $action, $m)) {
        $cmd = $m[1]; $draftId = $m[2]; $i = (int) $m[3];
        $result = store(static function (array &$state) use ($cmd, $draftId, $i): ?array {
            if (($state['step'] ?? '') !== 'buttons_manage' || ($state['draft']['id'] ?? '') !== $draftId) return null;
            $buttons = draftButtons($state['draft']);
            $count = count($buttons);
            if ($i < 0 || $i >= $count) return null;
            if ($cmd === 'bedit') return ['edit' => $state['draft'], 'i' => $i];
            if ($cmd === 'bpalette') return ['palette' => $state['draft'], 'i' => $i];
            if ($cmd === 'btitle' || $cmd === 'burl') {
                $state['draft']['editing_index'] = $i;
                $state['step'] = $cmd === 'btitle' ? 'await_edit_title' : 'await_edit_url';
                return ['input' => $cmd];
            }
            if ($cmd === 'bdel') {
                if ($count === 1) return ['last' => true];
                array_splice($buttons, $i, 1);
            } elseif ($cmd === 'brow') {
                if ($i === 0) return null;
                $buttons[$i]['new_row'] = !($buttons[$i]['new_row'] ?? true);
            } elseif ($cmd === 'bup' || $cmd === 'bdown') {
                $other = $cmd === 'bup' ? $i - 1 : $i + 1;
                if ($other < 0 || $other >= $count) return ['boundary' => true];
                [$buttons[$i], $buttons[$other]] = [$buttons[$other], $buttons[$i]];
            }
            $buttons[0]['new_row'] = true;
            $state['draft']['buttons'] = array_values($buttons);
            $state['draft']['id'] = bin2hex(random_bytes(8));
            return ['manager' => $state['draft']];
        });
        callbackAnswer($id, $result === null ? 'دکمه یا منوی قدیمی نامعتبر است.' : '✅');
        if ($result === null) return;
        if (isset($result['last'])) sendToAdmin('❌ برای حذف آخرین دکمه باید کل پست را لغو کنی.');
        elseif (isset($result['boundary'])) sendToAdmin('این دکمه دیگر در آن جهت جابه‌جا نمی‌شود.');
        elseif (isset($result['edit'])) sendToAdmin('ویرایش دکمه شماره ' . ($i + 1) . ':', buttonEditKeyboard($result['edit'], $i));
        elseif (isset($result['palette'])) sendToAdmin('🎨 رنگ دکمه شماره ' . ($i + 1) . ' را انتخاب کن:', buttonColorKeyboard($result['palette'], $i));
        elseif (isset($result['input'])) sendToAdmin($result['input'] === 'btitle'
            ? '✏️ عنوان جدید دکمه را بفرست (یک خط، حداکثر ۶۴ کاراکتر).'
            : '🔗 لینک جدید دکمه را بفرست؛ یا «بدون لینک» / «لینک پیش‌فرض» را بفرست.');
        else sendButtonsManager($result['manager']);
        return;
    }
    if (preg_match('/^bsetcolor:([0-9a-f]{16}):(\d{1,2}):(success|primary|danger|default)$/', $action, $m)) {
        $draft = store(static function (array &$state) use ($m): ?array {
            $i = (int) $m[2];
            if (($state['step'] ?? '') !== 'buttons_manage' || ($state['draft']['id'] ?? '') !== $m[1]
                || !isset($state['draft']['buttons'][$i])) return null;
            $state['draft']['buttons'][$i]['style'] = $m[3];
            $state['draft']['id'] = bin2hex(random_bytes(8));
            return $state['draft'];
        });
        callbackAnswer($id, $draft === null ? 'این گزینه دیگر فعال نیست.' : 'رنگ ذخیره شد.');
        if ($draft !== null) sendButtonsManager($draft);
        return;
    }
    if (preg_match('/^repost:begin:([0-9a-f]{16})$/', $action, $m)) {
        $started = store(static function (array &$state) use ($m): bool {
            if (!in_array($state['step'], ['buttons_manage', 'edit_preview'], true)
                || ($state['draft']['id'] ?? '') !== $m[1]
                || empty($state['draft']['edit_target'])) return false;
            // Start a new intent with a new nonce: previous edit/save keyboards expire.
            $state['draft']['id'] = bin2hex(random_bytes(8));
            $state['draft']['repost_target'] = null;
            $state['step'] = 'await_repost_channel';
            return true;
        });
        callbackAnswer($id, $started ? 'کانال جدید را ارسال کن.' : 'این گزینه منقضی شده است.');
        if ($started) sendToAdmin("📣 آدرس کانال *جدید* را بفرست.\n@MyChannel یا https://t.me/MyChannel یا -100...\n\nباید هم تو و هم ربات مجوز انتشار در کانال مقصد داشته باشید.\nاصل پست در کانال قبلی تغییری نمی‌کند.\nبرای لغو /cancel");
        return;
    }
    if (preg_match('/^repost:(back|cancel|confirm):([0-9a-f]{16})$/', $action, $m)) {
        $cmd = $m[1]; $draftId = $m[2];
        if ($cmd === 'back' || $cmd === 'cancel') {
            $result = store(static function (array &$state) use ($cmd, $draftId): ?array {
                if ($state['step'] !== 'repost_preview' || ($state['draft']['id'] ?? '') !== $draftId) return null;
                if ($cmd === 'cancel') {
                    $state['step'] = 'idle'; $state['draft'] = null;
                    return ['cancel' => true];
                }
                $state['draft']['repost_target'] = null;
                $state['draft']['id'] = bin2hex(random_bytes(8));
                $state['step'] = 'buttons_manage';
                return ['draft' => $state['draft']];
            });
            callbackAnswer($id, $result === null ? 'این پیش‌نمایش منقضی شده است.' : '✅');
            if (isset($result['cancel'])) { sendToAdmin('✅ انصراف از انتشار مجدد.', replyKeyboard()); showPublishedPosts(); }
            elseif (isset($result['draft'])) sendButtonsManager($result['draft']);
            return;
        }
        // Atomic reservation guards against multiple confirm taps and Telegram retries.
        $draft = store(static function (array &$state) use ($draftId): ?array {
            if ($state['step'] !== 'repost_preview' || ($state['draft']['id'] ?? '') !== $draftId
                || empty($state['draft']['repost_target']) || empty($state['draft']['edit_target'])) return null;
            $state['step'] = 'reposting';
            return $state['draft'];
        });
        callbackAnswer($id, $draft === null ? 'این تأیید قبلاً مصرف شده یا منقضی است.' : 'در حال انتشار مجدد...');
        if ($draft === null) return;
        try {
            $origin = getValidChannel((string) $draft['edit_target']['channel']);
            $target = getValidChannel((string) $draft['repost_target']['channel']);
            if ((string) $origin['id'] === (string) $target['id']) {
                throw new RuntimeException('مبدا و مقصد باید متفاوت باشند.');
            }
        } catch (Throwable $e) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'reposting' && ($state['draft']['id'] ?? '') === $draftId) $state['step'] = 'repost_preview';
            });
            sendToAdmin('❌ مجوز انتشار مجدد تأیید نشد: ' . $e->getMessage());
            return;
        }
        try {
            $request = buildRepostRequest($draft, $draft['repost_target']['channel']);
            $result = tg($request['method'], $request['payload']);
            if (empty($result['ok']) && hasButtonCustomEmoji($draft)) {
                $plain = $draft;
                foreach ($plain['buttons'] as &$b) $b['emoji_id'] = '';
                unset($b);
                $request = buildRepostRequest($plain, $draft['repost_target']['channel']);
                $result = tg($request['method'], $request['payload']);
            }
        } catch (Throwable $e) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'reposting' && ($state['draft']['id'] ?? '') === $draftId) $state['step'] = 'repost_uncertain';
            });
            sendToAdmin('⚠️ پاسخ انتشار مجدد نامشخص است. کانال مقصد را قبل از شروع مجدد بررسی کن تا پست تکراری منتشر نشود.');
            return;
        }
        if (empty($result['ok'])) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'reposting' && ($state['draft']['id'] ?? '') === $draftId) $state['step'] = 'repost_preview';
            });
            sendToAdmin('❌ انتشار در کانال جدید انجام نشد: ' . telegramError($result) .
                "\nدر صورت فعال بودن محافظت محتوا در کانال مبدا، تلگرام ممکن است کپی را ممنوع کند.");
            return;
        }
        $msgId = (int) ($result['result']['message_id'] ?? 0);
        store(static function (array &$state) use ($draftId, $draft, $msgId): void {
            if ($state['step'] !== 'reposting' || ($state['draft']['id'] ?? '') !== $draftId) return;
            $state['step'] = 'idle';
            $state['draft'] = null;
            if ($msgId > 0) {
                $state['published_posts'][] = [
                    'id' => bin2hex(random_bytes(8)),
                    'channel' => (string) $draft['repost_target']['channel'],
                    'channel_title' => (string) $draft['repost_target']['channel_title'],
                    'message_id' => $msgId,
                    'buttons' => draftButtons($draft),
                    'legacy' => false, 'published_at' => time(),
                    'copied_from' => [
                        'channel' => (string) $draft['edit_target']['channel'],
                        'message_id' => (int) $draft['edit_target']['message_id'],
                    ],
                ];
            }
        });
        updateConfirmationMessage($callback, '✅ پست بدون Forward/via در کانال جدید منتشر شد. شناسه پیام: #' . $msgId);
        sendToAdmin('✅ انتشار مجدد موفق! پست #' . $msgId . ' در کانال ' .
            $draft['repost_target']['channel_title'] . ' ثبت و به آرشیو اضافه شد.', replyKeyboard());
        return;
    }
    if (preg_match('/^posts:page:(\d{1,5})$/', $action, $m)) {
        callbackAnswer($id);
        showPublishedPosts(min(99999, (int) $m[1]));
        return;
    }
    if ($action === 'posts:import') {
        $allowed = currentUserId() === (int) cfg()['admin_user_id'];
        if ($allowed) $allowed = store(static function (array &$state): bool {
            if (in_array($state['step'], ['publishing', 'saving_edit', 'reposting'], true)) return false;
            $state['step'] = 'await_old_post_link';
            $state['draft'] = null;
            return true;
        });
        callbackAnswer($id, $allowed ? 'آدرس پست را بفرست' : 'این گزینه در دسترس نیست.');
        if ($allowed) sendToAdmin("🔗 لینک پست قدیمی کانال را بفرست.\nمثال: https://t.me/MyChannel/123\n\nدکمه‌های قبلی قابل خواندن نیستند؛ در مرحله بعد باید دوباره ساخته شوند. فقط پستی که همین ربات قبلاً منتشر کرده باشد قابل ویرایش است.\nلغو: /cancel");
        return;
    }
    if (preg_match('/^posts:open:([0-9a-f]{16})$/', $action, $m)) {
        $post = store(static function (array &$state) use ($m): ?array {
            $i = publishedPostIndex($state, $m[1]);
            return $i === null ? null : $state['published_posts'][$i];
        });
        if ($post === null) {
            callbackAnswer($id, 'این پست در آرشیو شما وجود ندارد.');
            return;
        }
        // Check again rather than trusting a previous channel membership.
        try { getValidChannel((string) $post['channel']); }
        catch (Throwable $e) {
            callbackAnswer($id, 'اجازه ویرایش این کانال را ندارید.', true);
            return;
        }
        $draft = store(static function (array &$state) use ($m): ?array {
            if (in_array($state['step'], ['publishing', 'saving_edit', 'reposting'], true)) return null;
            $i = publishedPostIndex($state, $m[1]);
            if ($i === null) return null;
            $record = $state['published_posts'][$i];
            $draft = freshDraft();
            $draft['buttons'] = $record['buttons'] ?? [];
            $draft['edit_target'] = [
                'id' => $record['id'], 'channel' => $record['channel'],
                'message_id' => $record['message_id'],
                'channel_title' => $record['channel_title'],
            ];
            $draft['channel'] = $record['channel'];
            $draft['channel_title'] = $record['channel_title'];
            $state['draft'] = $draft;
            $state['step'] = 'buttons_manage';
            return $draft;
        });
        callbackAnswer($id, $draft === null ? 'ویرایش فعلاً در دسترس نیست.' : 'ویرایش پست انتخاب شد.');
        if ($draft !== null) {
            sendToAdmin("✏️ ویرایش دکمه‌های پست شماره " . (int) $post['message_id'] .
                "\nکانال: " . $post['channel_title'] .
                (!empty($post['legacy']) ? "\n⚠️ این پست قدیمی است؛ دکمه‌های فعلی آن قابل بازیابی نیستند. با افزودن دکمه‌های جدید و ثبت، دکمه‌های قبلی جایگزین می‌شوند." : ''));
            sendButtonsManager($draft);
        }
        return;
    }
    if (preg_match('/^posts:(save|abort):([0-9a-f]{16})$/', $action, $m)) {
        $cmd = $m[1]; $draftId = $m[2];
        if ($cmd === 'abort') {
            $done = store(static function (array &$state) use ($draftId): bool {
                if ($state['step'] !== 'edit_preview' || ($state['draft']['id'] ?? '') !== $draftId) return false;
                $state['step'] = 'idle'; $state['draft'] = null;
                return true;
            });
            callbackAnswer($id, $done ? 'ویرایش لغو شد.' : 'این پیش‌نمایش منقضی شده است.');
            if ($done) showPublishedPosts();
            return;
        }
        $draft = store(static function (array &$state) use ($draftId): ?array {
            if (($state['step'] ?? '') !== 'edit_preview' || ($state['draft']['id'] ?? '') !== $draftId) return null;
            $target = $state['draft']['edit_target'] ?? null;
            if (!is_array($target) || publishedPostIndex($state, (string) ($target['id'] ?? '')) === null) return null;
            $state['step'] = 'saving_edit';
            return $state['draft'];
        });
        callbackAnswer($id, $draft === null ? 'این پیش‌نمایش قبلاً استفاده شده است.' : 'در حال ذخیره دکمه‌ها...');
        if ($draft === null) return;
        $target = $draft['edit_target'];
        try {
            getValidChannel((string) $target['channel']);
            $payload = [
                'chat_id' => $target['channel'], 'message_id' => (int) $target['message_id'],
                'reply_markup' => inlineButton($draft),
            ];
            $result = tg('editMessageReplyMarkup', $payload);
            if (empty($result['ok']) && hasButtonCustomEmoji($draft)) {
                // Premium icon restrictions: retry as ordinary text/buttons.
                $plain = $draft;
                foreach ($plain['buttons'] as &$button) $button['emoji_id'] = '';
                unset($button);
                $result = tg('editMessageReplyMarkup', [
                    'chat_id' => $target['channel'], 'message_id' => (int) $target['message_id'],
                    'reply_markup' => inlineButton($plain),
                ]);
            }
        } catch (Throwable $e) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'saving_edit' && ($state['draft']['id'] ?? '') === $draftId) $state['step'] = 'edit_preview';
            });
            sendToAdmin('⚠️ ارتباط قطع شد و وضعیت ویرایش نامشخص است. پست کانال را بررسی کن؛ در صورت نیاز ثبت تغییرات را دوباره بزن.');
            return;
        }
        $ok = !empty($result['ok']) || str_contains(strtolower(telegramError($result)), 'message is not modified');
        if (!$ok) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'saving_edit' && ($state['draft']['id'] ?? '') === $draftId) $state['step'] = 'edit_preview';
            });
            sendToAdmin('❌ ویرایش پست موفق نبود: ' . telegramError($result) . '\n\nدر صورت رفع مشکل، دکمه ثبت تغییرات را دوباره بزن.');
            return;
        }
        store(static function (array &$state) use ($draftId, $draft): void {
            if ($state['step'] !== 'saving_edit' || ($state['draft']['id'] ?? '') !== $draftId) return;
            $i = publishedPostIndex($state, (string) $draft['edit_target']['id']);
            if ($i !== null) {
                $state['published_posts'][$i]['buttons'] = draftButtons($draft);
                $state['published_posts'][$i]['legacy'] = false;
                $state['published_posts'][$i]['edited_at'] = time();
            }
            $state['step'] = 'idle'; $state['draft'] = null;
        });
        updateConfirmationMessage($callback, '✅ دکمه‌های همین پست در کانال بروزرسانی شدند. متن و تصویر تغییری نکرد.');
        sendToAdmin('✅ ویرایش دکمه‌های پست منتشرشده موفق بود.', replyKeyboard());
        return;
    }
    if ($action === 'settings:show') {
        callbackAnswer($id);
        showSettings();
        return;
    }
    if ($action === 'settings:color') {
        callbackAnswer($id);
        sendToAdmin('رنگ دکمه‌های پست‌های جدید را انتخاب کن:', stylesKeyboard());
        return;
    }
    if ($action === 'settings:link') {
        $allowed = store(static function (array &$state): bool {
            if ($state['step'] === 'publishing') return false;
            $state['step'] = 'await_url';
            $state['draft'] = null;
            return true;
        });
        callbackAnswer($id, $allowed ? 'آماده دریافت لینک' : 'در حال انتشار پست');
        if ($allowed) sendToAdmin("🔗 لینک مقصد دکمه‌های پست‌های جدید را بفرست.\nمثال: https://example.com\n\nبرای لغو /cancel را بزن.");
        return;
    }
    if ($action === 'settings:clear') {
        store(static function (array &$state): void {
            $state['settings']['button_url'] = '';
        });
        callbackAnswer($id, 'لینک حذف شد.');
        showSettings();
        return;
    }
    if (preg_match('/^style:(success|primary|danger|default)$/', $action, $match)) {
        store(static function (array &$state) use ($match): void {
            $state['settings']['style'] = $match[1];
        });
        callbackAnswer($id, 'رنگ ذخیره شد.');
        showSettings();
        return;
    }
    if (preg_match('/^(publish|cancel):([0-9a-f]{16})$/', $action, $match)) {
        $command = $match[1];
        $draftId = $match[2];
        if ($command === 'cancel') {
            $canceled = store(static function (array &$state) use ($draftId): bool {
                if ($state['step'] !== 'preview' || ($state['draft']['id'] ?? '') !== $draftId) return false;
                $state['step'] = 'idle';
                $state['draft'] = null;
                return true;
            });
            callbackAnswer($id, $canceled ? 'لغو شد.' : 'این پیش‌نمایش دیگر فعال نیست.');
            if ($canceled) updateConfirmationMessage($callback, '❌ انتشار این پست لغو شد.');
            return;
        }

        // Atomic claim prevents duplicate publication when button is pressed twice.
        $draft = store(static function (array &$state) use ($draftId): ?array {
            if ($state['step'] !== 'preview' || ($state['draft']['id'] ?? '') !== $draftId) return null;
            $state['step'] = 'publishing';
            return $state['draft'];
        });
        callbackAnswer($id, $draft === null ? 'این پیش‌نمایش قبلاً استفاده شده یا منقضی است.' : 'در حال انتشار...');
        if ($draft === null) return;
        // Public users must not publish into channels managed by other users.
        // Re-check at the moment of publication to handle revoked permissions.
        try {
            getValidChannel((string) $draft['channel']);
        } catch (Throwable $e) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'publishing' && ($state['draft']['id'] ?? '') === $draftId) {
                    $state['step'] = 'preview';
                }
            });
            sendToAdmin('❌ اجازه انتشار در کانال ندارید: ' . $e->getMessage());
            return;
        }
        try {
            // All variants publish WITHOUT a forwarded-from or via attribution.
            // copyMessage is used for intact original content; sendPhoto when
            // media & caption arrived in separate messages.
            $request = buildPostRequest($draft, $draft['channel']);
            $result = tg($request['method'], $request['payload']);
            // Some channels do not allow custom-emoji entities or button icons.
            // When Telegram explicitly rejects that request, retry just once
            // with ordinary Unicode emoji, retaining all non-emoji formatting.
            if (empty($result['ok']) && hasPremiumEmoji($draft)) {
                $plain = buildPlainPostRequest($draft, $draft['channel']);
                $result = tg($plain['method'], $plain['payload']);
            }
        } catch (Throwable $e) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'publishing' && ($state['draft']['id'] ?? '') === $draftId) {
                    $state['step'] = 'uncertain';
                }
            });
            updateConfirmationMessage($callback, '⚠️ نتیجه درخواست نامشخص است؛ کانال را بررسی کن. برای جلوگیری از ارسال تکراری، انتشار مجدد خودکار انجام نمی‌شود.');
            sendToAdmin('⚠️ ارتباط با تلگرام قطع شد یا پاسخ قابل خواندن نبود. ممکن است پست ارسال شده باشد. کانال را بررسی کن؛ در صورت نیاز یک پست جدید بساز.');
            return;
        }
        if (empty($result['ok'])) {
            store(static function (array &$state) use ($draftId): void {
                if ($state['step'] === 'publishing' && ($state['draft']['id'] ?? '') === $draftId) {
                    $state['step'] = 'preview';
                }
            });
            sendToAdmin('❌ انتشار انجام نشد: ' . telegramError($result) . "\n\nدسترسی‌های کانال را بررسی کن. در صورت رفع مشکل می‌توانی دوباره دکمه تأیید را بزنی.");
            return;
        }
        $msgId = (int) ($result['result']['message_id'] ?? 0);
        store(static function (array &$state) use ($draftId, $draft, $msgId): void {
            if ($state['step'] === 'publishing' && ($state['draft']['id'] ?? '') === $draftId) {
                $state['step'] = 'idle';
                $state['draft'] = null;
                $state['last_published'] = ['channel' => $draft['channel'], 'message_id' => $msgId, 'time' => time()];
                if ($msgId > 0) {
                    $state['published_posts'][] = [
                        'id' => bin2hex(random_bytes(8)),
                        'channel' => (string) $draft['channel'], 'channel_title' => (string) $draft['channel_title'],
                        'message_id' => $msgId, 'buttons' => draftButtons($draft),
                        'legacy' => false, 'published_at' => time(),
                    ];
                }
            }
        });
        updateConfirmationMessage($callback, '✅ پست با موفقیت در کانال منتشر شد؛ بدون فوروارد و بدون via.');
        sendToAdmin('✅ انتشار موفق! شناسه پیام در کانال: ' . $msgId, replyKeyboard());
        return;
    }
    callbackAnswer($id, 'عملیات نامعتبر است.');
}
