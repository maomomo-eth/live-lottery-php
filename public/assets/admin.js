(() => {
    'use strict';

    const qrContainer = document.querySelector('#qr-code');
    const copyButton = document.querySelector('#copy-url');
    const urlInput = document.querySelector('#share-url');

    function renderQrCode(text) {
        if (!qrContainer || !window.qrcodegen || !text) return;
        const qr = window.qrcodegen.QrCode.encodeText(text, window.qrcodegen.QrCode.Ecc.MEDIUM);
        const border = 3;
        const size = qr.size + border * 2;
        const parts = [];
        for (let y = 0; y < qr.size; y += 1) {
            for (let x = 0; x < qr.size; x += 1) {
                if (qr.getModule(x, y)) parts.push(`M${x + border},${y + border}h1v1h-1z`);
            }
        }
        qrContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" role="img" aria-label="参与者抽奖二维码"><rect width="100%" height="100%" fill="#fff"/><path d="${parts.join('')}" fill="#07101d"/></svg>`;
    }

    if (qrContainer) renderQrCode(qrContainer.dataset.url || '');

    copyButton?.addEventListener('click', async () => {
        const value = urlInput?.value || '';
        try {
            await navigator.clipboard.writeText(value);
            copyButton.textContent = '已复制';
        } catch {
            urlInput?.select();
            document.execCommand('copy');
            copyButton.textContent = '已复制';
        }
        window.setTimeout(() => { copyButton.textContent = '复制'; }, 1600);
    });

    document.querySelector('[data-refresh-page]')?.addEventListener('click', () => window.location.reload());
})();

