(() => {
    const scriptUrl = document.currentScript?.src;
    const endpoint = scriptUrl
        ? new URL('../../webauthn.php', scriptUrl).href
        : new URL('webauthn.php', window.location.href).href;
    const loginPanel = document.querySelector('[data-webauthn-login]');
    const devicePanel = document.querySelector('[data-webauthn-devices]');
    let csrfToken = '';
    let loginPoll = null;
    let loginCountdown = null;
    let activeRequestToken = '';
    let approvingToken = '';
    const isMobileDevice = navigator.userAgentData?.mobile
        ?? (/Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1));

    const encodeBase64Url = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const decodeBase64Url = (value) => {
        const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(normalized + '='.repeat((4 - normalized.length % 4) % 4));
        return Uint8Array.from(binary, (character) => character.charCodeAt(0));
    };

    async function api(action, data = {}) {
        const url = new URL(endpoint);
        if (!data.method || data.method === 'GET') {
            url.searchParams.set('action', action);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'No se pudo completar la solicitud.');
            return payload;
        }
        const body = { ...data, action, csrf_token: csrfToken };
        delete body.method;
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body)
        });
        const payload = await response.json();
        if (!response.ok) {
            const error = new Error(payload.error || 'No se pudo completar la solicitud.');
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    function setMessage(element, message, isError = false) {
        if (!element) return;
        element.textContent = message;
        element.hidden = !message;
        element.classList.toggle('danger', isError);
        element.classList.toggle('info', !isError);
    }

    function stopLoginWait() {
        clearInterval(loginPoll);
        clearInterval(loginCountdown);
        loginPoll = null;
        loginCountdown = null;
        activeRequestToken = '';
    }

    async function pollLoginStatus() {
        if (!activeRequestToken) return;
        try {
            const result = await api('quick_status', { method: 'POST', request_token: activeRequestToken });
            if (result.status === 'approved') {
                stopLoginWait();
                window.location.assign(result.redirect || 'dashboard.php');
            }
        } catch (error) {
            if (error.status === 410 || error.status === 403) {
                stopLoginWait();
                const status = loginPanel?.querySelector('[data-quick-login-status]');
                const wait = loginPanel?.querySelector('[data-quick-login-wait]');
                const startButton = loginPanel?.querySelector('[data-quick-login-start]');
                if (wait) wait.hidden = true;
                if (startButton) startButton.disabled = false;
                setMessage(status, error.message, true);
            }
        }
    }

    function initializeQuickLogin() {
        if (!loginPanel) return;
        const startButton = loginPanel.querySelector('[data-quick-login-start]');
        const cancelButton = loginPanel.querySelector('[data-quick-login-cancel]');
        const status = loginPanel.querySelector('[data-quick-login-status]');
        const wait = loginPanel.querySelector('[data-quick-login-wait]');
        const countdown = loginPanel.querySelector('[data-quick-login-countdown]');
        startButton.addEventListener('click', async () => {
            if (!window.isSecureContext) {
                setMessage(status, 'El inicio biométrico entre dispositivos requiere que la biblioteca use HTTPS.', true);
                return;
            }
            startButton.disabled = true;
            setMessage(status, 'Creando una solicitud segura…');
            try {
                const result = await api('quick_start', { method: 'POST' });
                activeRequestToken = result.request_token;
                let seconds = result.expires_in || 300;
                if (wait) wait.hidden = false;
                if (status) status.hidden = true;
                const drawCountdown = () => {
                    if (countdown) countdown.textContent = `Expira en ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
                    seconds -= 1;
                    if (seconds < 0) {
                        stopLoginWait();
                        if (wait) wait.hidden = true;
                        setMessage(status, 'La solicitud de acceso venció. Inicia una nueva solicitud.', true);
                        startButton.disabled = false;
                    }
                };
                drawCountdown();
                loginCountdown = setInterval(drawCountdown, 1000);
                loginPoll = setInterval(pollLoginStatus, 2000);
                pollLoginStatus();
            } catch (error) {
                setMessage(status, error.message, true);
                startButton.disabled = false;
            }
        });
        cancelButton?.addEventListener('click', async () => {
            if (!activeRequestToken) return;
            try { await api('cancel', { method: 'POST', request_token: activeRequestToken }); } catch (_) { /* la solicitud expirará */ }
            stopLoginWait();
            if (wait) wait.hidden = true;
            setMessage(status, 'Solicitud cancelada.');
            startButton.disabled = false;
        });
    }

    function serializeCredential(credential, includeAttestation = false) {
        const response = credential.response;
        const result = {
            id: credential.id,
            rawId: encodeBase64Url(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: encodeBase64Url(response.clientDataJSON)
            }
        };
        if (includeAttestation) {
            result.response.attestationObject = encodeBase64Url(response.attestationObject);
        } else {
            result.response.authenticatorData = encodeBase64Url(response.authenticatorData);
            result.response.signature = encodeBase64Url(response.signature);
            result.response.userHandle = response.userHandle ? encodeBase64Url(response.userHandle) : null;
        }
        return result;
    }

    function initializeDeviceManager() {
        if (!devicePanel) return;
        const registerButton = devicePanel.querySelector('[data-webauthn-register]');
        const message = devicePanel.querySelector('[data-webauthn-message]');
        const list = devicePanel.querySelector('[data-webauthn-device-list]');

        const loadDevices = async () => {
            try {
                const data = await api('devices');
                list.replaceChildren();
                if (!data.devices.length) {
                    const empty = document.createElement('p');
                    empty.className = 'empty-state';
                    empty.textContent = 'Todavía no hay dispositivos vinculados.';
                    list.append(empty);
                    return;
                }
                data.devices.forEach((device) => {
                    const item = document.createElement('article');
                    item.className = 'device-item';
                    const info = document.createElement('div');
                    const title = document.createElement('strong');
                    title.textContent = device.device_name || 'Dispositivo sin nombre';
                    const dates = document.createElement('small');
                    dates.textContent = `Registrado ${device.created_at || '—'} · Último uso ${device.last_used_at || '—'}`;
                    info.append(title, dates);
                    const revoke = document.createElement('button');
                    revoke.type = 'button';
                    revoke.className = 'btn btn-light btn-small';
                    revoke.textContent = 'Revocar';
                    revoke.addEventListener('click', async () => {
                        if (!window.confirm(`¿Revocar ${device.device_name || 'este dispositivo'}?`)) return;
                        revoke.disabled = true;
                        try {
                            await api('revoke', { method: 'POST', credential_id: device.credential_id });
                            setMessage(message, 'Dispositivo revocado.');
                            await loadDevices();
                        } catch (error) {
                            setMessage(message, error.message, true);
                            revoke.disabled = false;
                        }
                    });
                    item.append(info, revoke);
                    list.append(item);
                });
            } catch (error) {
                setMessage(message, error.message, true);
            }
        };

        registerButton.addEventListener('click', async () => {
            if (!window.PublicKeyCredential || !window.isSecureContext) {
                setMessage(message, 'WebAuthn necesita un navegador compatible y HTTPS (localhost también es válido).', true);
                return;
            }
            registerButton.disabled = true;
            try {
                const { options } = await api('register_options', { method: 'POST' });
                options.challenge = decodeBase64Url(options.challenge);
                options.user.id = decodeBase64Url(options.user.id);
                options.excludeCredentials = (options.excludeCredentials || []).map((item) => ({ ...item, id: decodeBase64Url(item.id) }));
                const credential = await navigator.credentials.create({ publicKey: options });
                if (!credential) throw new Error('No se completó el registro en el autenticador.');
                const platform = navigator.userAgentData?.platform || navigator.platform || 'Dispositivo';
                await api('register_verify', {
                    method: 'POST',
                    device_name: `${platform} · ${new Date().toLocaleDateString()}`,
                    credential: serializeCredential(credential, true)
                });
                setMessage(message, 'Dispositivo registrado correctamente.');
                await loadDevices();
            } catch (error) {
                setMessage(message, error.name === 'NotAllowedError' ? 'El registro se canceló o el autenticador no respondió.' : error.message, true);
            } finally {
                registerButton.disabled = false;
            }
        });

        loadDevices();
    }

    function renderApprovalBanner(requests) {
        let banner = document.querySelector('[data-webauthn-approval-banner]');
        if (!requests.length) {
            banner?.remove();
            return;
        }
        if (!banner) {
            banner = document.createElement('section');
            banner.className = 'webauthn-approval-banner';
            banner.dataset.webauthnApprovalBanner = '';
            banner.setAttribute('role', 'region');
            banner.setAttribute('aria-label', 'Solicitudes de acceso pendientes');
            document.body.prepend(banner);
        }
        banner.replaceChildren();
        requests.forEach((request) => {
            const card = document.createElement('article');
            card.className = 'webauthn-approval-request';
            const title = document.createElement('strong');
            title.textContent = 'Tienes una solicitud de acceso pendiente de una PC (Expira en 5 min)';
            const device = document.createElement('span');
            device.textContent = `Navegador: ${request.device_info || 'Computadora'}`;
            const remaining = document.createElement('small');
            remaining.textContent = `Quedan ${Math.floor(request.expires_in / 60)}:${String(request.expires_in % 60).padStart(2, '0')}`;
            const authorize = document.createElement('button');
            authorize.type = 'button';
            authorize.className = 'btn btn-primary';
            authorize.textContent = 'Autorizar con Huella';
            const status = document.createElement('p');
            status.className = 'approval-status';
            authorize.addEventListener('click', async () => {
                if (!window.PublicKeyCredential || !window.isSecureContext) {
                    status.textContent = 'WebAuthn requiere HTTPS y un navegador compatible.';
                    return;
                }
                authorize.disabled = true;
                approvingToken = request.request_token;
                status.textContent = 'Confirma tu identidad en el autenticador…';
                try {
                    const publicKey = {
                        challenge: decodeBase64Url(request.challenge),
                        rpId: window.location.hostname,
                        timeout: 60000,
                        userVerification: 'required',
                        allowCredentials: request.credential_ids.map((id) => ({ type: 'public-key', id: decodeBase64Url(id) }))
                    };
                    const credential = await navigator.credentials.get({ publicKey });
                    if (!credential) throw new Error('No se recibió una respuesta del autenticador.');
                    await api('approve', { method: 'POST', request_token: request.request_token, credential: serializeCredential(credential) });
                    status.textContent = 'Acceso aprobado. La computadora completará el inicio de sesión.';
                    card.classList.add('is-approved');
                    authorize.remove();
                } catch (error) {
                    status.textContent = error.name === 'NotAllowedError' ? 'No se autorizó la solicitud.' : error.message;
                    authorize.disabled = false;
                } finally {
                    approvingToken = '';
                    refreshPendingRequests();
                }
            });
            card.append(title, device, remaining, authorize, status);
            banner.append(card);
        });
    }

    async function refreshPendingRequests() {
        if (approvingToken) return;
        try {
            const result = await api('pending');
            if (approvingToken) return;
            if (result.authenticated) renderApprovalBanner(result.requests || []);
        } catch (_) { /* La sesión puede haber terminado */ }
    }

    (async () => {
        let boot = null;
        try {
            boot = await api('bootstrap');
            csrfToken = boot.csrf_token;
        } catch (_) { /* El login tradicional sigue disponible si WebAuthn no carga */ }
        initializeQuickLogin();
        initializeDeviceManager();
        if (boot?.authenticated && isMobileDevice) {
            await refreshPendingRequests();
            setInterval(refreshPendingRequests, 7000);
        }
    })();
})();
