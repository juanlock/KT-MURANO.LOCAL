/** NProgress facade over the app's own top progress bar. */
import { start, done } from '../lib/progress';

export default {
    start,
    done,
    set() { /* legacy calls set(0.1) right after start — the bar trickles on its own */ },
};
