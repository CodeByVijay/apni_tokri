/**
 * Web push — Firebase Installation ID (FID) registration.
 * @see https://firebase.google.com/docs/cloud-messaging/js/client
 */
const SDK = '13.2.0';
const LS_DENIED = 'at_push_denied_v2';
const LS_FID = 'at_push_fid_v2';
const LS_CFG = 'at_push_cfg_version_v2';

function csrf() {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
}

async function fetchConfig() {
    const res = await fetch((window.AT_BASE || '') + '/ajax/push-config.php', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    const json = await res.json();
    return json && json.success ? json.data : null;
}

async function loadMessagingApi() {
    const { initializeApp, getApp, getApps } = await import(
        `https://www.gstatic.com/firebasejs/${SDK}/firebase-app.js`
    );
    const { getMessaging, register, onRegistered, onUnregistered, onMessage, unregister } = await import(
        `https://www.gstatic.com/firebasejs/${SDK}/firebase-messaging.js`
    );
    return { initializeApp, getApp, getApps, getMessaging, register, onRegistered, onUnregistered, onMessage, unregister };
}

async function sendFidToServer(cfg, fid, permission) {
    await fetch(cfg.registerUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({
            fid,
            platform: 'web',
            target_type: 'fid',
            permission,
        }),
    });
    localStorage.setItem(LS_FID, fid);
    localStorage.setItem(LS_CFG, cfg.configVersion || '');
}

async function removeFidFromServer(cfg, fid) {
    if (!fid) {
        return;
    }
    await fetch(cfg.unregisterUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrf(),
        },
        body: JSON.stringify({ fid, platform: 'web', target_type: 'fid' }),
    });
}

let messagingApi = null;
let messagingInstance = null;
let fidHandlersReady = false;

async function ensureMessaging(cfg) {
    if (!messagingApi) {
        messagingApi = await loadMessagingApi();
    }
    const { initializeApp, getApp, getApps, getMessaging, onRegistered, onUnregistered, onMessage } = messagingApi;
    if (getApps().length === 0) {
        initializeApp(cfg.firebase);
    }
    const app = getApp();
    if (!messagingInstance) {
        messagingInstance = getMessaging(app);
    }
    if (!fidHandlersReady) {
        onRegistered(messagingInstance, (installationId) => {
            const perm = Notification.permission;
            sendFidToServer(cfg, installationId, perm).catch(() => {});
        });
        onUnregistered(messagingInstance, (installationId) => {
            removeFidFromServer(cfg, installationId).catch(() => {});
            if (localStorage.getItem(LS_FID) === installationId) {
                localStorage.removeItem(LS_FID);
            }
        });
        onMessage(messagingInstance, (payload) => {
            const title = (payload.notification && payload.notification.title) || 'Veggie Tokri';
            const body = (payload.notification && payload.notification.body) || '';
            if (Notification.permission === 'granted') {
                new Notification(title, { body, data: payload.data || {} });
            }
        });
        fidHandlersReady = true;
    }
    return messagingApi;
}

async function enablePush(btn) {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        alert('This browser does not support push notifications.');
        return;
    }
    if (Notification.permission === 'denied' || localStorage.getItem(LS_DENIED) === '1') {
        alert('Notifications are blocked. Enable them in browser settings.');
        return;
    }
    const cfg = await fetchConfig();
    if (!cfg || !cfg.enabled) {
        alert('Push notifications are not configured yet.');
        return;
    }
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
        localStorage.setItem(LS_DENIED, '1');
        return;
    }
    const reg = await navigator.serviceWorker.register(cfg.serviceWorkerUrl, { scope: '/' });
    reg.active && reg.active.postMessage({
        type: 'PUSH_INIT',
        firebase: cfg.firebase,
        vapidKey: cfg.vapidKey,
        configVersion: cfg.configVersion,
    });
    const api = await ensureMessaging(cfg);
    await api.register(messagingInstance, {
        vapidKey: cfg.vapidKey,
        serviceWorkerRegistration: reg,
    });
    if (btn) {
        btn.textContent = 'Notifications enabled';
        btn.disabled = true;
    }
}

async function disablePush(btn) {
    const cfg = await fetchConfig();
    if (cfg) {
        const api = await ensureMessaging(cfg);
        await api.unregister(messagingInstance);
    }
    localStorage.removeItem(LS_FID);
    if (btn) {
        btn.textContent = 'Enable notifications';
        btn.disabled = false;
    }
}

function wireUi() {
    const enableBtn = document.getElementById('at-push-enable');
    const disableBtn = document.getElementById('at-push-disable');
    enableBtn && enableBtn.addEventListener('click', () => enablePush(enableBtn));
    disableBtn && disableBtn.addEventListener('click', () => disablePush(disableBtn));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireUi);
} else {
    wireUi();
}

window.ATPush = { enablePush, disablePush, fetchConfig };
