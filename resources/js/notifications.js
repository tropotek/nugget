// Browser notifications for what is new in the top bar's list. The list comes from the server
// and refreshes itself every minute; this only looks at it. The time of the newest one already
// raised is kept in localStorage, so a notification is raised once, in one tab.
const KEY = 'nugget.notified-at';
const AT_MOST = 3;

const supported = 'Notification' in window;

const announce = () => {
    const menu = document.querySelector('[data-notifications]');

    if (!menu || !supported) {
        return;
    }

    const enable = menu.querySelector('[data-notifications-enable]');
    enable.hidden = Notification.permission !== 'default';
    enable.onclick = () => Notification.requestPermission().then(announce);

    const seen = Number(localStorage.getItem(KEY));

    // The first visit in this browser: start from now, and raise nothing that is already there.
    if (!seen) {
        localStorage.setItem(KEY, menu.dataset.now);

        return;
    }

    const fresh = [...menu.querySelectorAll('[data-unread]')].filter((item) => Number(item.dataset.at) > seen);

    if (fresh.length === 0) {
        return;
    }

    localStorage.setItem(KEY, String(Math.max(...fresh.map((item) => Number(item.dataset.at)))));

    if (Notification.permission !== 'granted') {
        return;
    }

    fresh.slice(0, AT_MOST).forEach((item) => {
        const raised = new Notification(item.dataset.title, { body: item.dataset.text, tag: item.href });

        raised.onclick = () => {
            window.focus();
            window.location.href = item.href;
        };
    });
};

announce();
document.body.addEventListener('htmx:afterSwap', announce);
