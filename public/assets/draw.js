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
    const wheel = document.querySelector('#prize-wheel');
    const wheelCanvas = document.querySelector('#wheel-canvas');
    let isDrawing = false;
    let hasResult = false;
    let wheelRotation = 0;

    let initialState = {};
    try {
        initialState = JSON.parse(app.dataset.initialState || '{}');
    } catch {
        initialState = {};
    }

    const wheelSegments = buildWheelSegments(Number(initialState.total) || 12);

    const statusText = {
        open: '抽奖进行中',
        paused: '抽奖暂时停下，请等待管理员开放',
        closed: '本场抽奖已经结束',
        finished: '所有参与名额已经抽完',
    };

    function buildWheelSegments(total) {
        const count = Math.max(3, Math.min(Math.floor(total), 500));
        const segments = Array.from({ length: count }, (_, index) => ({
            type: 'none',
            label: '好运',
            color: index % 2 === 0 ? '#172c44' : '#102238',
        }));
        const firstHatIndex = Math.max(1, Math.floor(count / 3));
        const secondHatIndex = Math.max(2, Math.floor(count * 2 / 3));
        segments[0] = { type: 'onekey', label: 'OneKey', color: '#d9a93d' };
        segments[firstHatIndex] = { type: 'okx_hat', label: 'Q总帽子', color: '#1dbca2' };
        segments[secondHatIndex] = { type: 'okx_hat', label: 'Q总帽子', color: '#1dbca2' };

        return segments;
    }

    function drawWheel() {
        const context = wheelCanvas?.getContext('2d');
        if (!context) return;

        const size = wheelCanvas.width;
        const center = size / 2;
        const radius = center - 18;
        const step = (Math.PI * 2) / wheelSegments.length;
        context.clearRect(0, 0, size, size);

        wheelSegments.forEach((segment, index) => {
            const start = -Math.PI / 2 + index * step;
            const end = start + step;
            const middle = start + step / 2;

            context.beginPath();
            context.moveTo(center, center);
            context.arc(center, center, radius, start, end);
            context.closePath();
            context.fillStyle = segment.color;
            context.fill();
            context.strokeStyle = 'rgba(255,255,255,.18)';
            context.lineWidth = wheelSegments.length > 60 ? 0.35 : 2;
            context.stroke();

            if (wheelSegments.length <= 24) {
                const labelRadius = radius * 0.68;
                const x = center + Math.cos(middle) * labelRadius;
                const y = center + Math.sin(middle) * labelRadius;
                context.save();
                context.translate(x, y);
                context.fillStyle = segment.type === 'none' ? '#a9bbcf' : '#ffffff';
                context.font = `700 ${wheelSegments.length > 16 ? 21 : 27}px system-ui, sans-serif`;
                context.textAlign = 'center';
                context.textBaseline = 'middle';
                context.shadowColor = 'rgba(0,0,0,.35)';
                context.shadowBlur = 4;
                context.fillText(segment.label, 0, 0);
                context.restore();
            }
        });

        context.beginPath();
        context.arc(center, center, radius, 0, Math.PI * 2);
        context.strokeStyle = '#ffd36b';
        context.lineWidth = 12;
        context.stroke();

        context.beginPath();
        context.arc(center, center, radius - 12, 0, Math.PI * 2);
        context.strokeStyle = 'rgba(255,255,255,.34)';
        context.lineWidth = 2;
        context.stroke();
    }

    function resultSegmentIndexes(prizeType) {
        return wheelSegments
            .map((segment, index) => segment.type === prizeType ? index : -1)
            .filter((index) => index >= 0);
    }

    function targetRotation(prizeType, withExtraTurns) {
        const indexes = resultSegmentIndexes(prizeType);
        const candidates = indexes.length > 0 ? indexes : resultSegmentIndexes('none');
        const index = candidates[Math.floor(Math.random() * candidates.length)];
        const stepDegrees = 360 / wheelSegments.length;
        const jitter = (Math.random() - 0.5) * stepDegrees * 0.34;
        const target = -((index + 0.5) * stepDegrees) + jitter;

        if (!withExtraTurns) return target;

        const currentNormalized = ((wheelRotation % 360) + 360) % 360;
        const targetNormalized = ((target % 360) + 360) % 360;
        const forwardDelta = (targetNormalized - currentNormalized + 360) % 360;

        return wheelRotation + 360 * 6 + forwardDelta;
    }

    function settleWheel(result) {
        wheelRotation = targetRotation(result.prize_type, false);
        wheel.style.transition = 'none';
        wheel.style.transform = `rotate(${wheelRotation}deg)`;
    }

    function spinWheel(result) {
        return new Promise((resolve) => {
            wheelRotation = targetRotation(result.prize_type, true);
            wheel.classList.add('is-spinning');
            wheel.style.transition = 'transform 4.2s cubic-bezier(.12,.62,.08,1)';

            const finish = () => {
                wheel.removeEventListener('transitionend', finish);
                wheel.classList.remove('is-spinning');
                resolve();
            };
            wheel.addEventListener('transitionend', finish, { once: true });
            window.setTimeout(finish, 4700);
            window.requestAnimationFrame(() => {
                wheel.style.transform = `rotate(${wheelRotation}deg)`;
            });
        });
    }

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
            resultTitle.textContent = 'Q总赞助帽子';
            resultMessage.textContent = `${result.name}，好运被你抽中了！请向现场工作人员领取。`;
        } else {
            resultIcon.textContent = '☻';
            resultKicker.textContent = '感谢参与';
            resultTitle.textContent = '这次差一点点';
            resultMessage.textContent = `${result.name}，谢谢你的参与，祝你今天依然好运！`;
        }
    }

    async function refreshStatus(showSavedResult = true) {
        try {
            const response = await fetch(`${apiUrl}?action=status&event=${encodeURIComponent(eventId)}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || '无法获取场次状态。');
            setStatus(payload.event);
            if (showSavedResult && payload.event.my_result) {
                settleWheel(payload.event.my_result);
                revealResult(payload.event.my_result, true);
            }
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
            const response = await fetch(`${apiUrl}?action=draw&event=${encodeURIComponent(eventId)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ name }),
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || '抽奖失败，请重试。');
            await spinWheel(payload.result);
            revealResult(payload.result);
            await refreshStatus(false);
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

    drawWheel();
    if (initialState.status) {
        setStatus(initialState);
        if (initialState.my_result) {
            settleWheel(initialState.my_result);
            revealResult(initialState.my_result, true);
        }
    } else {
        refreshStatus();
    }

    window.setInterval(() => {
        if (!isDrawing && !hasResult) refreshStatus();
    }, 5000);
})();
