import QRCode from 'qrcode';
import './echo';

document.addEventListener('alpine:init', () => {
    /**
     * Copy text to the clipboard and briefly show a "Copied" state.
     */
    window.Alpine.data('copyText', (text) => ({
        copied: false,
        async copy() {
            try {
                await navigator.clipboard.writeText(text);
            } catch {
                const field = document.createElement('textarea');
                field.value = text;
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }
            this.copied = true;
            setTimeout(() => (this.copied = false), 2000);
        },
    }));

    /**
     * Draw a Tourlast-navy QR code for a referral link, with a PNG download.
     */
    window.Alpine.data('qrCode', (text, filename) => ({
        init() {
            QRCode.toCanvas(this.$refs.canvas, text, {
                width: 200,
                margin: 1,
                color: { dark: '#0C5295', light: '#FFFFFF' },
            });
        },
        download() {
            const link = document.createElement('a');
            link.href = this.$refs.canvas.toDataURL('image/png');
            link.download = filename;
            link.click();
        },
    }));
});
