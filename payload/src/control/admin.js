(() => {
    'use strict';

    const body = document.body;
    const openMenu = document.querySelector('[data-menu-open]');
    const closeMenuButtons = document.querySelectorAll('[data-menu-close]');
    if (openMenu) {
        openMenu.addEventListener('click', () => body.classList.add('menu-open'));
    }
    closeMenuButtons.forEach((button) => {
        button.addEventListener('click', () => body.classList.remove('menu-open'));
    });
    document.querySelectorAll('.nav-item').forEach((link) => {
        link.addEventListener('click', () => body.classList.remove('menu-open'));
    });

    document.querySelectorAll('[data-alert-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const alert = button.closest('[data-alert]');
            if (alert) {
                alert.remove();
            }
        });
    });

    // Delegation also covers action forms copied into the selection toolbar.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const message = form.dataset.confirm;
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

})();

(() => {
    const appBasePath = document.body.dataset.appBasePath || '';
    if (appBasePath !== '' && appBasePath !== '/GitPosted') throw new Error('Некорректный путь приложения.');
    const toBuffer = (value) => {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(base64 + '='.repeat((4 - base64.length % 4) % 4));
        return Uint8Array.from(raw, (char) => char.charCodeAt(0)).buffer;
    };
    const toBase64 = (value) => {
        const bytes = new Uint8Array(value);
        let raw = '';
        bytes.forEach((byte) => { raw += String.fromCharCode(byte); });
        return btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    };
    const request = async (path, options = {}) => {
        const response = await fetch(`${appBasePath}/control/passkeys.php/${path}`, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(window.adminCsrf ? {'X-CSRF-Token': window.adminCsrf} : {}),
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || 'Операция с ключом не выполнена.');
        return data;
    };
    const prepareOptions = (publicKey) => ({
        ...publicKey,
        challenge: toBuffer(publicKey.challenge),
        user: publicKey.user ? {...publicKey.user, id: toBuffer(publicKey.user.id)} : undefined,
        allowCredentials: publicKey.allowCredentials?.map((item) => ({...item, id: toBuffer(item.id)})),
        excludeCredentials: publicKey.excludeCredentials?.map((item) => ({...item, id: toBuffer(item.id)})),
    });
    const login = document.getElementById('passkeyLogin');
    if (login && window.PublicKeyCredential) {
        request('status').then((data) => { login.hidden = !data.available; }).catch(() => {});
        document.getElementById('passkeyLoginButton')?.addEventListener('click', async () => {
            try {
                const options = await request('auth/options', {method: 'POST', body: '{}'});
                const credential = await navigator.credentials.get({publicKey: prepareOptions(options.publicKey)});
                const result = await request('auth/verify', {
                    method: 'POST',
                    body: JSON.stringify({
                        credential_id: toBase64(credential.rawId),
                        response: {
                            clientDataJSON: toBase64(credential.response.clientDataJSON),
                            authenticatorData: toBase64(credential.response.authenticatorData),
                            signature: toBase64(credential.response.signature),
                        },
                    }),
                });
                location.href = result.redirect;
            } catch (error) {
                alert(error.message);
            }
        });
    }

    const list = document.getElementById('passkeyList');
    if (!list) return;
    window.adminCsrf = document.querySelector('input[name="csrf"]')?.value || '';
    const errorBox = document.getElementById('passkeyError');
    const addButton = document.getElementById('passkeyAddButton');
    let siteSupportsPasskeys = true;
    const showError = (error) => {
        errorBox.textContent = error.message;
        errorBox.hidden = false;
    };
    const render = (keys) => {
        addButton.disabled = keys.length >= 3 || !window.PublicKeyCredential || !siteSupportsPasskeys;
        list.innerHTML = keys.length ? keys.map((key) => `
            <div class="passkey-row">
                <div><strong>${key.name}</strong><small>Добавлен: ${key.created_at}${key.last_used_at ? ` · использован: ${key.last_used_at}` : ''}</small></div>
                <button class="icon-button icon-button--danger" type="button" data-passkey-delete="${key.id}" title="Удалить ключ" aria-label="Удалить ключ"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
            </div>`).join('') : '<div class="empty">Ключей доступа пока нет.</div>';
        list.querySelectorAll('[data-passkey-delete]').forEach((button) => button.addEventListener('click', async () => {
            if (!confirm('Удалить этот ключ доступа?')) return;
            try {
                const data = await request(button.dataset.passkeyDelete, {method: 'DELETE'});
                render(data.passkeys);
            } catch (error) { showError(error); }
        }));
    };
    request('').then((data) => {
        siteSupportsPasskeys = data.supported !== false;
        render(data.passkeys);
        if (!siteSupportsPasskeys) showError(new Error(data.reason || 'Для ключей доступа нужен HTTPS-домен.'));
    }).catch(showError);
    addButton?.addEventListener('click', async () => {
        errorBox.hidden = true;
        try {
            const options = await request('register/options', {method: 'POST', body: '{}'});
            const credential = await navigator.credentials.create({publicKey: prepareOptions(options.publicKey)});
            const response = credential.response;
            const publicKey = response.getPublicKey?.();
            const authenticatorData = response.getAuthenticatorData?.();
            const algorithm = response.getPublicKeyAlgorithm?.();
            if (!publicKey || !authenticatorData || typeof algorithm !== 'number') throw new Error('Браузер не предоставил открытый ключ.');
            const data = await request('register/verify', {
                method: 'POST',
                body: JSON.stringify({
                    credential_id: toBase64(credential.rawId),
                    response: {
                        clientDataJSON: toBase64(response.clientDataJSON),
                        authenticatorData: toBase64(authenticatorData),
                        publicKey: toBase64(publicKey),
                        publicKeyAlgorithm: algorithm,
                        transports: response.getTransports?.() || [],
                    },
                }),
            });
            render(data.passkeys);
        } catch (error) { showError(error); }
    });
})();

(() => {
    document.querySelectorAll('.selection-panel').forEach((panel) => {
        const toolbar = panel.querySelector('.selection-toolbar');
        if (!toolbar) return;

        const rows = Array.from(panel.querySelectorAll('tbody tr')).filter(
            (row) => row.querySelector('.row-actions')
        );

        const selectRow = (row) => {
            rows.forEach((item) => item.classList.toggle('is-selected', item === row));
            const sourceActions = row.querySelector('.row-actions');
            if (!sourceActions) return;

            toolbar.replaceChildren();
            toolbar.append(sourceActions.cloneNode(true));

            const telegramId = row.dataset.telegramId || '';
            const maxId = row.dataset.maxId || '';
            if (telegramId || maxId) {
                const linkButton = document.createElement('button');
                linkButton.type = 'button';
                linkButton.className = 'icon-button icon-button--link';
                linkButton.title = telegramId ? 'Связать с аккаунтом МАКС' : 'Связать с аккаунтом Telegram';
                linkButton.setAttribute('aria-label', linkButton.title);
                linkButton.innerHTML = '<i class="fa-solid fa-link" aria-hidden="true"></i>';
                linkButton.addEventListener('click', () => {
                    const promptText = telegramId
                        ? 'Введите МАКС ID для связи с этим Telegram:'
                        : 'Введите Telegram ID для связи с этим аккаунтом МАКС:';
                    const current = telegramId
                        ? (row.dataset.linkedMaxId || '')
                        : (row.dataset.linkedTelegramId || '');
                    const entered = window.prompt(promptText, current);
                    if (entered === null) return;
                    const linkedId = entered.trim();
                    if (!/^[1-9][0-9]{0,63}$/.test(linkedId)) {
                        window.alert('Введите корректный числовой ID.');
                        return;
                    }

                    const form = document.createElement('form');
                    form.method = 'post';
                    const csrf = row.querySelector('input[name="csrf"]')?.value || '';
                    const fields = {
                        csrf,
                        action: 'account_link',
                        return_page: panel.dataset.returnPage || 'telegram-data',
                        telegram_id: telegramId || linkedId,
                        max_user_id: maxId || linkedId,
                    };
                    Object.entries(fields).forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        form.append(input);
                    });
                    document.body.append(form);
                    form.submit();
                });
                toolbar.append(linkButton);
            }
        };

        rows.forEach((row) => {
            row.classList.add('selectable-row');
            if (!row.hasAttribute('tabindex')) row.tabIndex = 0;
            row.addEventListener('click', (event) => {
                if (event.target.closest('button, input, select, form, a')) return;
                selectRow(row);
            });
            row.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                selectRow(row);
            });
        });
    });
})();
