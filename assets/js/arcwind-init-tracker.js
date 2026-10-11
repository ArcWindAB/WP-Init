(function (window) {
    if (!window.ArcWindInit) {
        window.ArcWindInit = {};
    }

    const config = window.ArcWindInitConfig || {};

    window.ArcWindInit.track = function (eventName, properties) {
        if (!window.posthog || typeof window.posthog.capture !== "function") {
            return false;
        }

        const merged = Object.assign({
            source: "wordpress",
            source_type: "client",
            plugin: "arcwind-init",
            plugin_ver: config.version || "",
            page_type: config.pageType || "",
            is_admin: !!config.isAdmin
        }, properties || {});

        try {
            window.posthog.capture(eventName, merged);
            return true;
        } catch (error) {
            return false;
        }
    };

    window.ArcWindInit.identify = function (distinctId, properties) {
        if (!window.posthog || typeof window.posthog.identify !== "function" || !distinctId) {
            return false;
        }

        try {
            window.posthog.identify(distinctId, properties || {});
            return true;
        } catch (error) {
            return false;
        }
    };
})(window);
