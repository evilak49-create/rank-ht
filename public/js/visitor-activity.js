const storageKey = 'rebates-visitor-id';
const heartbeatInterval = 60_000;

function newVisitorId() {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}

function storedVisitorId() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('rebates-visitor', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('identity');
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Visitor storage is busy.'));
        request.onsuccess = () => {
            const database = request.result;
            const transaction = database.transaction('identity', 'readwrite');
            const store = transaction.objectStore('identity');
            const lookup = store.get('browser');
            let id;

            lookup.onsuccess = () => {
                id = lookup.result ?? newVisitorId();
                if (lookup.result === undefined) {
                    store.add(id, 'browser');
                }
            };
            transaction.oncomplete = () => {
                database.close();
                resolve(id);
            };
            transaction.onabort = () => {
                database.close();
                reject(transaction.error);
            };
        };
    });
}

function localVisitorId() {
    try {
        const existing = localStorage.getItem(storageKey);
        if (existing) {
            return existing;
        }

        const id = newVisitorId();
        localStorage.setItem(storageKey, id);
        return id;
    } catch {
        return newVisitorId();
    }
}

const visitorId = storedVisitorId().catch(async () => {
    if (navigator.locks) {
        try {
            return await navigator.locks.request(storageKey, localVisitorId);
        } catch {
            // Storage may be disabled by the browser.
        }
    }

    return localVisitorId();
});

function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : null;
}

async function refreshCsrf(signal) {
    const response = await fetch('/sanctum/csrf-cookie', { signal, credentials: 'same-origin' });
    return response.ok ? csrfToken() : null;
}

let pending = false;

async function heartbeat() {
    if (document.visibilityState !== 'visible' || pending) {
        return;
    }

    pending = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12_000);
    try {
        const signal = controller.signal;
        const id = await visitorId;
        let token = csrfToken() ?? await refreshCsrf(signal);
        if (token && document.visibilityState === 'visible') {
            const send = (csrf) => fetch('/api/v1/visitor-activity', {
                method: 'POST',
                signal,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ visitor_id: id }),
            });

            const response = await send(token);
            if (response.status === 419 && document.visibilityState === 'visible') {
                token = await refreshCsrf(signal);
                if (token && document.visibilityState === 'visible') {
                    await send(token);
                }
            }
        }
    } catch {
        // A later visible heartbeat retries transient failures.
    } finally {
        clearTimeout(timeout);
        pending = false;
    }
}

document.addEventListener('visibilitychange', heartbeat);
window.addEventListener('pageshow', heartbeat);
setInterval(heartbeat, heartbeatInterval);
heartbeat();
