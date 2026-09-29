(() => {
    'use strict';

    const app = document.querySelector('#draw-app');
    if (!app) return;

    const eventId = app.dataset.eventId;
    const apiUrl = app.dataset.apiUrl;
    const form = document.querySelector('#draw-form');
    const nameInput = document.querySelector('#participant-name');
    const drawButton = document.querySelector('#draw-button');
    const statusPill = document.querySelector('#status-pill');
    const errorBox = document.querySelector('#draw-error');
    const resultBox = document.querySelector('#draw-result');
    const resultIcon = document.querySelector('#result-icon');
    const resultKicker = document.querySelector('#result-kicker');
    const resultTitle = document.querySelector('#result-title');
    const resultMessage = document.querySelector('#result-message');
    const resultNumber = document.querySelector('#result-number');
    const defaultLabel = drawButton.querySelector('.button-default');
    const loadingLabel = drawButton.querySelector('.button-loading');
    let isDrawing = false;
    let hasResult = false;

    const statusText = {
        open: '抽奖进行中',
        paused: '抽奖暂时停下，请等待管理员开放',
        closed: '本场抽奖已经结束',
        finished: '所有参与名额已经抽完',
    };

    function setStatus(state) {
        statusPill.textContent = `${statusText[state.status] || '正在等待'} · 剩余 ${state.remaining} 个名额`;
        statusPill.className = `status-pill status-pill-${state.status}`;
        if (!hasResult) {
            drawButton.disabled = state.status !== 'open';
            nameInput.disabled = state.status !== 'open';
        }
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = false;
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = '';
    }

    function sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function revealResult(result, immediate = false) {
        hasResult = true;
        form.hidden = true;
        resultBox.hidden = false;
        resultBox.className = `draw-result result-${result.prize_type}${immediate ? '' : ' result-enter'}`;
        resultNumber.textContent = `你的抽签序号：#${result.draw_no}`;

        if (result.prize_type === 'onekey') {
            resultIcon.textContent = '◈';
            resultKicker.textContent = '压轴好运降临';
            resultTitle.textContent = 'OneKey 钱包';
            resultMessage.textContent = `${result.name}，恭喜你抽中大奖！请向现场工作人员领取。`;
        } else if (result.prize_type === 'okx_hat') {
            resultIcon.textContent = '✦';
            resultKicker.textContent = '恭喜中奖';
            resultTitle.textContent = 'OKX 帽子';
            resultMessage.textContent = `${result.name}，好运被你抽中了！请向现场工作人员领取。`;
        } else {
            resultIcon.textContent = '☻';
            resultKicker.textContent = '感谢参与';
            resultTitle.textContent = '这次差一点点';
            resultMessage.textContent = `${result.name}，谢谢你的参与，祝你今天依然好运！`;
        }
    }

    async function refreshStatus() {
        try {
            const response = await fetch(`${apiUrl}?action=status&event=${encodeURIComponent(eventId)}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || '无法获取场次状态。');
            setStatus(payload.event);
            if (payload.event.my_result) revealResult(payload.event.my_result, true);
        } catch (error) {
            showError(error instanceof Error ? error.message : '网络异常，请刷新页面重试。');
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (isDrawing || hasResult) return;

        const name = nameInput.value.trim();
        if (!name) {
            showError('请输入姓名或现场昵称。');
            nameInput.focus();
            return;
        }

        clearError();
        isDrawing = true;
        drawButton.disabled = true;
        nameInput.disabled = true;
        defaultLabel.hidden = true;
        loadingLabel.hidden = false;
        drawButton.classList.add('is-drawing');

        try {
            const [response] = await Promise.all([
                fetch(`${apiUrl}?action=draw&event=${encodeURIComponent(eventId)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ name }),
                }),
                sleep(1500),
            ]);
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || '抽奖失败，请重试。');
            revealResult(payload.result);
            await refreshStatus();
        } catch (error) {
            showError(error instanceof Error ? error.message : '网络异常，请重试。');
            drawButton.disabled = false;
            nameInput.disabled = false;
        } finally {
            isDrawing = false;
            defaultLabel.hidden = false;
            loadingLabel.hidden = true;
            drawButton.classList.remove('is-drawing');
        }
    });

    try {
        const initialState = JSON.parse(app.dataset.initialState || '{}');
        setStatus(initialState);
        if (initialState.my_result) revealResult(initialState.my_result, true);
    } catch {
        refreshStatus();
    }

    window.setInterval(() => {
        if (!isDrawing && !hasResult) refreshStatus();
    }, 5000);
})();

