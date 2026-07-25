(() => {
    'use strict';

    const initialize = () => {
        'use strict';

        (function () {
            function renderQrCode(element) {
                if (!window.KaevCMSQRCode) {
                    return;
                }

                var value = element.dataset.uri || '';
                if (!value) {
                    return;
                }

                var qr = new window.KaevCMSQRCode(-1, 1);
                qr.addData(value);
                qr.make();

                var moduleCount = qr.getModuleCount();
                var quietZone = 4;
                var size = moduleCount + quietZone * 2;
                var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
                svg.setAttribute('role', 'img');
                svg.setAttribute('aria-label', element.getAttribute('aria-label') || 'QR code');
                svg.setAttribute('shape-rendering', 'crispEdges');

                var background = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                background.setAttribute('width', String(size));
                background.setAttribute('height', String(size));
                background.setAttribute('fill', '#ffffff');
                svg.appendChild(background);

                var pathData = [];
                for (var row = 0; row < moduleCount; row++) {
                    for (var column = 0; column < moduleCount; column++) {
                        if (qr.isDark(row, column)) {
                            pathData.push('M' + (column + quietZone) + ' ' + (row + quietZone) + 'h1v1h-1z');
                        }
                    }
                }

                var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', pathData.join(''));
                path.setAttribute('fill', '#111827');
                svg.appendChild(path);
                element.replaceChildren(svg);
            }

            function recoveryText(card) {
                var source = card.querySelector('[data-recovery-code-source]');
                return source ? source.value : '';
            }

            function copyText(value) {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(value);
                    return;
                }

                var textarea = document.createElement('textarea');
                textarea.value = value;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                textarea.remove();
            }

            document.querySelectorAll('[data-two-factor-qr]').forEach(renderQrCode);

            document.querySelectorAll('[data-copy-recovery-codes]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var card = button.closest('[data-recovery-codes]');
                    if (!card) {
                        return;
                    }
                    copyText(recoveryText(card));
                });
            });

            document.querySelectorAll('[data-download-recovery-codes]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var card = button.closest('[data-recovery-codes]');
                    if (!card) {
                        return;
                    }
                    var blob = new Blob([recoveryText(card) + '\n'], {type: 'text/plain;charset=utf-8'});
                    var url = URL.createObjectURL(blob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = button.dataset.filename || 'recovery-codes.txt';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    window.setTimeout(function () {
                        URL.revokeObjectURL(url);
                    }, 0);
                });
            });
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('two-factor', initialize);
    } else {
        initialize();
    }
})();
