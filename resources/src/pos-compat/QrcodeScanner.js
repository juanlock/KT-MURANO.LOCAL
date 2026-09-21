/**
 * Port of legacy main.js's global `qrcode-scanner` component. Uses the
 * html5-qrcode library from the global `Html5QrcodeScanner` (legacy loaded it
 * the same way); when the global is absent the scanner area stays empty
 * instead of crashing the modal.
 */
import { h, defineComponent } from 'vue';

export default defineComponent({
    name: 'QrcodeScanner',
    props: {
        qrbox: { type: Number, default: 250 },
        fps: { type: Number, default: 10 },
    },
    emits: ['result'],
    data() {
        return { isFirstScan: true, scanner: null };
    },
    mounted() {
        const Ctor = window.Html5QrcodeScanner;
        if (!Ctor) return;
        this.scanner = new Ctor('reader', { fps: this.fps, qrbox: this.qrbox });
        this.scanner.render((decodedText, decodedResult) => {
            if (this.isFirstScan) {
                this.isFirstScan = false;
                this.$emit('result', decodedText, decodedResult);
            } else {
                try { this.scanner.stop(); } catch (e) { /* already stopped */ }
            }
        });
    },
    beforeUnmount() {
        try { this.scanner?.clear(); } catch (e) { /* fine */ }
    },
    render() {
        return h('div', { id: 'reader' });
    },
});
