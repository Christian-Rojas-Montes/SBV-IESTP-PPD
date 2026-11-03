(() => {
    const root = document.querySelector('[data-pdf-reader]');
    if (!root) return;

    const canvas = root.querySelector('[data-pdf-canvas]');
    const context = canvas.getContext('2d', { alpha: false });
    const status = root.querySelector('[data-reader-status]');
    const overlay = root.querySelector('[data-reader-overlay]');
    const overlayMessage = root.querySelector('[data-reader-overlay-message]');
    const resumeButton = root.querySelector('[data-reader-resume]');
    const pageCount = root.querySelector('[data-page-count]');
    const previousButton = root.querySelector('[data-page-previous]');
    const nextButton = root.querySelector('[data-page-next]');
    const zoomOutButton = root.querySelector('[data-zoom-out]');
    const zoomInButton = root.querySelector('[data-zoom-in]');
    const endpoint = root.dataset.streamUrl;
    const bookId = root.dataset.bookId;
    const csrfToken = root.dataset.csrfToken;
    const inactivityLimit = 15 * 60 * 1000;
    const heartbeatInterval = 45 * 1000;
    const pauseMessage = 'Sesión de lectura pausada por inactividad. Haz clic para reanudar';

    let pdfjsLib = null;
    let pdfDocument = null;
    let loadingTask = null;
    let renderTask = null;
    let token = '';
    let currentPage = 1;
    let zoom = 1;
    let pageBaseScale = 1;
    let activityTimer = null;
    let lastActivity = Date.now();
    let lastHeartbeat = 0;
    let paused = false;
    let sessionOwned = false;
    let leaseStarted = false;
    let starting = false;

    function setStatus(message, isError = false) {
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    }

    function getReaderToken() {
        const randomBytes = new Uint8Array(32);
        crypto.getRandomValues(randomBytes);
        return Array.from(randomBytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    async function ensurePdfJs() {
        if (pdfjsLib) return;
        const version = '6.5.438';
        const cdnRoot = `https://cdn.jsdelivr.net/npm/pdfjs-dist@${version}/build/`;
        pdfjsLib = await import(`${cdnRoot}pdf.mjs`);
        pdfjsLib.GlobalWorkerOptions.workerSrc = `${cdnRoot}pdf.worker.mjs`;
    }

    async function postAction(action) {
        const body = new URLSearchParams({
            action,
            book_id: bookId,
            token,
            csrf_token: csrfToken
        });
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
            body
        });
        const data = await response.json();
        if (!response.ok) {
            const error = new Error(data.error || 'No se pudo mantener la sesión de lectura.');
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function setControlsEnabled(enabled) {
        previousButton.disabled = !enabled || currentPage <= 1;
        nextButton.disabled = !enabled || !pdfDocument || currentPage >= pdfDocument.numPages;
        zoomOutButton.disabled = !enabled || zoom <= 0.65;
        zoomInButton.disabled = !enabled || zoom >= 2.5;
    }

    function clearCanvas() {
        canvas.hidden = true;
        canvas.width = 0;
        canvas.height = 0;
    }

    async function stopTransmission() {
        if (renderTask) {
            try { renderTask.cancel(); } catch (_) { /* render ya detenido */ }
            renderTask = null;
        }
        const task = loadingTask;
        loadingTask = null;
        pdfDocument = null;
        if (task) {
            try { await task.destroy(); } catch (_) { /* conexión ya cerrada */ }
        }
        clearCanvas();
    }

    async function pauseReader(message = pauseMessage, buttonLabel = 'Reanudar lectura') {
        if (paused) return;
        paused = true;
        sessionOwned = true;
        clearTimeout(activityTimer);
        setControlsEnabled(false);
        setStatus(message, message !== pauseMessage);
        overlayMessage.textContent = message;
        resumeButton.textContent = buttonLabel;
        overlay.hidden = false;
        await stopTransmission();
    }

    async function renderCurrentPage() {
        if (!pdfDocument || paused) return;
        if (renderTask) {
            try { renderTask.cancel(); } catch (_) { /* render ya detenido */ }
            renderTask = null;
        }

        const page = await pdfDocument.getPage(currentPage);
        if (paused) return;
        const baseViewport = page.getViewport({ scale: 1 });
        const maxWidth = Math.max(280, root.querySelector('[data-pdf-stage]').clientWidth - 32);
        pageBaseScale = Math.min(maxWidth / baseViewport.width, 1.6);
        const viewport = page.getViewport({ scale: pageBaseScale * zoom });
        const outputScale = Math.max(1, window.devicePixelRatio || 1);

        canvas.width = Math.floor(viewport.width * outputScale);
        canvas.height = Math.floor(viewport.height * outputScale);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;
        canvas.hidden = false;
        overlay.hidden = true;

        const pageRenderTask = page.render({
            canvasContext: context,
            viewport,
            transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0]
        });
        renderTask = pageRenderTask;
        try {
            await pageRenderTask.promise;
        } catch (error) {
            if (error?.name !== 'RenderingCancelledException') throw error;
        } finally {
            if (renderTask === pageRenderTask) renderTask = null;
        }

        if (paused) {
            clearCanvas();
            return;
        }
        pageCount.textContent = `${currentPage} / ${pdfDocument.numPages}`;
        setControlsEnabled(true);
        setStatus('Documento listo para leer.');
    }

    function scheduleInactivityCheck() {
        clearTimeout(activityTimer);
        const remaining = Math.max(0, inactivityLimit - (Date.now() - lastActivity));
        activityTimer = setTimeout(() => {
            if (Date.now() - lastActivity >= inactivityLimit) {
                pauseReader();
            } else {
                scheduleInactivityCheck();
            }
        }, remaining);
    }

    async function refreshLease() {
        if (paused || !sessionOwned || Date.now() - lastHeartbeat < heartbeatInterval) return;
        lastHeartbeat = Date.now();
        try {
            await postAction('touch');
        } catch (error) {
            const message = error.status === 409 || error.status === 403
                ? 'La sesión de lectura venció o está activa en otra pestaña o navegador.'
                : 'No se pudo validar la sesión de lectura. Vuelve a intentarlo.';
            await pauseReader(message, 'Reintentar lectura');
        }
    }

    function registerActivity() {
        if (paused || !sessionOwned) return;
        lastActivity = Date.now();
        scheduleInactivityCheck();
        refreshLease();
    }

    async function loadDocument() {
        const url = new URL(endpoint, window.location.href);
        url.searchParams.set('id', bookId);
        loadingTask = pdfjsLib.getDocument({
            url: url.href,
            httpHeaders: { 'X-Reader-Token': token },
            withCredentials: true,
            rangeChunkSize: 65536,
            disableStream: true,
            disableAutoFetch: true
        });
        pdfDocument = await loadingTask.promise;
        if (paused) return;
        currentPage = Math.min(currentPage, pdfDocument.numPages);
        pageCount.textContent = `${currentPage} / ${pdfDocument.numPages}`;
        await renderCurrentPage();
    }

    async function beginReading() {
        if (starting) return;
        starting = true;
        let acquiredThisAttempt = false;
        try {
            await ensurePdfJs();
            setStatus('Validando la sesión de lectura…');
            await postAction('start');
            sessionOwned = true;
            leaseStarted = true;
            acquiredThisAttempt = true;
            paused = false;
            lastActivity = Date.now();
            lastHeartbeat = Date.now();
            overlay.hidden = true;
            scheduleInactivityCheck();
            await loadDocument();
            scheduleInactivityCheck();
        } catch (error) {
            if (paused) return;
            if (acquiredThisAttempt) {
                try { await postAction('end'); } catch (_) { /* lease expirará por inactividad */ }
                leaseStarted = false;
            }
            paused = true;
            sessionOwned = false;
            setControlsEnabled(false);
            clearCanvas();
            const message = error.message || 'No se pudo abrir el documento digital.';
            setStatus(message, true);
            overlayMessage.textContent = message;
            resumeButton.textContent = error.status === 409 ? 'Reintentar lectura' : 'Volver a intentar';
            overlay.hidden = false;
        } finally {
            starting = false;
        }
    }

    previousButton.addEventListener('click', async () => {
        if (paused || currentPage <= 1) return;
        currentPage--;
        registerActivity();
        try { await renderCurrentPage(); } catch (error) { await pauseReader(error.message, 'Reintentar lectura'); }
    });
    nextButton.addEventListener('click', async () => {
        if (paused || !pdfDocument || currentPage >= pdfDocument.numPages) return;
        currentPage++;
        registerActivity();
        try { await renderCurrentPage(); } catch (error) { await pauseReader(error.message, 'Reintentar lectura'); }
    });
    zoomOutButton.addEventListener('click', async () => {
        if (paused || zoom <= 0.65) return;
        zoom = Math.max(0.65, zoom - 0.15);
        registerActivity();
        try { await renderCurrentPage(); } catch (error) { await pauseReader(error.message, 'Reintentar lectura'); }
    });
    zoomInButton.addEventListener('click', async () => {
        if (paused || zoom >= 2.5) return;
        zoom = Math.min(2.5, zoom + 0.15);
        registerActivity();
        try { await renderCurrentPage(); } catch (error) { await pauseReader(error.message, 'Reintentar lectura'); }
    });
    resumeButton.addEventListener('click', beginReading);

    for (const eventName of ['pointerdown', 'pointermove', 'wheel', 'scroll', 'keydown', 'touchstart']) {
        window.addEventListener(eventName, registerActivity, { passive: eventName !== 'keydown', capture: eventName === 'scroll' });
    }
    root.addEventListener('contextmenu', event => event.preventDefault());
    document.addEventListener('keydown', event => {
        const key = event.key.toLowerCase();
        if ((event.ctrlKey || event.metaKey) && (key === 's' || key === 'p')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    window.addEventListener('beforeprint', () => document.body.classList.add('pdf-reader-print-block'));
    window.addEventListener('afterprint', () => document.body.classList.remove('pdf-reader-print-block'));
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && sessionOwned && !paused && Date.now() - lastActivity >= inactivityLimit) {
            pauseReader();
        }
    });
    window.addEventListener('pagehide', () => {
        clearTimeout(activityTimer);
        if (leaseStarted && token) {
            const body = new URLSearchParams({
                action: 'end',
                book_id: bookId,
                token,
                csrf_token: csrfToken
            });
            const queued = typeof navigator.sendBeacon === 'function' && navigator.sendBeacon(endpoint, body);
            if (!queued) {
                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body,
                    keepalive: true
                }).catch(() => {});
            }
        }
        stopTransmission();
    }, { once: true });

    (async () => {
        try {
            token = getReaderToken();
            await beginReading();
        } catch (error) {
            const message = error.message || 'No se pudo cargar el lector PDF.';
            setStatus(message, true);
            overlayMessage.textContent = message;
            overlay.hidden = false;
            resumeButton.textContent = 'Volver a intentar';
        }
    })();
})();
