/**
 * Admin Starter — application JS
 *
 * Sidenav toggle, responsive layout, active-link detection, theme
 * persistence, and Lucide icon re-creation for dynamically injected markup.
 */

import { createIcons } from "lucide";
import { adminIcons } from "./icons.js";

const STORAGE_KEY = "__THEME_CONFIG__";
const html = document.documentElement;

// ---------- config helpers ----------

function getConfig() {
    const raw = localStorage.getItem(STORAGE_KEY) ?? sessionStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : (window.config ?? {});
}

function persistConfig(patch) {
    const config = getConfig();
    const next = { ...config, ...patch };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    window.config = next;
}

// ---------- sidenav size ----------

function changeSidenavSize(size, save = true) {
    html.setAttribute("data-sidenav-size", size);
    if (save) {
        persistConfig({ "sidenav-size": size });
    }
}

// Mirrors LayoutCustomizer._toggleSidebar() from the Inspinia 5 reference.
// Default config: toggle default ↔ condensed (icons-only, 75 px).
// Compact config: toggle compact ↔ condensed.
// Offcanvas (mobile): show backdrop + slide in.
function toggleSidebar() {
    const current = html.getAttribute("data-sidenav-size");
    const configSize = getConfig()["sidenav-size"] || "default";

    if (current === "offcanvas") {
        showBackdrop();
    } else if (configSize === "compact") {
        changeSidenavSize(current === "condensed" ? "compact" : "condensed", false);
    } else {
        changeSidenavSize(current === "condensed" ? "default" : "condensed", true);
    }

    html.classList.toggle("sidenav-enable");
}

// Responsive breakpoints mirror the Inspinia 5 reference exactly.
function adjustLayout() {
    const width = window.innerWidth;
    const configSize = getConfig()["sidenav-size"] || "default";

    if (width <= 767.98) {
        changeSidenavSize("offcanvas", false);
    } else if (width <= 1140) {
        changeSidenavSize("condensed", false);
    } else {
        changeSidenavSize(configSize, false);
    }
}

// ---------- mobile backdrop ----------

function showBackdrop() {
    const backdrop = document.createElement("div");
    backdrop.id = "custom-backdrop";
    backdrop.className = "transition duration fixed inset-0 bg-default-900/50 z-40";
    document.body.appendChild(backdrop);
    backdrop.addEventListener("click", () => {
        html.classList.remove("sidenav-enable");
        hideBackdrop();
    });
}

function hideBackdrop() {
    const backdrop = document.getElementById("custom-backdrop");
    if (backdrop) {
        document.body.removeChild(backdrop);
    }
}

// ---------- sidenav init ----------

function initSidenav() {
    // Toggle button
    const toggleBtn = document.querySelector('[data-toggle="sidenav-size"]');
    if (toggleBtn) {
        toggleBtn.addEventListener("click", (event) => {
            event.preventDefault();
            toggleSidebar();
        });
    }

    // Mark the active link and open its parent accordions based on the
    // current URL, mirroring App.initSidenav() from the Inspinia 5 reference.
    const pageUrl = window.location.href.split(/[?#]/)[0];
    document.querySelectorAll("ul.side-nav .menu-item > a").forEach((link) => {
        if (link.href !== pageUrl) {
            return;
        }

        link.classList.add("active");

        const parentLi = link.closest(".menu-item");
        if (parentLi) {
            parentLi.classList.add("active");
        }

        // Walk up through nested hs-accordion parents and open each one.
        let accordion = link.closest(".hs-accordion");
        while (accordion) {
            accordion.classList.add("active");
            const toggle = accordion.querySelector(":scope > .hs-accordion-toggle");
            if (toggle) {
                toggle.classList.add("active");
                toggle.setAttribute("aria-expanded", "true");
            }
            const content = accordion.querySelector(":scope > .hs-accordion-content");
            if (content) {
                content.classList.remove("hidden");
            }
            accordion = accordion.parentElement?.closest(".hs-accordion");
        }
    });

    // Scroll the active item into view after Simplebar has initialised.
    setTimeout(() => {
        const activeLink = document.querySelector("ul.side-nav .menu-item.active a.active");
        const scrollContainer = document.querySelector("#app-menu .simplebar-content-wrapper");
        if (activeLink && scrollContainer) {
            const offset = activeLink.offsetTop - 265;
            if (offset > 100) {
                smoothScrollTo(scrollContainer, offset, 600);
            }
        }
    }, 200);
}

function smoothScrollTo(element, to, duration) {
    const start = element.scrollTop;
    const change = to - start;
    let currentTime = 0;
    const increment = 20;

    function easeInOutQuad(t, b, c, d) {
        t /= d / 2;
        if (t < 1) { return (c / 2) * t * t + b; }
        t--;
        return (-c / 2) * (t * (t - 2) - 1) + b;
    }

    (function tick() {
        currentTime += increment;
        element.scrollTop = easeInOutQuad(currentTime, start, change, duration);
        if (currentTime < duration) {
            setTimeout(tick, increment);
        }
    })();
}

// ---------- theme toggle ----------

function initThemeToggle() {
    document.querySelectorAll('[data-toggle="theme"]').forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            const current = html.getAttribute("data-theme") || "light";
            const next = current === "dark" ? "light" : "dark";
            html.setAttribute("data-theme", next);
            persistConfig({ theme: next });
        });
    });
}

// ---------- Lucide icon rendering ----------
// Re-creates icons whenever new placeholder <i data-lucide> tags appear in
// the DOM (Preline modals, DataTables rows, AJAX partials).
// The observer guard filters to <i>/<span> — Lucide preserves `data-lucide`
// on its rendered <svg>, so matching SVGs would spin forever.

/**
 * Render every outstanding icon placeholder.
 *
 * Lucide leaves a placeholder untouched (console.warn only) when its name is
 * not in the registry. That would keep the observer guard below matching
 * forever, and every pass re-replaces all rendered SVGs — a mutation that
 * retriggers the observer, looping the page until it locks up. So any
 * placeholder still standing after a render pass has its `data-lucide`
 * swapped for an inert marker: the icon is simply missing, which is a
 * cosmetic bug, instead of hanging the page.
 */
function renderIcons() {
    createIcons({ icons: adminIcons });

    document.querySelectorAll("i[data-lucide], span[data-lucide]").forEach((el) => {
        const name = el.getAttribute("data-lucide");
        el.removeAttribute("data-lucide");
        el.setAttribute("data-lucide-unregistered", name);
        console.warn(
            `[icons] "${name}" is not registered in resources/js/admin-v2/icons.js — nothing rendered.`,
        );
    });
}

const iconObserver = new MutationObserver(() => {
    if (document.querySelector("i[data-lucide], span[data-lucide]")) {
        renderIcons();
    }
});

// ---------- overlay backdrop cleanup ----------
// Offcanvas panels and modals are opened with the static `HSOverlay.open()`
// API but dismissed via `[data-hs-overlay]` cancel/close buttons. That mix can
// leave Preline's `.hs-overlay-backdrop` (and the body scroll-lock) behind, so
// the page stays greyed out after the panel slides away. Once the last overlay
// has closed, remove any stray backdrop and restore scrolling. Idempotent.

function sweepStrayOverlayBackdrops() {
    if (document.querySelector(".hs-overlay.open")) {
        return;
    }

    document.querySelectorAll(".hs-overlay-backdrop").forEach((el) => el.remove());
    document.documentElement.style.removeProperty("overflow");
    document.body.style.removeProperty("overflow");
}

function initOverlayBackdropCleanup() {
    // Run after Preline's own close teardown + the 300ms slide transition.
    const schedule = () => setTimeout(sweepStrayOverlayBackdrops, 350);

    document.addEventListener("close.hs.overlay", schedule);
    document.addEventListener("click", (event) => {
        if (event.target.closest("[data-hs-overlay]")) {
            schedule();
        }
    });
}

// ---------- boot ----------

function ready(fn) {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
        fn();
    }
}

ready(() => {
    adjustLayout();
    initSidenav();
    initThemeToggle();
    initOverlayBackdropCleanup();
    renderIcons();

    window.addEventListener("resize", adjustLayout);
    iconObserver.observe(document.body, { childList: true, subtree: true });
});
