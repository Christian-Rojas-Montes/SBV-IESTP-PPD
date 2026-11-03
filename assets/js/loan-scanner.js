(() => {
    const root = document.querySelector('[data-copy-scanner]');
    if (!root) return;

    const status = root.querySelector('[data-scanner-status]');
    const result = root.querySelector('[data-copy-result]');
    const startButton = root.querySelector('[data-start-scanner]');
    const stopButton = root.querySelector('[data-stop-scanner]');
    const resetButton = root.querySelector('[data-reset-scanner]');
    const borrowForm = root.querySelector('[data-copy-borrow-form]');
    const returnForm = root.querySelector('[data-copy-return-form]');
    const deliverForm = root.querySelector('[data-copy-deliver-form]');
    let scanner = null;
    let scanning = false;
    let handlingScan = false;

    function setStatus(message, isError = false) {
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    }

    function addResultLine(label, value) {
        const line = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = `${label}: `;
        line.append(strong, document.createTextNode(value || '—'));
        result.append(line);
    }

    function formatLocalDate(value) {
        if (!value) return '—';
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
        const parsed = new Date(`${value.replace(' ', 'T')}Z`);
        return Number.isNaN(parsed.getTime())
            ? value
            : new Intl.DateTimeFormat('es-PE').format(parsed);
    }

    async function stopScanner() {
        if (!scanning || !scanner) return;
        try {
            await scanner.stop();
        } catch (error) {
            console.warn('No se pudo detener la cámara del lector QR.', error);
        }
        scanning = false;
        startButton.hidden = false;
        stopButton.hidden = true;
    }

    async function showCopy(code) {
        result.replaceChildren();
        result.hidden = false;
        borrowForm.hidden = true;
        returnForm.hidden = true;
        deliverForm.hidden = true;
        setStatus('Buscando el ejemplar…');

        try {
            const url = new URL(root.dataset.lookupUrl, window.location.href);
            url.searchParams.set('code', code);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'No se pudo consultar el ejemplar.');

            const { copy, loan } = data;
            addResultLine('Título', copy.title);
            addResultLine('Autor', copy.author);
            addResultLine('Carrera', copy.career);
            addResultLine('Sede', copy.sede);
            addResultLine('Código', copy.qr_code);
            addResultLine('Estado', copy.status);

            if (copy.status === 'no_disponible' && loan?.status === 'reservado') {
                addResultLine('Reservado por', loan.borrower_name);
                addResultLine('Recojo programado', loan.pickup_date_label || formatLocalDate(loan.pickup_date));
                addResultLine('Devolución límite', loan.due_date_label || formatLocalDate(loan.due_date));
                deliverForm.querySelector('[name="loan_id"]').value = loan.id;
                deliverForm.hidden = false;
                setStatus('Reserva vigente. Registra la entrega a la persona identificada.');
            } else if (copy.status === 'disponible') {
                borrowForm.querySelector('[name="copy_id"]').value = copy.id;
                borrowForm.hidden = false;
                setStatus('Ejemplar disponible. Selecciona a quién se lo entregarás.');
            } else if (copy.status === 'prestado' && loan && ['prestado', 'vencido'].includes(loan.status)) {
                addResultLine('Prestado a', loan.borrower_name);
                addResultLine('Vence', loan.due_date_label || formatLocalDate(loan.due_date));
                returnForm.querySelector('[name="copy_id"]').value = copy.id;
                returnForm.hidden = false;
                setStatus('Préstamo encontrado. Puedes registrar la devolución.');
            } else {
                setStatus('El ejemplar no está disponible para préstamo ni tiene una devolución pendiente.');
            }
            resetButton.hidden = false;
        } catch (error) {
            setStatus(error.message || 'No se pudo consultar el código QR.', true);
            result.hidden = true;
        }
    }

    async function startScanner() {
        if (!window.isSecureContext) {
            setStatus('La cámara solo funciona en HTTPS o en localhost.', true);
            return;
        }
        if (!window.Html5Qrcode) {
            setStatus('No se pudo cargar el lector QR. Revisa tu conexión e inténtalo otra vez.', true);
            return;
        }

        scanner ??= new window.Html5Qrcode('copy-qr-reader');
        setStatus('Solicitando acceso a la cámara…');
        try {
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 240, height: 240 }, aspectRatio: 4 / 3 },
                async decodedText => {
                    if (handlingScan) return;
                    handlingScan = true;
                    await stopScanner();
                    await showCopy(decodedText.trim());
                    handlingScan = false;
                }
            );
            scanning = true;
            startButton.hidden = true;
            stopButton.hidden = false;
            setStatus('Cámara activa. Apunta al código QR del ejemplar.');
        } catch (error) {
            setStatus(error.message || 'No se pudo abrir la cámara. Comprueba el permiso del navegador.', true);
        }
    }

    startButton.addEventListener('click', startScanner);
    stopButton.addEventListener('click', stopScanner);
    resetButton.addEventListener('click', () => {
        result.hidden = true;
        result.replaceChildren();
        borrowForm.hidden = true;
        returnForm.hidden = true;
        deliverForm.hidden = true;
        resetButton.hidden = true;
        startScanner();
    });

    window.addEventListener('pagehide', stopScanner, { once: true });
})();
