<?php
declare(strict_types=1);

/** Internal helpers for the channel post publisher. No third-party packages. */

function cfg(): array
{
    static $data = null;
    if ($data === null) {
        $data = require __DIR__ . '/config.php';
    }
    return $data;
}

function tg(string $method, array $payload = []): array
{
    $config = cfg();
    $url = 'https://api.telegram.org/bot' . $config['bot_token'] . '/' . $method;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 22,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $problem = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Telegram transport error: ' . $problem);
        }
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'timeout' => 22,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new RuntimeException('Telegram transport error (allow_url_fopen/HTTPS).');
        }
    }

    $response = json_decode($raw, true);
    if (!is_array($response) || !array_key_exists('ok', $response)) {
        throw new RuntimeException('Invalid response from Telegram API.');
    }
    return $response;
}

function telegramError(array $response): string
{
    return (string) ($response['description'] ?? 'خطای ناشناخته تلگرام');
}

function ensureTelegramSuccess(array $response): array
{
    if (empty($response['ok'])) {
        throw new RuntimeException(telegramError($response));
    }
    return $response['result'] ?? [];
}

// Each webhook request is scoped to the Telegram user that initiated it.
// Never derive the current user from a client-provided callback_data value.
function setCurrentUser(int $userId): void
{
    if ($userId < 1) throw new InvalidArgumentException('Invalid user ID.');
    $GLOBALS['channel_button_current_user_id'] = $userId;
}

function currentUserId(): int
{
    $id = (int) ($GLOBALS['channel_button_current_user_id'] ?? 0);
    if ($id < 1) throw new RuntimeException('Missing current user context.');
    return $id;
}

// Kept the original function name to avoid changing all workflow call sites.
function sendToAdmin(string $text, ?array $keyboard = null): void
{
    $payload = ['chat_id' => currentUserId(), 'text' => $text];
    if ($keyboard !== null) {
        $payload['reply_markup'] = $keyboard;
    }
    $res = tg('sendMessage', $payload);
    if (!$res['ok']) {
        error_log('Bot admin sendMessage error: ' . telegramError($res));
    }
}

function replyKeyboard(): array
{
    return [
        'keyboard' => [
            [['text' => 'ساخت متن دکمه دار', 'style' => 'primary']],
            [['text' => 'تنظیمات بات']],
        ],
        'resize_keyboard' => true,
        'is_persistent' => true,
    ];
}

function styleLabel(string $style): string
{
    return match ($style) {
        'success' => '🟢 سبز',
        'primary' => '🔵 آبی',
        'danger' => '🔴 قرمز',
        default => '⚪ پیش‌فرض',
    };
}

function stylesKeyboard(): array
{
    return ['inline_keyboard' => [
        [
            ['text' => '🟢 سبز', 'callback_data' => 'style:success', 'style' => 'success'],
            ['text' => '🔵 آبی', 'callback_data' => 'style:primary', 'style' => 'primary'],
        ],
        [
            ['text' => '🔴 قرمز', 'callback_data' => 'style:danger', 'style' => 'danger'],
            ['text' => '⚪ پیش‌فرض', 'callback_data' => 'style:default'],
        ],
        [['text' => '↩️ بازگشت', 'callback_data' => 'settings:show']],
    ]];
}

function settingsKeyboard(): array
{
    return ['inline_keyboard' => [
        [['text' => '🎨 تغییر رنگ دکمه', 'callback_data' => 'settings:color']],
        [['text' => '🔗 تنظیم لینک دکمه', 'callback_data' => 'settings:link']],
        [['text' => '🗑 حذف لینک دکمه', 'callback_data' => 'settings:clear']],
        [['text' => '🗂 مدیریت پست‌های منتشرشده', 'callback_data' => 'posts:page:0']],
    ]];
}

function store(callable $callback): mixed
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Storage folder is missing or not writable.');
    }
    // Preserve the owner's existing settings and drafts from v1.5.
    $file = $dir . (currentUserId() === (int) cfg()['admin_user_id'] ? '/state.php' : '/state_' . currentUserId() . '.php');
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Cannot open state file.');
    }
    @chmod($file, 0600);
    $prefix = "<?php http_response_code(404); exit; ?>\n";
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock state file.');
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === '') {
            $state = [
                'step' => 'idle',
                'settings' => ['style' => 'success', 'button_url' => ''],
                'draft' => null,
                'last_published' => null,
                'published_posts' => [],
            ];
        } elseif (str_starts_with($raw, $prefix)) {
            $state = json_decode(substr($raw, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($state) || !isset($state['settings'])) {
                throw new RuntimeException('Corrupt state file.');
            }
        } else {
            throw new RuntimeException('Unrecognized state file.');
        }
        if (!isset($state['published_posts']) || !is_array($state['published_posts'])) {
            $state['published_posts'] = [];
            $old = $state['last_published'] ?? null;
            if (is_array($old) && !empty($old['channel']) && !empty($old['message_id'])) {
                $state['published_posts'][] = [
                    'id' => bin2hex(random_bytes(8)), 'channel' => (string) $old['channel'],
                    'message_id' => (int) $old['message_id'], 'channel_title' => 'پست قبلی',
                    'buttons' => [], 'legacy' => true,
                    'published_at' => (int) ($old['time'] ?? time()),
                ];
            }
        }
        $result = $callback($state);
        $encoded = $prefix . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('Failed to save state.');
        }
        fflush($handle);
        flock($handle, LOCK_UN);
        return $result;
    } finally {
        fclose($handle);
    }
}

function freshDraft(): array
{
    return [
        'id' => bin2hex(random_bytes(8)),
        'text' => '', 'entities' => [], 'button' => '', 'button_emoji_id' => '',
        'photo_file_id' => '', 'source_message_id' => 0, 'source_matches' => false,
        'channel' => '', 'channel_title' => '',
        'style' => 'success', 'url' => '',
        'buttons' => [], 'pending_button' => null, 'editing_index' => null,
    ];
}

/**
 * Build a Telegram request without adding an attribution / via label.
 * copyMessage is NOT forwardMessage; original formatting & custom emoji are
 * preserved when the administrator supplied the content in ONE message.
 * For a separately supplied caption/photo, we use sendPhoto + caption_entities.
 */
function buildPostRequest(array $draft, int|string $destination): array
{
    $payload = ['chat_id' => $destination, 'reply_markup' => inlineButton($draft)];
    // Use explicit entities for messages containing custom emoji; Telegram
    // may replace them with ordinary characters in channels. That is fine.
    if (!draftHasCustomEmoji($draft) && !hasButtonCustomEmoji($draft) && !empty($draft['source_matches']) && !empty($draft['source_message_id'])) {
        $payload['from_chat_id'] = currentUserId();
        $payload['message_id'] = (int) $draft['source_message_id'];
        return ['method' => 'copyMessage', 'payload' => $payload];
    }
    if (!empty($draft['photo_file_id'])) {
        $payload['photo'] = $draft['photo_file_id'];
        if ($draft['text'] !== '') {
            $payload['caption'] = $draft['text'];
            $payload['caption_entities'] = $draft['entities'] ?? [];
        }
        return ['method' => 'sendPhoto', 'payload' => $payload];
    }
    $payload['text'] = $draft['text'];
    $payload['entities'] = $draft['entities'] ?? [];
    return ['method' => 'sendMessage', 'payload' => $payload];
}

/** Whether there are premium entities in the post text/caption. */
function draftHasCustomEmoji(array $draft): bool
{
    foreach (($draft['entities'] ?? []) as $entity) {
        if (is_array($entity) && ($entity['type'] ?? '') === 'custom_emoji' && !empty($entity['custom_emoji_id'])) return true;
    }
    return false;
}

/**
 * Graceful fallback for channels where custom emoji are restricted.
 * Keep text, photos, links and all ordinary formatting; only drop
 * custom_emoji entities and the native premium button icon.
 */
function buildPlainPostRequest(array $draft, int|string $destination): array
{
    $draft['button_emoji_id'] = '';
    if (isset($draft['buttons']) && is_array($draft['buttons'])) {
        foreach ($draft['buttons'] as &$button) $button['emoji_id'] = '';
        unset($button);
    }
    $draft['source_matches'] = false;
    $draft['entities'] = array_values(array_filter(
        $draft['entities'] ?? [],
        static fn ($entity): bool => !is_array($entity) || ($entity['type'] ?? '') !== 'custom_emoji'
    ));
    return buildPostRequest($draft, $destination);
}

function hasPremiumEmoji(array $draft): bool
{
    foreach (($draft['entities'] ?? []) as $entity) {
        if (($entity['type'] ?? '') === 'custom_emoji') return true;
    }
    return hasButtonCustomEmoji($draft);
}

function hasButtonCustomEmoji(array $draft): bool
{
    foreach (draftButtons($draft) as $button) {
        if (!empty($button['emoji_id'])) return true;
    }
    return false;
}


/** Parse custom emoji at very beginning of the button label, if any.
 * It becomes a native Telegram button icon; it is not displayed twice.
 * Telegram can restrict premium icons in channels for ordinary bots.
 */
function parseButtonLabel(string $raw, array $entities): array
{
    foreach ($entities as $entity) {
        if (($entity['type'] ?? '') !== 'custom_emoji' || ($entity['offset'] ?? -1) !== 0 || empty($entity['custom_emoji_id'])) continue;
        $length = (int) ($entity['length'] ?? 0);
        if ($length < 1 || $length > 10) continue;
        $utf16 = @iconv('UTF-8', 'UTF-16LE', $raw);
        if ($utf16 === false) break;
        $remainder = @iconv('UTF-16LE', 'UTF-8', substr($utf16, $length * 2));
        if (!is_string($remainder) || trim($remainder) === '') break;
        return ['text' => trim($remainder), 'emoji_id' => (string) $entity['custom_emoji_id']];
    }
    return ['text' => $raw, 'emoji_id' => ''];
}

/** Backwards-compatible view of the older single-button draft format. */
function draftButtons(array $draft): array
{
    if (isset($draft['buttons']) && is_array($draft['buttons']) && count($draft['buttons']) > 0) {
        return array_values($draft['buttons']);
    }
    if (!empty($draft['button'])) {
        return [[
            'text' => (string) $draft['button'],
            'url' => (string) ($draft['url'] ?? ''),
            'style' => (string) ($draft['style'] ?? 'default'),
            'emoji_id' => (string) ($draft['button_emoji_id'] ?? ''),
            'new_row' => true,
        ]];
    }
    return [];
}

/** Build a Telegram inline keyboard with up to two buttons per row. */
function inlineButton(array $draft): array
{
    $rows = [];
    foreach (draftButtons($draft) as $i => $entry) {
        $button = ['text' => (string) ($entry['text'] ?? '')];
        if (!empty($entry['emoji_id'])) {
            $button['icon_custom_emoji_id'] = (string) $entry['emoji_id'];
        }
        $style = (string) ($entry['style'] ?? $draft['style'] ?? 'default');
        if (in_array($style, ['success', 'primary', 'danger'], true)) {
            $button['style'] = $style;
        }
        if (!empty($entry['url'])) {
            $button['url'] = (string) $entry['url'];
        } else {
            $button['callback_data'] = 'public:tap';
        }
        if ($i === 0 || !empty($entry['new_row']) || count($rows[count($rows) - 1]) >= 2) {
            $rows[] = [$button];
        } else {
            $rows[count($rows) - 1][] = $button;
        }
    }
    return ['inline_keyboard' => $rows];
}

function layoutDescription(array $draft): string
{
    $lines = [];
    foreach (inlineButton($draft)['inline_keyboard'] as $r => $buttons) {
        $names = array_map(static fn (array $button): string => mbSafeCut($button['text'], 22) . (utf16Length($button['text']) > 22 ? '…' : ''), $buttons);
        $lines[] = 'ردیف ' . ($r + 1) . ': ' . implode(' | ', $names);
    }
    return implode("\n", $lines);
}

function buttonsManagerKeyboard(array $draft): array
{
    $id = $draft['id'];
    $buttons = draftButtons($draft);
    $rows = [[['text' => '➕ افزودن دکمه', 'callback_data' => 'badd:' . $id, 'style' => 'success']]];
    foreach ($buttons as $i => $button) {
        $label = (string) $button['text'];
        if (utf16Length($label) > 14) $label = mbSafeCut($label, 14) . '…';
        $r = [
            ['text' => '✏️ ' . ($i + 1), 'callback_data' => "bedit:$id:$i"],
            ['text' => '⬆️', 'callback_data' => "bup:$id:$i"],
            ['text' => '⬇️', 'callback_data' => "bdown:$id:$i"],
        ];
        if ($i > 0) {
            $r[] = ['text' => !empty($button['new_row']) ? '↔️' : '↩️', 'callback_data' => "brow:$id:$i"];
        }
        $r[] = ['text' => '🗑', 'callback_data' => "bdel:$id:$i"];
        $rows[] = $r;
    }
    $rows[] = [['text' => !empty($draft['edit_target']) ? '✅ پیش‌نمایش تغییرات همان پست' : '✅ ادامه و انتخاب کانال / پیش‌نمایش', 'callback_data' => 'bdone:' . $id, 'style' => 'primary']];
    if (!empty($draft['edit_target'])) {
        $rows[] = [['text' => '📤 انتشار مجدد در کانال دیگر', 'callback_data' => 'repost:begin:' . $id, 'style' => 'success']];
    }
    $rows[] = [['text' => !empty($draft['edit_target']) ? '❌ انصراف از ویرایش' : '❌ لغو پست', 'callback_data' => 'babort:' . $id, 'style' => 'danger']];
    return ['inline_keyboard' => $rows];
}

function mbSafeCut(string $text, int $length): string
{
    if (function_exists('mb_substr')) return mb_substr($text, 0, $length, 'UTF-8');
    preg_match_all('/./us', $text, $matches);
    return implode('', array_slice($matches[0], 0, $length));
}

function sendButtonsManager(array $draft): void
{
    $buttons = draftButtons($draft);
    $message = !empty($draft['edit_target']) ? '🛠 ویرایش دکمه‌های پست منتشرشده' . "\n\n" : '🔘 مدیریت دکمه‌های همین پست' . "\n\n";
    $message .= 'تعداد: ' . count($buttons) . ' دکمه' . "\n";
    $message .= 'چیدمان فعلی:' . "\n" . (layoutDescription($draft) ?: 'بدون دکمه') . "\n\n";
    foreach ($buttons as $i => $button) {
        $link = $button['url'] ?: '(بدون لینک)';
        if (strlen($link) > 40) $link = substr($link, 0, 37) . '...';
        $message .= ($i + 1) . ') ' . styleLabel((string) ($button['style'] ?? 'default')) . ' — ' . $link . "\n";
    }
    $message .= "\n";
    $message .= "✏️ ویرایش عنوان، لینک یا رنگ | ⬆️⬇️ تغییر ترتیب\n";
    $message .= "↔️ قرارگیری کنار دکمه قبلی | ↩️ شروع ردیف جدید\n";
    $message .= 'در هر ردیف حداکثر دو دکمه قرار می‌گیرد.';
    sendToAdmin($message, buttonsManagerKeyboard($draft));
}

function buttonEditKeyboard(array $draft, int $index): array
{
    $id = $draft['id'];
    return ['inline_keyboard' => [
        [['text' => '✏️ تغییر عنوان', 'callback_data' => "btitle:$id:$index"]],
        [['text' => '🔗 تغییر لینک', 'callback_data' => "burl:$id:$index"]],
        [['text' => '🎨 تغییر رنگ', 'callback_data' => "bpalette:$id:$index"]],
        [['text' => '↩️ بازگشت به مدیریت', 'callback_data' => 'bmanager:' . $id]],
    ]];
}

function buttonColorKeyboard(array $draft, int $index): array
{
    $id = $draft['id'];
    $rows = [];
    foreach ([['success', '🟢 سبز'], ['primary', '🔵 آبی'], ['danger', '🔴 قرمز'], ['default', '⚪ پیش‌فرض']] as [$style, $label]) {
        $rows[] = [['text' => $label, 'callback_data' => "bsetcolor:$id:$index:$style"]];
    }
    $rows[] = [['text' => '↩️ بازگشت', 'callback_data' => 'bmanager:' . $id]];
    return ['inline_keyboard' => $rows];
}

function sendPreview(array $draft): void
{
    sendToAdmin('👁 پیش‌نمایش پست (متن/عکس و همه دکمه‌ها):');
    $request = buildPostRequest($draft, currentUserId());
    $response = tg($request['method'], $request['payload']);
    if (empty($response['ok']) && hasPremiumEmoji($draft)) {
        $fallback = buildPlainPostRequest($draft, currentUserId());
        $response = tg($fallback['method'], $fallback['payload']);
    }
    if (empty($response['ok'])) {
        throw new RuntimeException('Preview failed: ' . telegramError($response));
    }
    $info = 'کانال مقصد: ' . $draft['channel_title'] . ' (' . $draft['channel'] . ")\n";
    $info .= 'نوع پست: ' . (!empty($draft['photo_file_id']) ? '🖼 عکس و کپشن' : '📝 متن') . "\n";
    $info .= 'دکمه‌ها و ترتیب:' . "\n" . layoutDescription($draft) . "\n\n";
    $info .= 'انتشار این پست را تأیید می‌کنی؟';
    sendToAdmin($info, ['inline_keyboard' => [
        [['text' => '✅ تأیید و انتشار', 'callback_data' => 'publish:' . $draft['id'], 'style' => 'success']],
        [['text' => '🛠 ویرایش و مرتب‌سازی دکمه‌ها', 'callback_data' => 'bmanager:' . $draft['id']]],
        [['text' => '❌ لغو', 'callback_data' => 'cancel:' . $draft['id'], 'style' => 'danger']],
    ]]);
}

function normalizeChannel(string $input): ?string
{
    $input = trim($input);
    if (preg_match('/^-100\d{5,20}$/', $input)) {
        return $input;
    }
    if (preg_match('/^@?([a-zA-Z][a-zA-Z0-9_]{4,31})$/', $input, $m)) {
        return '@' . $m[1];
    }
    if (preg_match('~^(?:https?://)?(?:www\.)?t\.me/([a-zA-Z][a-zA-Z0-9_]{4,31})/?(?:\?.*)?$~i', $input, $m)) {
        return '@' . $m[1];
    }
    return null;
}

function normalizeButtonUrl(string $input): ?string
{
    $url = trim($input);
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function utf16Length(string $text): int
{
    if (function_exists('iconv')) {
        $utf16 = @iconv('UTF-8', 'UTF-16LE', $text);
        if ($utf16 !== false) {
            return (int) (strlen($utf16) / 2);
        }
    }
    preg_match_all('/./us', $text, $matches);
    return count($matches[0]);
}

function getValidChannel(string $channel): array
{
    $chat = ensureTelegramSuccess(tg('getChat', ['chat_id' => $channel]));
    if (($chat['type'] ?? '') !== 'channel') {
        throw new RuntimeException('آدرس واردشده مربوط به یک کانال نیست.');
    }
    $me = ensureTelegramSuccess(tg('getMe'));
    $member = ensureTelegramSuccess(tg('getChatMember', [
        'chat_id' => $chat['id'], 'user_id' => $me['id'],
    ]));
    $status = $member['status'] ?? '';
    if ($status !== 'creator' && ($status !== 'administrator' || empty($member['can_post_messages']))) {
        throw new RuntimeException('ربات باید ادمین کانال باشد و دسترسی Post Messages داشته باشد.');
    }
    // A public visitor must also be an authorized publisher of this channel.
    // Checking both parties prevents a random user from abusing a channel where
    // the bot is already an administrator.
    $requester = ensureTelegramSuccess(tg('getChatMember', [
        'chat_id' => $chat['id'], 'user_id' => currentUserId(),
    ]));
    $userStatus = (string) ($requester['status'] ?? '');
    if ($userStatus !== 'creator' && ($userStatus !== 'administrator' || empty($requester['can_post_messages']))) {
        throw new RuntimeException('فقط مالک یا ادمین کانال با مجوز ارسال پست می‌تواند در این کانال منتشر کند.');
    }
    return $chat;
}

function callbackAnswer(string $queryId, string $text = '', bool $alert = false): void
{
    $res = tg('answerCallbackQuery', [
        'callback_query_id' => $queryId,
        'text' => $text,
        'show_alert' => $alert,
    ]);
    if (!$res['ok']) {
        error_log('answerCallbackQuery failed: ' . telegramError($res));
    }
}

function updateConfirmationMessage(array $callback, string $text): void
{
    $message = $callback['message'] ?? null;
    if (!is_array($message) || !isset($message['chat']['id'], $message['message_id'])) {
        return;
    }
    $r = tg('editMessageText', [
        'chat_id' => $message['chat']['id'],
        'message_id' => $message['message_id'],
        'text' => $text,
        'reply_markup' => ['inline_keyboard' => []],
    ]);
    if (!$r['ok']) {
        error_log('editMessageText failed: ' . telegramError($r));
    }
}

/** Archive belongs solely to the current caller's state file. */
function publishedPostIndex(array $state, string $postId): ?int
{
    foreach (($state['published_posts'] ?? []) as $i => $item) {
        if (($item['id'] ?? '') === $postId) return (int) $i;
    }
    return null;
}

/** Generated links for channel posts; private channels can be referenced by -100... ID. */
function channelPostLink(array $post): string
{
    $channel = (string) ($post['channel'] ?? '');
    $message = (int) ($post['message_id'] ?? 0);
    if (str_starts_with($channel, '@')) return 'https://t.me/' . substr($channel, 1) . '/' . $message;
    if (preg_match('/^-100(\d+)$/D', $channel, $m)) {
        return 'https://t.me/c/' . $m[1] . '/' . $message;
    }
    return '';
}

/** Link parsing for OWNER-ONLY old post registration. */
function parseOldPostLink(string $input): ?array
{
    $input = trim($input);
    if (!preg_match('~^https?://t\.me/(?:s/)?(c/[0-9]+|[a-zA-Z][a-zA-Z0-9_]{4,31})/([1-9][0-9]*)(?:[/?#].*)?$~iD', $input, $m)) return null;
    $channel = str_starts_with($m[1], 'c/') ? '-100' . substr($m[1], 2) : '@' . $m[1];
    $msgId = (int) $m[2];
    return $msgId > 0 ? [$channel, $msgId] : null;
}

function postsPageKeyboard(array $posts, int $page = 0): array
{
    $posts = array_reverse(array_values($posts));
    $size = 6;
    $max = max(0, (int) ceil(count($posts) / $size) - 1);
    $page = max(0, min($max, $page));
    $rows = [];
    foreach (array_slice($posts, $page * $size, $size) as $post) {
        $name = mbSafeCut((string) ($post['channel_title'] ?? $post['channel']), 20);
        $when = !empty($post['published_at']) ? date('m/d', (int) $post['published_at']) : '';
        $rows[] = [[
            'text' => '📄 #' . (int) $post['message_id'] . ' | ' . $name . ' | ' . $when,
            'callback_data' => 'posts:open:' . $post['id'],
        ]];
    }
    $nav = [];
    if ($page > 0) $nav[] = ['text' => '⬅️ جدیدتر', 'callback_data' => 'posts:page:' . ($page - 1)];
    if ($page < $max) $nav[] = ['text' => 'قدیمی‌تر ➡️', 'callback_data' => 'posts:page:' . ($page + 1)];
    if ($nav) $rows[] = $nav;
    if (currentUserId() === (int) cfg()['admin_user_id']) {
        $rows[] = [['text' => '➕ ثبت پست قدیمی با لینک (فقط مالک)', 'callback_data' => 'posts:import']];
    }
    $rows[] = [['text' => '↩️ تنظیمات بات', 'callback_data' => 'settings:show']];
    return ['inline_keyboard' => $rows];
}

function showPublishedPosts(int $page = 0): void
{
    $posts = store(static fn (array &$state): array => $state['published_posts'] ?? []);
    sendToAdmin("🗂 آرشیو پست‌های منتشرشده\n\n" .
        'تعداد ثبت‌شده: ' . count($posts) . "\n" .
        "پست موردنظر را برای ویرایش دکمه‌ها انتخاب کن. تغییرات فقط پس از تأیید نهایی در کانال اعمال می‌شوند.\n" .
        'پست‌های قدیمی‌تر از این آپدیت در آرشیو کامل موجود نیستند.',
        postsPageKeyboard($posts, $page));
}

function sendPublishedEditPreview(array $draft): void
{
    $target = $draft['edit_target'] ?? [];
    sendToAdmin('👁 پیش‌نمایش چیدمان جدید دکمه‌ها (فقط در چت خصوصی):', inlineButton($draft));
    $message = "🔧 ویرایش دکمه‌های پست منتشرشده\n\n" .
        'کانال: ' . ($target['channel_title'] ?? $target['channel']) . "\n" .
        'شناسه پست: ' . (int) ($target['message_id'] ?? 0) . "\n\n" .
        "چیدمان جدید:\n" . layoutDescription($draft) . "\n\n" .
        'فقط دکمه‌ها تغییر می‌کنند؛ متن و تصویر اصلی دست‌نخورده باقی می‌مانند.';
    sendToAdmin($message, ['inline_keyboard' => [
        [['text' => '✅ ثبت تغییرات در همان پست', 'callback_data' => 'posts:save:' . $draft['id'], 'style' => 'success']],
        [['text' => '📤 انتشار همین چیدمان در کانال دیگر', 'callback_data' => 'repost:begin:' . $draft['id'], 'style' => 'primary']],
        [['text' => '🛠 بازگشت به ویرایش دکمه‌ها', 'callback_data' => 'bmanager:' . $draft['id']]],
        [['text' => '❌ انصراف بدون ذخیره', 'callback_data' => 'posts:abort:' . $draft['id']]],
    ]]);
}

/** Copy the existing channel message without attribution, with the edited buttons. */
function buildRepostRequest(array $draft, int|string $destination): array
{
    $origin = $draft['edit_target'] ?? null;
    if (!is_array($origin) || empty($origin['channel']) || (int) ($origin['message_id'] ?? 0) < 1) {
        throw new RuntimeException('اطلاعات پست مبدا در آرشیو ناقص است.');
    }
    $payload = [
        'chat_id' => $destination,
        'from_chat_id' => (string) $origin['channel'],
        'message_id' => (int) $origin['message_id'],
    ];
    if (count(draftButtons($draft)) > 0) $payload['reply_markup'] = inlineButton($draft);
    return ['method' => 'copyMessage', 'payload' => $payload];
}

function sendRepostPreview(array $draft): void
{
    $source = $draft['edit_target'];
    $destination = $draft['repost_target'];
    sendToAdmin('👁 پیش‌نمایش نسخه جدید پست (بدون Forward یا via):');
    try {
        $request = buildRepostRequest($draft, currentUserId());
        $res = tg($request['method'], $request['payload']);
        if (empty($res['ok']) && hasButtonCustomEmoji($draft)) {
            $plain = $draft;
            foreach ($plain['buttons'] as &$b) $b['emoji_id'] = '';
            unset($b);
            $request = buildRepostRequest($plain, currentUserId());
            $res = tg($request['method'], $request['payload']);
        }
        if (empty($res['ok'])) {
            sendToAdmin('⚠️ پیش‌نمایش محتوا از کانال مبدا قابل کپی نبود: ' . telegramError($res) .
                "\nممکن است ارسال به کانال مقصد هم توسط تلگرام رد شود.");
        }
    } catch (Throwable $e) {
        sendToAdmin('⚠️ پیش‌نمایش رسانه از کانال مبدا در دسترس نبود: ' . $e->getMessage());
    }
    sendToAdmin(
        '📤 انتشار مجدد پست #' . (int) $source['message_id'] . "\n" .
        'مبدا: ' . ($source['channel_title'] ?? $source['channel']) . "\n" .
        'مقصد: ' . $destination['channel_title'] . "\n\n" .
        "دکمه‌های نسخه جدید:\n" . (layoutDescription($draft) ?: 'بدون دکمه') . "\n\n" .
        'این عملیات پیام جدیدی در کانال مقصد ایجاد می‌کند و پست مبدا را تغییر نمی‌دهد.',
        ['inline_keyboard' => [
            [['text' => '✅ تأیید انتشار در کانال جدید', 'callback_data' => 'repost:confirm:' . $draft['id'], 'style' => 'success']],
            [['text' => '🛠 بازگشت به ویرایش دکمه‌ها', 'callback_data' => 'repost:back:' . $draft['id']]],
            [['text' => '❌ انصراف', 'callback_data' => 'repost:cancel:' . $draft['id'], 'style' => 'danger']],
        ]]
    );
}

function showSettings(): void
{
    $settings = store(fn (array &$state): array => $state['settings']);
    $url = $settings['button_url'] ?: 'تنظیم نشده (نمایش پیام کوتاه هنگام کلیک)';
    sendToAdmin('⚙️ تنظیمات بات' . "\n\n" .
        'رنگ فعلی: ' . styleLabel($settings['style']) . "\n" .
        'لینک فعلی دکمه: ' . $url . "\n\n" .
        'رنگ و لینک پیش‌فرض مخصوص پست‌های جدید هستند؛ دکمه‌های پست‌های قبلی را از آرشیو ویرایش کن.', settingsKeyboard());
}

function isAdminMessage(array $message): bool
{
    return (string) ($message['from']['id'] ?? '') === (string) cfg()['admin_user_id']
        && ($message['chat']['type'] ?? '') === 'private';
}
