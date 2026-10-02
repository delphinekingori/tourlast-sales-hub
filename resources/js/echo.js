import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Live notifications. Connects to the Reverb server (which runs on another
 * site) and nudges the bell and the Notifications page to refresh when a new
 * alert or announcement arrives. Does nothing when no Reverb key is configured,
 * so the pages simply keep polling.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Pusher = Pusher;

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    let channelName = null;

    const connect = () => {
        const userId = document.querySelector('meta[name="hub-user-id"]')?.content;

        if (!userId || channelName === `App.Models.User.${userId}`) {
            return;
        }

        window.Echo ??= new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
        });

        if (channelName) {
            window.Echo.leave(channelName);
        }

        channelName = `App.Models.User.${userId}`;

        const refresh = (title) => {
            window.Livewire?.dispatch('inbox-updated');

            if (title) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: title } }));
            }
        };

        window.Echo.private(channelName)
            .notification((alert) => refresh(alert.title))
            .listen('.announcement.published', (announcement) => refresh(announcement.title));
    };

    document.addEventListener('livewire:init', connect);
    document.addEventListener('livewire:navigated', connect);
}
