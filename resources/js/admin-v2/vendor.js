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

import { createIcons } from "lucide";
import { adminIcons } from "./icons.js";

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
    // The icon registry is shared with app.js so first paint and the
    // MutationObserver's re-renders always resolve the same set. See
    // ./icons.js for how to add one.
    createIcons({ icons: adminIcons });
});
