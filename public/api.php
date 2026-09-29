<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/app.php';

applySecurityHeaders();
ensureStorage();

try {
    $action = (string) ($_GET['action'] ?? '');
    $eventId = strtolower(trim((string) ($_GET['event'] ?? '')));
    $participantToken = getOrCreateParticipantToken();

    if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $event = getEvent($eventId);
        jsonResponse(['ok' => true, 'event' => publicEventState($event, $participantToken)]);
    }

    if ($action === 'draw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw !== false ? $raw : '', true);
        if (!is_array($payload)) {
            throw new LotteryException('请求内容无效。');
        }

        $result = drawPrize($eventId, $participantToken, (string) ($payload['name'] ?? ''));
        jsonResponse([
            'ok' => true,
            'result' => [
                'draw_no' => (int) $result['draw_no'],
                'name' => (string) $result['name'],
                'prize_type' => (string) $result['prize_type'],
                'prize_title' => (string) $result['prize_title'],
                'drawn_at' => (string) $result['drawn_at'],
                'repeated' => (bool) ($result['repeated'] ?? false),
            ],
        ]);
    }

    throw new LotteryException('接口不存在。', 404);
} catch (LotteryException $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], $exception->httpStatus);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    jsonResponse(['ok' => false, 'message' => '服务器暂时无法处理请求，请稍后重试。'], 500);
}
