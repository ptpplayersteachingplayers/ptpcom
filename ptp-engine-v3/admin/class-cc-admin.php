<?php
/**
 * CC_Admin — PTP Engine v3 Admin Loader
 *
 * Replaces the old monolithic inline-JS admin with a proper React app.
 * Enqueues ptp-app.js (compiled from ptp-app.jsx) and passes API config.
 *
 * @since 3.0
 */
if (!defined('ABSPATH')) exit;

class CC_Admin {

    /**
     * Register the single admin menu page.
     * All navigation happens inside the React app.
     */
    public static function register_menu() {
        // Main menu item
        $hook = add_menu_page(
            'PTP Engine',
            'PTP Engine',
            'manage_options',
            'ptp-engine',
            [__CLASS__, 'render_fullpage'],
            'dashicons-superhero',
            3
        );

        // Hidden submenu aliases for backward compatibility (old bookmarks)
        add_submenu_page(null, 'Command Center', 'Command Center', 'manage_options', 'ptp-command-center', [__CLASS__, 'render_fullpage']);
        add_submenu_page(null, 'Comms Hub', 'Comms Hub', 'manage_options', 'ptp-comms-hub', [__CLASS__, 'render_fullpage']);
        add_submenu_page(null, 'OpenPhone', 'OpenPhone', 'manage_options', 'ptp-openphone-platform', [__CLASS__, 'render_fullpage']);

        // Enqueue scripts on our page
        add_action('admin_enqueue_scripts', function ($page_hook) use ($hook) {
            // Only load on our admin page
            if ($page_hook !== $hook
                && $page_hook !== 'admin_page_ptp-command-center'
                && $page_hook !== 'admin_page_ptp-comms-hub'
                && $page_hook !== 'admin_page_ptp-openphone-platform'
            ) {
                return;
            }

            // React deps from WordPress
            wp_enqueue_script('wp-element');

            // Our app — either the compiled build or dev version
            $app_url = defined('PTP_ENGINE_DIR')
                ? plugins_url('assets/ptp-app.js', PTP_ENGINE_DIR . '/ptp-engine.php')
                : plugin_dir_url(__FILE__) . '../assets/ptp-app.js';

            wp_enqueue_script(
                'ptp-engine-app',
                $app_url,
                ['wp-element'],
                defined('PTP_ENGINE_VER') ? PTP_ENGINE_VER : '3.0',
                true
            );

            // Pass config to the app
            wp_localize_script('ptp-engine-app', 'PTP_ENGINE', [
                'api'   => rest_url('ptp-cc/v1'),
                'nonce' => wp_create_nonce('wp_rest'),
                'user'  => wp_get_current_user()->display_name,
                'admin' => admin_url(),
                'ver'   => defined('PTP_ENGINE_VER') ? PTP_ENGINE_VER : '3.0',
            ]);
        });
    }

    /**
     * Render the full-page admin app.
     *
     * This outputs a clean HTML shell that:
     * 1. Hides the WP admin chrome (sidebar, admin bar, footer)
     * 2. Loads Google Fonts
     * 3. Mounts the React app on #ptp-root
     *
     * The React app handles ALL navigation internally.
     */
    public static function render_fullpage() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Get config values for inline fallback
        $api   = rest_url('ptp-cc/v1');
        $nonce = wp_create_nonce('wp_rest');
        $user  = wp_get_current_user()->display_name;

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PTP Engine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
html, body { overflow: hidden !important; height: 100vh !important; }
body { font-family: 'DM Sans', -apple-system, sans-serif; background: #F5F4F0; color: #1C1B18; }

/* Hide WP admin chrome */
#wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter { display: none !important; }
#wpcontent { margin-left: 0 !important; padding-left: 0 !important; }
#wpbody, #wpbody-content { padding: 0 !important; float: none !important; overflow: hidden !important; height: 100vh !important; }

/* Scrollbar */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-thumb { background: #C8C7C3; }
::selection { background: #FCB900; color: #0A0A0A; }

/* React root takes full viewport */
#ptp-root { width: 100%; height: 100vh; overflow: hidden; }

/* Loading state */
.ptp-loading {
    display: flex; align-items: center; justify-content: center;
    height: 100vh; width: 100%;
    font-family: 'Oswald', sans-serif; font-size: 14px;
    text-transform: uppercase; letter-spacing: 2px; color: #918F89;
    animation: ptp-pulse 1.5s ease-in-out infinite;
}
@keyframes ptp-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
</style>
</head>
<body>

<div id="ptp-root">
    <div class="ptp-loading">Loading PTP Engine v3...</div>
</div>

<script>
// Config available before React loads (fallback if wp_localize_script missed)
window.PTP_ENGINE = window.PTP_ENGINE || {
    api:   <?php echo wp_json_encode($api); ?>,
    nonce: <?php echo wp_json_encode($nonce); ?>,
    user:  <?php echo wp_json_encode($user); ?>,
    admin: <?php echo wp_json_encode(admin_url()); ?>,
    ver:   '3.0'
};
</script>

<?php
        // WordPress will have enqueued our scripts via admin_enqueue_scripts
        // But since we're doing a full-page exit, we need to manually print them
        wp_print_scripts(['wp-element', 'ptp-engine-app']);
        ?>

<script>
// Mount React app
(function() {
    // Wait for React and our app to be available
    function mount() {
        var root = document.getElementById('ptp-root');
        if (window.wp && window.wp.element && window.PTPEngineApp) {
            var el = window.wp.element;
            if (el.createRoot) {
                // React 18+
                el.createRoot(root).render(el.createElement(window.PTPEngineApp.default || window.PTPEngineApp));
            } else {
                // React 17 fallback
                el.render(el.createElement(window.PTPEngineApp.default || window.PTPEngineApp), root);
            }
        } else {
            // Retry in case scripts are still loading
            setTimeout(mount, 100);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
</script>

</body>
</html>
<?php
        exit;
    }
}
