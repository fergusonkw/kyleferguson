{{-- Skin / theme bootstrap. Runs synchronously before CSS so the right
     skin variables are present on first paint. Mirrors Inspinia 5's
     shared/partials/head-css.blade.php pattern. --}}
<script>
    ;(function () {
        const html = document.documentElement;
        const storageKey = "__THEME_CONFIG__";
        const savedConfig = sessionStorage.getItem(storageKey);

        const defaultConfig = {
            "dir": "ltr",
            "skin": "material",
            "theme": "light",
            "width": "fluid",
            "position": "fixed",
            "orientation": "vertical",
            "sidenav-size": "default",
            "sidenav-user": true,
            "topbar-color": "light",
            "sidenav-color": "dark",
        };

        function getSystemTheme() {
            return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
        }

        const htmlConfig = {
            dir: html.getAttribute("dir") || defaultConfig.dir,
            skin: html.getAttribute("data-skin") || defaultConfig.skin,
            theme: html.getAttribute("data-theme") === "system"
                ? getSystemTheme()
                : html.getAttribute("data-theme") || defaultConfig.theme,
            "topbar-color": html.getAttribute("data-topbar-color") || defaultConfig["topbar-color"],
            "sidenav-color": html.getAttribute("data-menu-color") || defaultConfig["sidenav-color"],
            "sidenav-size": html.getAttribute("data-sidenav-size") || defaultConfig["sidenav-size"],
            "sidenav-user": html.hasAttribute("data-sidenav-user") || defaultConfig["sidenav-user"],
            position: html.getAttribute("data-layout-position") || defaultConfig.position,
            width: html.getAttribute("data-layout-width") || defaultConfig.width,
        };

        window.defaultConfig = structuredClone(htmlConfig);
        const config = savedConfig ? JSON.parse(savedConfig) : htmlConfig;
        window.config = config;

        // Material is the only skin we ship — coerce stale sessionStorage from
        // when alternative skins existed back to material so the user doesn't
        // end up on an unstyled page.
        config.skin = "material";

        html.setAttribute("dir", config.dir);
        html.setAttribute("data-skin", config.skin);
        html.setAttribute("data-theme", config.theme);
        html.setAttribute("data-topbar-color", config["topbar-color"]);
        html.setAttribute("data-menu-color", config["sidenav-color"]);
        html.setAttribute("data-layout-position", config.position);
        html.setAttribute("data-layout-width", config.width);

        if (config["sidenav-user"] === true) {
            html.setAttribute("data-sidenav-user", "true");
        } else {
            html.removeAttribute("data-sidenav-user");
        }

        if (config["sidenav-size"]) {
            let size = config["sidenav-size"];
            if (window.innerWidth <= 767.98) {
                size = "offcanvas";
            } else if (window.innerWidth <= 1140) {
                size = "condensed";
            }
            html.setAttribute("data-sidenav-size", size);
        }
    })();
</script>

@vite(['resources/js/admin-v2/vendor.js', 'resources/js/admin-v2/app.js'])
