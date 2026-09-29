<?php

declare(strict_types=1);

$testDir = __DIR__ . '/runtime-' . bin2hex(random_bytes(5));
putenv('LOTTERY_STORAGE_DIR=' . $testDir);

require dirname(__DIR__) . '/src/app.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('断言失败：' . $message);
    }
}

function removeTestFiles(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($dir);
}

try {
    installAdmin('test-password-123');
    assertTrue(isInstalled(), '应完成管理员初始化');
    assertTrue(verifyAdminPassword('test-password-123'), '正确密码应通过验证');
    assertTrue(!verifyAdminPassword('wrong-password'), '错误密码不应通过验证');
    assertTrue(utf8Length('中文ABC') === 5, 'UTF-8 字符长度应计算正确');

    $event = createEvent(12);
    assertTrue($event['status'] === 'open', '新场次应立即开放');
    assertTrue(count($event['pool']) === 12, '奖池数量应等于参与人数');

    $results = [];
    for ($i = 1; $i <= 12; $i++) {
        $token = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
        $results[] = drawPrize($event['id'], $token, '参与者' . $i);
    }

    $onekeyCount = count(array_filter($results, static fn (array $draw): bool => $draw['prize_type'] === 'onekey'));
    $hatCount = count(array_filter($results, static fn (array $draw): bool => $draw['prize_type'] === 'okx_hat'));
    $noneCount = count(array_filter($results, static fn (array $draw): bool => $draw['prize_type'] === 'none'));
    assertTrue($onekeyCount === 1, 'OneKey 钱包必须恰好抽出 1 个');
    assertTrue($hatCount === 2, 'Q总赞助帽子必须恰好抽出 2 个');
    assertTrue($noneCount === 9, '未中奖结果必须恰好 9 个');
    foreach ($results as $result) {
        if ($result['prize_type'] === 'okx_hat') {
            assertTrue($result['prize_title'] === 'Q总赞助帽子', '帽子奖品必须使用最新名称');
        }
    }

    $finished = getEvent($event['id']);
    assertTrue($finished['status'] === 'finished', '名额抽完后场次应自动结束');
    assertTrue(count($finished['pool']) === 0, '奖池应为空');

    $repeat = drawPrize($event['id'], str_pad('1', 32, '0', STR_PAD_LEFT), '另一个名字');
    assertTrue($repeat['draw_no'] === 1 && $repeat['repeated'] === true, '同一浏览器重复提交应返回原结果');

    $duplicateEvent = createEvent(3);
    drawPrize($duplicateEvent['id'], str_repeat('a', 32), '小明');
    try {
        drawPrize($duplicateEvent['id'], str_repeat('b', 32), '小明');
        throw new RuntimeException('同名参与者应被拒绝');
    } catch (LotteryException $exception) {
        assertTrue($exception->httpStatus === 409, '同名参与应返回冲突状态');
    }

    $missingId = str_repeat('f', 32);
    try {
        drawPrize($missingId, str_repeat('c', 32), '不存在场次');
        throw new RuntimeException('不存在的场次应被拒绝');
    } catch (LotteryException $exception) {
        assertTrue($exception->httpStatus === 404, '不存在场次应返回 404');
        assertTrue(!is_file(eventPath($missingId)), '错误请求不应创建空数据文件');
    }

    if (function_exists('pcntl_fork')) {
        $concurrentEvent = createEvent(12);
        $children = [];
        for ($i = 1; $i <= 16; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    $token = str_pad(dechex(100 + $i), 32, '0', STR_PAD_LEFT);
                    drawPrize($concurrentEvent['id'], $token, '并发参与者' . $i);
                    exit(0);
                } catch (LotteryException $exception) {
                    exit($exception->httpStatus === 409 ? 0 : 2);
                }
            }
            if ($pid < 0) {
                throw new RuntimeException('无法创建并发测试进程');
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            assertTrue(pcntl_wexitstatus($status) === 0, '并发抽奖子进程应正常结束');
        }
        $concurrentResult = getEvent($concurrentEvent['id']);
        assertTrue(count($concurrentResult['draws']) === 12, '并发请求不能超过总人数');
        assertTrue(count($concurrentResult['pool']) === 0, '并发抽取后奖池数量必须正确');
    }

    echo "全部测试通过。\n";
} finally {
    removeTestFiles($testDir);
}
