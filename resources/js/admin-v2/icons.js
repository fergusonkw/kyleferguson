/**
 * Admin Starter — Lucide icon registry
 *
 * Single source of truth for the icons the admin panel ships. Both the vendor
 * bundle's initial render and app.js's MutationObserver import this map, so
 * an icon can never be renderable on first paint but not on re-render.
 *
 * The import list is explicit rather than `import { icons } from "lucide"` so
 * the bundle stays tree-shaken to what the admin actually uses.
 *
 * ADDING AN ICON: add the PascalCase export here, then reference it in Blade
 * as its kebab-case name (`Building2` → `data-lucide="building-2"`). An icon
 * used in Blade but absent here renders nothing — see `renderIcons()` in
 * app.js, which neutralises the placeholder and warns in the console.
 */

import {
    AlarmClock, AlertCircle, AlertTriangle, Archive, ArrowLeft, ArrowRight,
    Ban, BanknoteArrowDown, BarChart, BarChart2, Bell, BellOff,
    BriefcaseBusiness, Building2,
    Calculator, Calendar, CalendarCheck, CalendarClock, CalendarDays,
    CalendarPlus, Camera, Car, ChartLine, Check, CheckCircle, CheckSquare,
    CheckCheck, ChevronDown, ChevronRight, ChevronUp, CircleCheck, CircleDot,
    CircleHelp,
    CircleUser, CircleX, ClipboardCheck, ClipboardList, Clock, Cloud,
    CloudUpload, Copy, Database, Download, Ellipsis, Eye, ExternalLink,
    File, FileBarChart2, FileCode, FileSearch, FileText, FileUp, Files,
    Filter, Flag, FolderKanban, FunctionSquare, Gavel, GitMerge, Globe,
    GripVertical, HandCoins, HelpCircle, Home, Image, Info, Key, KeyRound,
    LayoutDashboard, Lightbulb, Link, List, ListOrdered, Loader2, Lock,
    LockOpen, LogOut, Mail, MailX, Mails, MapPin, Megaphone, Menu,
    MessageCircle, Monitor, Moon, Package, Pencil, PhoneCall, Play, Plus,
    PlusCircle, Printer, Receipt, RefreshCw, RotateCcw, RotateCw, Ruler,
    Save, Scale, Search, SendHorizontal, Settings, Settings2, ShieldCheck,
    ShieldUser,
    ShoppingCart, Sigma, Star, Sun, TableProperties, Timer, TriangleAlert,
    Trophy, Truck, Trash2, Undo, Upload, UserCog, UserMinus, UserPlus, Users,
    Wand, Wand2, Wrench, X, XCircle,
} from "lucide";

export const adminIcons = {
    AlarmClock, AlertCircle, AlertTriangle, Archive, ArrowLeft, ArrowRight,
    Ban, BanknoteArrowDown, BarChart, BarChart2, Bell, BellOff,
    BriefcaseBusiness, Building2,
    Calculator, Calendar, CalendarCheck, CalendarClock, CalendarDays,
    CalendarPlus, Camera, Car, ChartLine, Check, CheckCircle, CheckSquare,
    CheckCheck, ChevronDown, ChevronRight, ChevronUp, CircleCheck, CircleDot,
    CircleHelp,
    CircleUser, CircleX, ClipboardCheck, ClipboardList, Clock, Cloud,
    CloudUpload, Copy, Database, Download, Ellipsis, Eye, ExternalLink,
    File, FileBarChart2, FileCode, FileSearch, FileText, FileUp, Files,
    Filter, Flag, FolderKanban, FunctionSquare, Gavel, GitMerge, Globe,
    GripVertical, HandCoins, HelpCircle, Home, Image, Info, Key, KeyRound,
    LayoutDashboard, Lightbulb, Link, List, ListOrdered, Loader2, Lock,
    LockOpen, LogOut, Mail, MailX, Mails, MapPin, Megaphone, Menu,
    MessageCircle, Monitor, Moon, Package, Pencil, PhoneCall, Play, Plus,
    PlusCircle, Printer, Receipt, RefreshCw, RotateCcw, RotateCw, Ruler,
    Save, Scale, Search, SendHorizontal, Settings, Settings2, ShieldCheck,
    ShieldUser,
    ShoppingCart, Sigma, Star, Sun, TableProperties, Timer, TriangleAlert,
    Trophy, Truck, Trash2, Undo, Upload, UserCog, UserMinus, UserPlus, Users,
    Wand, Wand2, Wrench, X, XCircle,
};

export default adminIcons;
