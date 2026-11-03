(() => {
    document.querySelectorAll('canvas[data-qr-value]').forEach(canvas => {
        if (!window.QRCode) {
            canvas.replaceWith(document.createTextNode('No se cargó el generador QR.'));
            return;
        }

        QRCode.toCanvas(canvas, canvas.dataset.qrValue, {
            width: 128,
            margin: 2,
            errorCorrectionLevel: 'M'
        }, error => {
            if (error) canvas.replaceWith(document.createTextNode('No se pudo generar el QR.'));
        });
    });

    let printTarget = null;
    document.querySelectorAll('[data-print-qr]').forEach(button => {
        button.addEventListener('click', () => {
            printTarget = button.closest('[data-qr-label]');
            if (!printTarget) return;
            printTarget.classList.add('is-printing');
            document.body.classList.add('printing-qr-label');
            window.print();
        });
    });

    window.addEventListener('afterprint', () => {
        printTarget?.classList.remove('is-printing');
        document.body.classList.remove('printing-qr-label');
        printTarget = null;
    });
})();
