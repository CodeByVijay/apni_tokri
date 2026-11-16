/** @deprecated Use push-notifications.mjs (FID registration). Kept so old cached HTML does not 404. */
(function () {
    if (window.ATPush && typeof window.ATPush.enablePush === 'function') {
        return;
    }
    console.warn('Load push-notifications.mjs for web push.');
})();
