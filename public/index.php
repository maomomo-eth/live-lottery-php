<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/app.php';

applySecurityHeaders();
ensureStorage();

$eventId = strtolower(trim((string) ($_GET['event'] ?? '')));
$event = null;
$error = null;
$participantToken = getOrCreateParticipantToken();

if ($eventId === '') {
    $error = '缺少抽奖场次，请扫描现场二维码进入。';
} else {
    try {
        $event = getEvent($eventId);
    } catch (LotteryException $exception) {
        http_response_code($exception->httpStatus);
        $error = $exception->getMessage();
    }
}

$initialState = $event !== null ? publicEventState($event, $participantToken) : null;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#08111f">
    <title>现场幸运抽签</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="participant-page">
<main class="draw-shell">
    <section class="hero-card">
        <div class="eyebrow">LIVE LUCKY DRAW</div>
        <h1>现场幸运大转盘</h1>
        <p class="hero-copy">转动好运，看看今天的惊喜属于谁</p>

        <?php if ($error !== null): ?>
            <div class="notice notice-error" role="alert"><?= h($error) ?></div>
        <?php else: ?>
            <div
                id="draw-app"
                class="draw-app"
                data-event-id="<?= h($eventId) ?>"
                data-api-url="api.php"
                data-initial-state="<?= h(json_encode($initialState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
            >
                <div class="wheel-stage" aria-label="抽奖转盘">
                    <div class="wheel-pointer" aria-hidden="true"></div>
                    <div id="prize-wheel" class="prize-wheel">
                        <canvas id="wheel-canvas" width="640" height="640">你的浏览器不支持转盘画布。</canvas>
                    </div>
                    <div class="wheel-center" aria-hidden="true">
                        <span>LUCKY</span>
                        <strong>GO</strong>
                    </div>
                </div>

                <div class="prize-strip" aria-label="本场奖品">
                    <div><span>1×</span> OneKey 钱包</div>
                    <div><span>2×</span> Q总赞助帽子</div>
                </div>

                <div id="status-pill" class="status-pill">正在读取场次状态…</div>

                <form id="draw-form" class="draw-form" autocomplete="off">
                    <label for="participant-name">姓名或现场昵称</label>
                    <input
                        id="participant-name"
                        name="name"
                        type="text"
                        maxlength="30"
                        placeholder="请输入你的名字"
                        autocomplete="name"
                        required
                    >
                    <button id="draw-button" class="primary-button draw-button" type="submit">
                        <span class="button-default">开始转动</span>
                        <span class="button-loading" hidden>转盘旋转中…</span>
                    </button>
                </form>

                <div id="draw-result" class="draw-result" hidden aria-live="polite">
                    <div id="result-icon" class="result-icon">✦</div>
                    <div id="result-kicker" class="result-kicker"></div>
                    <h2 id="result-title"></h2>
                    <p id="result-message"></p>
                    <div id="result-number" class="result-number"></div>
                </div>

                <p id="draw-error" class="form-error" role="alert" hidden></p>
                <p class="rule-note">每台设备限抽一次，以服务器记录为准</p>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php if ($event !== null): ?>
    <script src="assets/draw.js" defer></script>
<?php endif; ?>
</body>
</html>
