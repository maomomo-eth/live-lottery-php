<?php

declare(strict_types=1);

const LOTTERY_FILE_PREFIX = "<?php exit; ?>\n";
const LOTTERY_MAX_PARTICIPANTS = 500;

date_default_timezone_set(getenv('LOTTERY_TIMEZONE') ?: 'Asia/Shanghai');

final class LotteryException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}

function storageDir(): string
{
    $customDir = getenv('LOTTERY_STORAGE_DIR');

    return $customDir !== false && $customDir !== ''
        ? rtrim($customDir, DIRECTORY_SEPARATOR)
        : dirname(__DIR__) . '/storage';
}

function ensureStorage(): void
{
    $dir = storageDir();
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建 storage 目录。');
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('storage 目录不可写，请检查目录权限。');
    }
}

function settingsPath(): string
{
    return storageDir() . '/settings.php';
}

function eventPath(string $eventId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $eventId)) {
        throw new LotteryException('抽奖链接无效。', 404);
    }

    return storageDir() . '/event-' . $eventId . '.php';
}

/**
 * @template T
 * @param callable(array<string, mixed>|null): array{0: array<string, mixed>, 1: T} $callback
 * @return T
 */
function mutateEvent(string $eventId, callable $callback): mixed
{
    $path = eventPath($eventId);
    if (!is_file($path)) {
        throw new LotteryException('抽奖场次不存在。', 404);
    }

    return mutateProtectedJson($path, $callback);
}

function nowIso(): string
{
    return gmdate('c');
}

/** @return array<string, mixed>|null */
function readProtectedJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('无法读取数据文件。');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('无法锁定数据文件。');
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if ($contents === false || !str_starts_with($contents, LOTTERY_FILE_PREFIX)) {
        throw new RuntimeException('数据文件格式错误。');
    }

    $data = json_decode(substr($contents, strlen(LOTTERY_FILE_PREFIX)), true);
    if (!is_array($data)) {
        throw new RuntimeException('数据文件内容损坏。');
    }

    return $data;
}

/**
 * 在文件独占锁内读取并更新 JSON。
 *
 * @template T
 * @param callable(array<string, mixed>|null): array{0: array<string, mixed>, 1: T} $callback
 * @return T
 */
function mutateProtectedJson(string $path, callable $callback): mixed
{
    ensureStorage();
    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('无法打开数据文件。');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('无法锁定数据文件。');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $current = null;
        if (is_string($contents) && $contents !== '') {
            if (!str_starts_with($contents, LOTTERY_FILE_PREFIX)) {
                throw new RuntimeException('数据文件格式错误。');
            }
            $decoded = json_decode(substr($contents, strlen(LOTTERY_FILE_PREFIX)), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('数据文件内容损坏。');
            }
            $current = $decoded;
        }

        [$next, $result] = $callback($current);
        $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException('无法编码数据。');
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, LOTTERY_FILE_PREFIX . $encoded . "\n") === false) {
            throw new RuntimeException('无法写入数据文件。');
        }
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function isInstalled(): bool
{
    return is_file(settingsPath());
}

function installAdmin(string $password): void
{
    if (strlen($password) < 8) {
        throw new LotteryException('管理员密码至少需要 8 个字符。');
    }

    mutateProtectedJson(settingsPath(), static function (?array $current) use ($password): array {
        if ($current !== null) {
            throw new LotteryException('管理员已经初始化。', 409);
        }

        $settings = [
            'version' => 1,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'latest_event_id' => null,
            'created_at' => nowIso(),
        ];

        return [$settings, null];
    });
}

/** @return array<string, mixed> */
function getSettings(): array
{
    $settings = readProtectedJson(settingsPath());
    if ($settings === null) {
        throw new LotteryException('系统尚未初始化。', 503);
    }

    return $settings;
}

function verifyAdminPassword(string $password): bool
{
    $settings = getSettings();

    return isset($settings['password_hash'])
        && is_string($settings['password_hash'])
        && password_verify($password, $settings['password_hash']);
}

function updateLatestEvent(string $eventId): void
{
    mutateProtectedJson(settingsPath(), static function (?array $settings) use ($eventId): array {
        if ($settings === null) {
            throw new LotteryException('系统尚未初始化。', 503);
        }
        $settings['latest_event_id'] = $eventId;

        return [$settings, null];
    });
}

/** @return list<array{type: string, title: string}> */
function buildPrizePool(int $total): array
{
    if ($total < 3 || $total > LOTTERY_MAX_PARTICIPANTS) {
        throw new LotteryException('总人数必须在 3 到 ' . LOTTERY_MAX_PARTICIPANTS . ' 之间。');
    }

    $pool = [
        ['type' => 'onekey', 'title' => 'OneKey 钱包'],
        ['type' => 'okx_hat', 'title' => 'Q总赞助帽子'],
        ['type' => 'okx_hat', 'title' => 'Q总赞助帽子'],
    ];

    while (count($pool) < $total) {
        $pool[] = ['type' => 'none', 'title' => '谢谢参与'];
    }

    return $pool;
}

function canonicalPrizeTitle(string $type, string $fallback = ''): string
{
    return match ($type) {
        'onekey' => 'OneKey 钱包',
        'okx_hat' => 'Q总赞助帽子',
        'none' => '谢谢参与',
        default => $fallback !== '' ? $fallback : '未知奖品',
    };
}

/** @return array<string, mixed> */
function createEvent(int $total): array
{
    $pool = buildPrizePool($total);
    ensureStorage();

    do {
        $eventId = bin2hex(random_bytes(16));
        $path = eventPath($eventId);
    } while (is_file($path));

    $event = [
        'version' => 1,
        'id' => $eventId,
        'status' => 'open',
        'total' => $total,
        'pool' => $pool,
        'draws' => [],
        'created_at' => nowIso(),
        'updated_at' => nowIso(),
    ];

    mutateProtectedJson($path, static function (?array $current) use ($event): array {
        if ($current !== null) {
            throw new RuntimeException('抽奖场次 ID 冲突，请重试。');
        }

        return [$event, null];
    });
    updateLatestEvent($eventId);

    return $event;
}

/** @return array<string, mixed> */
function getEvent(string $eventId): array
{
    $event = readProtectedJson(eventPath($eventId));
    if ($event === null) {
        throw new LotteryException('抽奖场次不存在。', 404);
    }

    return $event;
}

/** @return array<string, mixed>|null */
function getLatestEvent(): ?array
{
    if (!isInstalled()) {
        return null;
    }

    $settings = getSettings();
    $eventId = $settings['latest_event_id'] ?? null;
    if (!is_string($eventId) || $eventId === '') {
        return null;
    }

    try {
        return getEvent($eventId);
    } catch (LotteryException $exception) {
        if ($exception->httpStatus === 404) {
            return null;
        }
        throw $exception;
    }
}

/** @return array<string, mixed> */
function setEventStatus(string $eventId, string $status): array
{
    if (!in_array($status, ['open', 'paused', 'closed'], true)) {
        throw new LotteryException('场次状态无效。');
    }

    return mutateEvent($eventId, static function (?array $event) use ($status): array {
        if ($event === null) {
            throw new LotteryException('抽奖场次不存在。', 404);
        }
        if (count($event['draws'] ?? []) >= (int) ($event['total'] ?? 0)) {
            $event['status'] = 'finished';
        } else {
            $event['status'] = $status;
        }
        $event['updated_at'] = nowIso();

        return [$event, $event];
    });
}

function normalizeParticipantName(string $name): string
{
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
    if ($name === '') {
        throw new LotteryException('请输入姓名或现场昵称。');
    }
    if (utf8Length($name) > 30) {
        throw new LotteryException('姓名或昵称不能超过 30 个字符。');
    }

    return $name;
}

function normalizeNameForCompare(string $name): string
{
    $name = trim($name);

    return function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);
}

function utf8Length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value);

    return $count === false ? strlen($value) : $count;
}

function logAppError(Throwable $exception): string
{
    try {
        $errorId = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $errorId = substr(hash('sha256', uniqid('', true)), 0, 8);
    }

    $line = sprintf(
        "[%s] [%s] %s: %s in %s:%d\n",
        date('Y-m-d H:i:s'),
        $errorId,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    error_log(trim($line));
    @file_put_contents(storageDir() . '/error.log', $line, FILE_APPEND | LOCK_EX);

    return $errorId;
}

/** @return array<string, mixed> */
function drawPrize(string $eventId, string $participantToken, string $rawName): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $participantToken)) {
        throw new LotteryException('参与者凭证无效，请刷新页面重试。');
    }

    $name = normalizeParticipantName($rawName);
    $tokenHash = hash('sha256', $participantToken);

    return mutateEvent($eventId, static function (?array $event) use ($tokenHash, $name): array {
        if ($event === null) {
            throw new LotteryException('抽奖场次不存在。', 404);
        }

        $draws = is_array($event['draws'] ?? null) ? $event['draws'] : [];
        foreach ($draws as $draw) {
            if (($draw['participant_hash'] ?? '') === $tokenHash) {
                $draw['repeated'] = true;

                return [$event, $draw];
            }
        }

        if (($event['status'] ?? '') !== 'open') {
            $messages = [
                'paused' => '管理员暂时停止了抽奖，请稍候。',
                'closed' => '本场抽奖已经结束。',
                'finished' => '所有参与名额已经抽完。',
            ];
            throw new LotteryException($messages[$event['status'] ?? ''] ?? '当前不能抽奖。', 409);
        }

        foreach ($draws as $draw) {
            if (normalizeNameForCompare((string) ($draw['name'] ?? '')) === normalizeNameForCompare($name)) {
                throw new LotteryException('这个姓名已经抽过，请联系现场管理员核对。', 409);
            }
        }

        $pool = is_array($event['pool'] ?? null) ? array_values($event['pool']) : [];
        if ($pool === []) {
            $event['status'] = 'finished';
            $event['updated_at'] = nowIso();
            throw new LotteryException('所有参与名额已经抽完。', 409);
        }

        $index = random_int(0, count($pool) - 1);
        $prize = $pool[$index];
        array_splice($pool, $index, 1);

        $prizeType = (string) ($prize['type'] ?? 'none');
        $draw = [
            'draw_no' => count($draws) + 1,
            'participant_hash' => $tokenHash,
            'name' => $name,
            'prize_type' => $prizeType,
            'prize_title' => canonicalPrizeTitle($prizeType, (string) ($prize['title'] ?? '')),
            'drawn_at' => nowIso(),
            'repeated' => false,
        ];
        $draws[] = $draw;
        $event['pool'] = $pool;
        $event['draws'] = $draws;
        $event['updated_at'] = nowIso();
        if ($pool === []) {
            $event['status'] = 'finished';
        }

        return [$event, $draw];
    });
}

/** @return array<string, mixed> */
function publicEventState(array $event, ?string $participantToken = null): array
{
    $draws = is_array($event['draws'] ?? null) ? $event['draws'] : [];
    $state = [
        'id' => (string) ($event['id'] ?? ''),
        'status' => (string) ($event['status'] ?? 'closed'),
        'total' => (int) ($event['total'] ?? 0),
        'drawn' => count($draws),
        'remaining' => max(0, (int) ($event['total'] ?? 0) - count($draws)),
        'my_result' => null,
    ];

    if ($participantToken !== null && preg_match('/^[a-f0-9]{32}$/', $participantToken)) {
        $tokenHash = hash('sha256', $participantToken);
        foreach ($draws as $draw) {
            if (($draw['participant_hash'] ?? '') === $tokenHash) {
                $state['my_result'] = [
                    'draw_no' => (int) ($draw['draw_no'] ?? 0),
                    'name' => (string) ($draw['name'] ?? ''),
                    'prize_type' => (string) ($draw['prize_type'] ?? 'none'),
                    'prize_title' => canonicalPrizeTitle(
                        (string) ($draw['prize_type'] ?? 'none'),
                        (string) ($draw['prize_title'] ?? '')
                    ),
                    'drawn_at' => (string) ($draw['drawn_at'] ?? ''),
                ];
                break;
            }
        }
    }

    return $state;
}

function isHttpsRequest(): bool
{
    if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function appBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(dirname($scriptName), '/.');

    return $dir === '' ? '' : $dir;
}

function appBaseUrl(): string
{
    $configuredUrl = getenv('LOTTERY_BASE_URL');
    if ($configuredUrl !== false && filter_var($configuredUrl, FILTER_VALIDATE_URL) !== false) {
        return rtrim($configuredUrl, '/');
    }

    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }

    return $scheme . '://' . $host . appBasePath();
}

function participantUrl(string $eventId): string
{
    return appBaseUrl() . '/index.php?event=' . rawurlencode($eventId);
}

function participantCookieName(): string
{
    return 'live_lottery_participant';
}

function getOrCreateParticipantToken(): string
{
    $name = participantCookieName();
    $existing = (string) ($_COOKIE[$name] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $existing)) {
        return $existing;
    }

    $token = bin2hex(random_bytes(16));
    setcookie($name, $token, [
        'expires' => time() + 86400 * 30,
        'path' => appBasePath() . '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $token;

    return $token;
}

function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('live_lottery_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => appBasePath() . '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isAdminLoggedIn(): bool
{
    return ($_SESSION['admin_authenticated'] ?? false) === true;
}

function loginAdmin(): void
{
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

function logoutAdmin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        throw new LotteryException('页面已过期，请刷新后重试。', 403);
    }
}

function applySecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store, max-age=0');
}

function h(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
