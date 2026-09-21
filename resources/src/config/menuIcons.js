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
    FolderArchive, Truck, HeartPulse, GraduationCap, Plug, ChefHat,
} from 'lucide-vue-next';

export const MENU_ICONS = {
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
};
