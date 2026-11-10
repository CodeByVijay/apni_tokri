/* eslint-disable no-undef */
/**
 * Firebase Cloud Messaging service worker (Web Push).
 * Firebase JS SDK 13.2.0 (compat — background handler only; FID registration runs in page module).
 */
importScripts('https://www.gstatic.com/firebasejs/13.2.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/13.2.0/firebase-messaging-compat.js');

let messaging = null;
let currentConfigVersion = '';

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type === 'PUSH_INIT' && data.firebase && data.vapidKey) {
        if (data.configVersion && data.configVersion === currentConfigVersion && messaging) {
            return;
        }
        currentConfigVersion = data.configVersion || '';
        firebase.initializeApp(data.firebase);
        messaging = firebase.messaging();
        messaging.onBackgroundMessage((payload) => {
            const title = (payload.notification && payload.notification.title) || 'Veggie Tokri';
            const options = {
                body: (payload.notification && payload.notification.body) || '',
                data: payload.data || {},
                icon: payload.notification && payload.notification.icon,
            };
            self.registration.showNotification(title, options);
        });
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data = event.notification.data || {};
    const target = data.click_url || data.link || '/';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (const client of windowClients) {
                if ('focus' in client) {
                    client.navigate(target);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(target);
            }
            return undefined;
        })
    );
});
