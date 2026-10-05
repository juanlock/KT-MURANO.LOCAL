/**
 * The icons the legacy sidebar uses, so navigation looks identical after the
 * switch. Names are the kebab-case ones from menu.js (extracted from
 * Sidebar.vue's <lucide-icon name="...">).
 *
 * Imported by name (not `import *`) to keep tree-shaking: lucide ships ~1,700
 * icons and we need 26.
 */
import {
    BarChart3, ShoppingBag, Wallet, Users, ShieldCheck, LibraryBig, MapPin,
    Receipt, ShoppingCart, ChevronRight, ShoppingBasket, ChevronLeft, ArrowLeft,
    Library, CalendarDays, Megaphone, DollarSign, Wrench, Settings, Archive,
    ClipboardList, Check, Book, DatabaseZap, Lightbulb, TrendingUp, Percent,
    FolderArchive, Truck, HeartPulse, GraduationCap, Plug, ChefHat, Menu,
    LifeBuoy, LayoutGrid, Mails,
} from 'lucide-vue-next';

/**
 * A stable "random" icon for a module/menu that has no icon of its own (or an
 * icon name that no longer resolves) -- hashed from its key so the SAME item
 * always gets the SAME icon across reloads/re-renders, rather than actually
 * picking a new one every render (which would look broken, not "random").
 */
export function fallbackIcon(seed) {
    const s = String(seed || '');
    let hash = 0;
    for (let i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
    const keys = Object.keys(MENU_ICONS);
    return MENU_ICONS[keys[hash % keys.length]];
}

export const MENU_ICONS = {
    'life-buoy': LifeBuoy,
    'mails': Mails,
    'layout-grid': LayoutGrid,
    'bar-chart': BarChart3,
    'shopping-bag': ShoppingBag,
    'wallet': Wallet,
    'users': Users,
    'shield-check': ShieldCheck,
    'library-big': LibraryBig,
    'map-pin': MapPin,
    'receipt': Receipt,
    'shopping-cart': ShoppingCart,
    'chevron-right': ChevronRight,
    'shopping-basket': ShoppingBasket,
    'chevron-left': ChevronLeft,
    'arrow-left': ArrowLeft,
    'library': Library,
    'calendar-days': CalendarDays,
    'megaphone': Megaphone,
    'dollar-sign': DollarSign,
    'wrench': Wrench,
    'settings': Settings,
    'archive': Archive,
    'clipboard-list': ClipboardList,
    'check': Check,
    'book': Book,
    'database-zap': DatabaseZap,
    'lightbulb': Lightbulb,
    'trending-up': TrendingUp,
    'percent': Percent,
    'folder-archive': FolderArchive,
    'truck': Truck,
    'heart-pulse': HeartPulse,
    'graduation-cap': GraduationCap,
    'plug': Plug,
    'chef-hat': ChefHat,
    'menu': Menu,
};
