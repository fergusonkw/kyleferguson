/**
 * Admin Starter — vendor bundle
 *
 * Brings in the libraries every admin-v2 page expects: Preline UI,
 * DataTables (default theme), Choices.js, Simplebar, Lucide icons.
 * No Bootstrap or jQuery — Preline's native APIs are used throughout.
 */

import "../../css/admin-v2/app.css";

import { HSStaticMethods, HSOverlay } from "preline";
import "simplebar";

import Choices from "choices.js";
window.Choices = Choices;

import Swal from "sweetalert2";

/**
 * Alert — SweetAlert2 utility.
 *
 * Provides a consistent notification API used by all admin-v2 pages and
 * page-specific JS bundles. All methods are available as window.Alert.*
 */
const Alert = {
    /** Short toast — type is 'success' | 'error' | 'warning' | 'info' */
    toast(message, type = "info") {
        Swal.fire({
            toast: true,
            position: "top-end",
            icon: type,
            title: message,
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true,
        });
    },

    error(message, title = "Error") {
        return Swal.fire({ icon: "error", title, text: message });
    },

    warning(message, title = "Warning") {
        return Swal.fire({ icon: "warning", title, text: message });
    },

    success(message, title = "Success") {
        return Swal.fire({ icon: "success", title, text: message });
    },

    info(message, title = "Info") {
        return Swal.fire({ icon: "info", title, text: message });
    },

    html(html, title = "") {
        return Swal.fire({ title, html });
    },

    /** Returns Promise<boolean> */
    async confirm(message, title = "Are you sure?", confirmText = "Yes", cancelText = "Cancel") {
        const result = await Swal.fire({
            icon: "question",
            title,
            text: message,
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: cancelText,
        });
        return result.isConfirmed;
    },

    /** Returns Promise<boolean> */
    async confirmDelete(message = "This action cannot be undone.", title = "Delete?") {
        const result = await Swal.fire({
            icon: "warning",
            title,
            text: message,
            showCancelButton: true,
            confirmButtonText: "Yes, Delete",
            cancelButtonText: "Cancel",
            confirmButtonColor: "#dc3545",
        });
        return result.isConfirmed;
    },

    loading(title = "Loading…", message = "") {
        Swal.fire({
            title,
            text: message,
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading(),
        });
    },

    close() {
        Swal.close();
    },
};

window.Alert = Alert;

import DataTable from "datatables.net-dt";
import "datatables.net-responsive";
import "datatables.net-select";
import "datatables.net-buttons";
window.DataTable = DataTable;

import {
    createIcons,
    // brand / decoration
    CircleDot,
    Package,
    ShieldCheck,
    Trophy,
    Check,
    // sidenav — main
    LayoutDashboard,
    CalendarDays,
    FileUp,
    Users,
    // sidenav — master data
    Building2,
    Sigma,
    Truck,
    Files,
    Image,
    Gavel,
    Megaphone,
    ClipboardList,
    // sidenav — system
    UserCog,
    KeyRound,
    FileBarChart2,
    Database,
    Timer,
    BarChart2,
    Wrench,
    // topbar / layout
    Menu,
    ChevronDown,
    Mails,
    Bell,
    Moon,
    Sun,
    CircleUser,
    LogOut,
    Settings,
    Settings2,
    X,
    // components — alerts, page-title, help, card
    AlertCircle,
    AlertTriangle,
    CircleCheck,
    Home,
    HelpCircle,
    Info,
    Lightbulb,
    ChevronUp,
    // dashboard stat-card icons
    CalendarClock,
    ClipboardCheck,
    Car,
    CalendarCheck,
    ShieldUser,
    // page action icons (CRUD, nav, status)
    Plus,
    Eye,
    Pencil,
    Trash2,
    GitMerge,
    UserMinus,
    UserPlus,
    ArrowRight,
    ArrowLeft,
    ChevronRight,
    RefreshCw,
    Star,
    Globe,
    Wand2,
    Search,
    Printer,
    MessageCircle,
    MapPin,
    MailX,
    LockOpen,
    Lock,
    ListOrdered,
    GripVertical,
    Flag,
    Download,
    Save,
    Copy,
    Clock,
    Camera,
    Calculator,
    BellOff,
    Archive,
    Ban,
    ShoppingCart,
    TableProperties,
    FileText,
    PhoneCall,
    Mail,
    ExternalLink,
    Link,
    RotateCcw,
    // additional — used across pages but previously unregistered
    BarChart,
    Calendar,
    CheckCircle,
    CheckSquare,
    ChartLine,
    CircleX,
    CloudUpload,
    File,
    FileCode,
    FileSearch,
    Filter,
    FunctionSquare,
    Key,
    List,
    Loader2,
    Monitor,
    Play,
    PlusCircle,
    RotateCw,
    Ruler,
    CalendarPlus,
    Ellipsis,
    TriangleAlert,
    Undo,
    Upload,
    Wand,
    XCircle,
    BriefcaseBusiness,
    Receipt,
    Cloud,
    FolderKanban,
} from "lucide";

// Module scripts execute after document parse but, depending on cache /
// load order, can fire BEFORE OR AFTER `DOMContentLoaded`. A bare
// `addEventListener("DOMContentLoaded", ...)` registered after the event
// already fired would never run, leaving icons unrendered and Preline
// uninitialised. The readyState check below covers both orderings.
function ready(fn) {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
        fn();
    }
}

// Expose Preline's static helpers so dynamically-rendered markup (modals,
// DataTables rows, AJAX-injected partials) can re-init components with
// `window.HSStaticMethods.autoInit()`.
window.HSStaticMethods = HSStaticMethods;
window.HSOverlay = HSOverlay;

ready(() => {
    HSStaticMethods.autoInit();
    // Tree-shakable: only the icons referenced by name actually ship in the
    // bundle. Pages that need more icons either (a) extend this list, or
    // (b) import + register additional icons from their own page-specific
    // entry. F.4 sub-sessions will refactor this once we know each page's
    // icon footprint.
    createIcons({
        icons: {
            CircleDot, Package, ShieldCheck, Trophy, Check,
            LayoutDashboard, CalendarDays, FileUp, Users,
            Building2, Sigma, Truck, Files, Image, Gavel, Megaphone, ClipboardList,
            UserCog, KeyRound, FileBarChart2, Database, Timer, BarChart2, Wrench,
            Menu, ChevronDown, Mails, Bell, Moon, Sun, CircleUser, LogOut,
            Settings, Settings2, X,
            AlertCircle, AlertTriangle, CircleCheck, Home, HelpCircle, Info, Lightbulb, ChevronUp,
            CalendarClock, ClipboardCheck, Car, CalendarCheck, ShieldUser,
            Plus, Eye, Pencil, Trash2, GitMerge, UserMinus, UserPlus,
            ArrowRight, ArrowLeft, ChevronRight, RefreshCw, Star, Globe,
            Wand2, Search, Printer, MessageCircle, MapPin, MailX, LockOpen,
            Lock, ListOrdered, GripVertical, Flag, Download, Save, Copy,
            Clock, Camera, Calculator, BellOff, Archive, Ban, ShoppingCart,
            TableProperties, FileText, PhoneCall, Mail, ExternalLink, Link, RotateCcw,
            BarChart, Calendar, CheckCircle, CheckSquare, ChartLine, CircleX,
            CloudUpload, File, FileCode, FileSearch, Filter, FunctionSquare,
            Key, List, Loader2, Monitor, Play, PlusCircle, RotateCw, Ruler,
            CalendarPlus, Ellipsis, TriangleAlert, Undo, Upload, Wand, XCircle,
            BriefcaseBusiness, Receipt, Cloud, FolderKanban,
        },
    });
});
