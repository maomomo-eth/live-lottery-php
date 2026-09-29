<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/app.php';

applySecurityHeaders();
ensureStorage();
startAdminSession();

$error = null;
$success = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'install' && !isInstalled()) {
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (!hash_equals($password, $confirmation)) {
                throw new LotteryException('两次输入的密码不一致。');
            }
            installAdmin($password);
            loginAdmin();
            $success = '初始化完成，可以创建第一场抽奖了。';
        } elseif ($action === 'login' && isInstalled()) {
            if (!verifyAdminPassword((string) ($_POST['password'] ?? ''))) {
                usleep(350000);
                throw new LotteryException('管理员密码不正确。', 401);
            }
            loginAdmin();
            $success = '登录成功。';
        } elseif ($action === 'logout' && isAdminLoggedIn()) {
            verifyCsrf((string) ($_POST['csrf_token'] ?? ''));
            logoutAdmin();
            header('Location: admin.php');
            exit;
        } elseif (isAdminLoggedIn()) {
            verifyCsrf((string) ($_POST['csrf_token'] ?? ''));
            if ($action === 'create') {
                $event = createEvent((int) ($_POST['total'] ?? 0));
                $success = '新场次已创建并允许开抽。';
            } elseif ($action === 'status') {
                $status = (string) ($_POST['status'] ?? '');
                setEventStatus((string) ($_POST['event_id'] ?? ''), $status);
                $success = match ($status) {
                    'open' => '已允许参与者抽奖。',
                    'paused' => '抽奖已暂停。',
                    default => '抽奖已结束。',
                };
            } else {
                throw new LotteryException('操作无效。');
            }
        } else {
            throw new LotteryException('请先登录管理员后台。', 401);
        }
    }
} catch (LotteryException $exception) {
    http_response_code($exception->httpStatus);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    $error = '服务器发生错误，请检查 storage 目录权限。';
}

$installed = isInstalled();
$loggedIn = $installed && isAdminLoggedIn();
$event = $loggedIn ? getLatestEvent() : null;
$draws = $event !== null && is_array($event['draws'] ?? null) ? array_reverse($event['draws']) : [];
$eventUrl = $event !== null ? participantUrl((string) $event['id']) : '';
$winnerCount = $event !== null
    ? count(array_filter($event['draws'] ?? [], static fn (array $draw): bool => ($draw['prize_type'] ?? 'none') !== 'none'))
    : 0;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#08111f">
    <title>现场抽签管理后台</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="admin-page">
<header class="admin-header">
    <div>
        <div class="eyebrow">LIVE LOTTERY</div>
        <h1>现场抽签管理台</h1>
    </div>
    <?php if ($loggedIn): ?>
        <form method="post">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <button class="text-button" type="submit">退出登录</button>
        </form>
    <?php endif; ?>
</header>

<main class="admin-main">
    <?php if ($error !== null): ?>
        <div class="notice notice-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($success !== null): ?>
        <div class="notice notice-success" role="status"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if (!$installed): ?>
        <section class="panel auth-panel">
            <div class="panel-heading">
                <span class="step-number">01</span>
                <div><h2>首次初始化</h2><p>设置后台管理员密码，至少 8 个字符。</p></div>
            </div>
            <form method="post" class="stack-form">
                <input type="hidden" name="action" value="install">
                <label for="password">管理员密码</label>
                <input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                <label for="password-confirmation">再次输入密码</label>
                <input id="password-confirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
                <button class="primary-button" type="submit">完成初始化</button>
            </form>
        </section>
    <?php elseif (!$loggedIn): ?>
        <section class="panel auth-panel">
            <div class="panel-heading">
                <span class="step-number">↗</span>
                <div><h2>管理员登录</h2><p>登录后创建、暂停或结束抽奖。</p></div>
            </div>
            <form method="post" class="stack-form">
                <input type="hidden" name="action" value="login">
                <label for="login-password">管理员密码</label>
                <input id="login-password" name="password" type="password" autocomplete="current-password" autofocus required>
                <button class="primary-button" type="submit">进入后台</button>
            </form>
        </section>
    <?php else: ?>
        <section class="admin-grid">
            <div class="panel create-panel">
                <div class="panel-heading">
                    <span class="step-number">01</span>
                    <div><h2>创建新场次</h2><p>新场次创建后立即允许扫码开抽。</p></div>
                </div>
                <form method="post" class="create-form">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <label for="total">现场总人数</label>
                    <div class="input-with-unit">
                        <input id="total" name="total" type="number" min="3" max="500" value="12" inputmode="numeric" required>
                        <span>人</span>
                    </div>
                    <div class="fixed-prizes">
                        <div><span class="prize-dot onekey-dot"></span>OneKey 钱包 <strong>×1</strong></div>
                        <div><span class="prize-dot okx-dot"></span>Q总赞助帽子 <strong>×2</strong></div>
                    </div>
                    <button class="primary-button" type="submit">创建并允许开抽</button>
                </form>
            </div>

            <?php if ($event !== null): ?>
                <div class="panel share-panel">
                    <div class="panel-heading compact">
                        <span class="step-number">02</span>
                        <div><h2>分享现场二维码</h2><p>参与者扫码后输入姓名开抽。</p></div>
                    </div>
                    <div id="qr-code" class="qr-code" data-url="<?= h($eventUrl) ?>"></div>
                    <div class="share-url-row">
                        <input id="share-url" type="text" readonly value="<?= h($eventUrl) ?>" aria-label="参与者链接">
                        <button id="copy-url" class="secondary-button" type="button">复制</button>
                    </div>
                </div>
            <?php else: ?>
                <div class="panel empty-panel"><p>创建场次后，这里会显示二维码和随机链接。</p></div>
            <?php endif; ?>
        </section>

        <?php if ($event !== null): ?>
            <section class="panel event-panel">
                <div class="event-toolbar">
                    <div>
                        <div class="eyebrow">CURRENT SESSION</div>
                        <h2>当前场次</h2>
                    </div>
                    <span class="event-status status-<?= h((string) $event['status']) ?>">
                        <?= h(match ($event['status']) { 'open' => '允许开抽', 'paused' => '已暂停', 'finished' => '名额已抽完', default => '已结束' }) ?>
                    </span>
                </div>

                <div class="stat-grid">
                    <div class="stat-card"><span>总人数</span><strong><?= h((int) $event['total']) ?></strong></div>
                    <div class="stat-card"><span>已抽人数</span><strong><?= h(count($event['draws'])) ?></strong></div>
                    <div class="stat-card"><span>剩余名额</span><strong><?= h(max(0, (int) $event['total'] - count($event['draws']))) ?></strong></div>
                    <div class="stat-card"><span>已中奖</span><strong><?= h($winnerCount) ?><small>/3</small></strong></div>
                </div>

                <div class="status-actions">
                    <?php foreach ([
                        'open' => '允许开抽',
                        'paused' => '暂停抽奖',
                        'closed' => '结束本场',
                    ] as $status => $label): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="event_id" value="<?= h((string) $event['id']) ?>">
                            <input type="hidden" name="status" value="<?= h($status) ?>">
                            <button class="<?= $status === 'open' ? 'primary-button small-button' : 'secondary-button small-button' ?>" type="submit" <?= $event['status'] === 'finished' ? 'disabled' : '' ?>><?= h($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                    <button class="secondary-button small-button" type="button" data-refresh-page>刷新记录</button>
                </div>

                <div class="records-heading">
                    <h3>抽奖记录</h3>
                    <span>按最新时间排序</span>
                </div>
                <?php if ($draws === []): ?>
                    <div class="records-empty">还没有人参与抽奖</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>序号</th><th>参与者</th><th>结果</th><th>时间</th></tr></thead>
                            <tbody>
                            <?php foreach ($draws as $draw): ?>
                                <tr>
                                    <td>#<?= h((int) $draw['draw_no']) ?></td>
                                    <td><?= h((string) $draw['name']) ?></td>
                                    <td><span class="result-tag result-<?= h((string) $draw['prize_type']) ?>"><?= h((string) $draw['prize_title']) ?></span></td>
                                    <td><?= h(date('H:i:s', strtotime((string) $draw['drawn_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($loggedIn && $event !== null): ?>
    <script src="assets/qrcodegen-v1.8.0-es5.js" defer></script>
    <script src="assets/admin.js" defer></script>
<?php endif; ?>
</body>
</html>
