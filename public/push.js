// Browser push setup for the Settings page buttons. Plain script (no build step).
(() => {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body ?? {}),
    });

    const keyBytes = (base64url) => {
        const base64 = (base64url + '='.repeat((4 - base64url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    };

    const registration = async () => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            throw new Error('This browser does not support push notifications.');
        }
        await navigator.serviceWorker.register('/sw.js');
        return navigator.serviceWorker.ready;
    };

    window.bidPush = {
        async enable() {
            try {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') return 'Notifications are blocked for this site. Allow them in the browser settings.';

                const reg = await registration();
                const { key } = await (await fetch('/push/key', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
                let sub = await reg.pushManager.getSubscription();
                const current = sub?.options?.applicationServerKey;
                if (sub && current && btoa(String.fromCharCode(...new Uint8Array(current))) !== btoa(String.fromCharCode(...keyBytes(key)))) {
                    await sub.unsubscribe();
                    sub = null;
                }
                sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });

                const res = await post('/push/subscribe', sub.toJSON());
                if (!res.ok) return 'Could not save the subscription (' + res.status + ').';
                const { devices } = await res.json();
                return 'Push enabled on this device. Subscribed devices: ' + devices + '.';
            } catch (e) {
                return 'Push could not be enabled: ' + e.message;
            }
        },

        async disable() {
            try {
                const reg = await registration();
                const sub = await reg.pushManager.getSubscription();
                if (!sub) return 'This device was not subscribed.';
                await post('/push/unsubscribe', { endpoint: sub.endpoint });
                await sub.unsubscribe();
                return 'Push disabled on this device.';
            } catch (e) {
                return 'Could not disable push: ' + e.message;
            }
        },

        async test() {
            try {
                const res = await post('/push/test');
                if (!res.ok) return 'Test failed (' + res.status + ').';
                const { sent, devices } = await res.json();
                return devices === 0 ? 'No device is subscribed yet.' : 'Test sent to ' + sent + ' of ' + devices + ' device(s).';
            } catch (e) {
                return 'Test failed: ' + e.message;
            }
        },
    };
})();
