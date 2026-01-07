<?php
/*
Plugin Name: Nginx Cache in Redis
Description: Managing Nginx cache stored in Redis
Version: 1.0.0
Author: SimpleIT.pro
Author URI: https://www.simpleit.pro
Requires PHP: 8.2
*/

if (!defined('ABSPATH')) exit;


//update checker

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/SimpleITpro/nginx-redis-cache',
    __FILE__,
    'nginx-redis-cache'
);
$updateChecker->getVcsApi()->enableReleaseAssets();

// -----------------------------
// 1️⃣ Redis connection
// -----------------------------
function nrcf_get_redis() {
    if (!class_exists('Redis')) return false;

    $redis = new Redis();

    if (defined('NGINX_REDIS_SOCKET')) {
        $redis->connect(NGINX_REDIS_SOCKET);
    } else {
        $host = defined('NGINX_REDIS_HOST') ? NGINX_REDIS_HOST : '127.0.0.1';
        $port = defined('NGINX_REDIS_PORT') ? NGINX_REDIS_PORT : 6379;
        $timeout = defined('NGINX_REDIS_TIMEOUT') ? NGINX_REDIS_TIMEOUT : 1.5;
        $redis->connect($host, $port, $timeout);
    }

    if (defined('NGINX_REDIS_AUTH') && NGINX_REDIS_AUTH) {
        $redis->auth(NGINX_REDIS_AUTH);
    }

    if (defined('NGINX_REDIS_DB')) {
        $redis->select((int) NGINX_REDIS_DB);
    }

    return $redis;
}

function nrcf_is_configured() {
    return defined('NGINX_REDIS_HOST') || defined('NGINX_REDIS_SOCKET');
}

// -----------------------------
// 2️⃣ Cache key utilities
// -----------------------------
function nrcf_get_cache_prefix(): string {
    return defined('NGINX_REDIS_PREFIX') ? rtrim(NGINX_REDIS_PREFIX, ':') . ':' : 'nginx-cache:';
}

function nrcf_is_cache_key(string $key): bool {
    $prefix = nrcf_get_cache_prefix();

    // Must start with nginx prefix
    if (!str_starts_with($key, $prefix)) {
        return false;
    }

    // Strip prefix
    $key = substr($key, strlen($prefix));

    // Must start with scheme + GET
    if (!preg_match('#^(https?)GET#', $key)) {
        return false;
    }

    // Must end with Desktop or Mobile
    if (!preg_match('#(Desktop|Mobile)$#', $key)) {
        return false;
    }

    return true;
}

function nrcf_parse_cache_key(string $key): array {
    $original_key = $key;
    $prefix = nrcf_get_cache_prefix();
    $key_no_prefix = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;

    $device = 'All';
    if (str_ends_with($key_no_prefix, 'Desktop')) {
        $device = 'Desktop';
        $key_no_prefix = substr($key_no_prefix, 0, -7);
    } elseif (str_ends_with($key_no_prefix, 'Mobile')) {
        $device = 'Mobile';
        $key_no_prefix = substr($key_no_prefix, 0, -6);
    }

    $full_url = '';
    if (str_starts_with($key_no_prefix, 'httpGET')) {
        $full_url = 'http://' . substr($key_no_prefix, 7);
    } elseif (str_starts_with($key_no_prefix, 'httpsGET')) {
        $full_url = 'https://' . substr($key_no_prefix, 8);
    } else {
        $full_url = $key_no_prefix;
    }

    $full_url = rtrim($full_url, '/');
    $display_url = preg_replace('#^https?://#', '', $full_url);

    return [
        'url'      => $display_url,
        'device'   => $device,
        'full_url' => $full_url,
        'key'      => $original_key,
    ];
}

// -----------------------------
// 3️⃣ Get cached URLs
// -----------------------------
function nrcf_get_cached_urls(int $limit = 50): array {
    $redis = nrcf_get_redis();
    if (!$redis) {
        return [];
    }

    $results = [];
    $pattern = nrcf_get_cache_prefix() . '*';
    $it = null;

    do {
        $keys = $redis->scan($it, $pattern, 500);

        if ($keys === false) {
            continue;
        }

        foreach ($keys as $key) {

            // STRICT match for YOUR nginx config
            if (!preg_match(
                '#^' . preg_quote(nrcf_get_cache_prefix(), '#') . '(https?)GET.+(Desktop|Mobile)$#',
                $key
            )) {
                continue;
            }

            $parsed = nrcf_parse_cache_key($key);
            if (empty($parsed)) {
                continue;
            }

            $url = $parsed['url'];

            if (!isset($results[$url])) {
                $results[$url] = [
                    'url'     => $url,
                    'devices' => [],
                    'keys'    => [],
                ];
            }

            if (!in_array($parsed['device'], $results[$url]['devices'], true)) {
                $results[$url]['devices'][] = $parsed['device'];
            }

            $results[$url]['keys'][] = $parsed['key'];

            if (count($results) >= $limit) {
                break 2;
            }
        }

    } while ($it !== 0);

    return array_values($results);
}

// -----------------------------
// 4️⃣ Purge functions
// -----------------------------
function nrcf_flush_nginx_cache() {
    $redis = nrcf_get_redis();
    if (!$redis) return 0;

    $pattern = nrcf_get_cache_prefix() . '*';
    $it = null;
    $deleted = 0;

    while ($keys = $redis->scan($it, $pattern, 1000)) {
        if (!empty($keys)) $deleted += $redis->del($keys);
    }

    return $deleted;
}

function nrcf_purge_keys(array $keys): int {
    $redis = nrcf_get_redis();
    if (!$redis) return 0;
    return $redis->del($keys);
}

function nrcf_purge_url(string $url): int {
    $redis = nrcf_get_redis();
    if (!$redis) return 0;

    $prefix = nrcf_get_cache_prefix();
    $url_no_scheme = preg_replace('#^https?://#', '', $url);

    $deleted = 0;
    $it = null;
    while ($keys = $redis->scan($it, $prefix . '*' . $url_no_scheme . '*', 500)) {
        if (!$keys) continue;
        $deleted += $redis->del($keys);
    }
    return $deleted;
}

// -----------------------------
// 4️⃣ Admin Menu & Toolbar
// -----------------------------
add_action('admin_menu', function () {
    // Top-level menu
    add_menu_page(
        'Nginx Redis Cache',
        'Nginx Redis Cache',
        'manage_options',
        'nginx-redis-cache',
        'nrcf_cache_admin_page',
        'dashicons-database',
        80
    );

    // Submenu: Cached URLs
    add_submenu_page(
        'nginx-redis-cache',
        'Cached URLs',
        'Admin Panel',
        'manage_options',
        'nginx-redis-cache',
        'nrcf_cache_admin_page'
    );

});

// Add WP-Toolbar menu
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;

    // Top-level toolbar menu
    $wp_admin_bar->add_node([
        'id'    => 'nginx_redis_cache',
        'title' => 'Nginx Redis Cache',
        'href'  => admin_url('admin.php?page=nginx-redis-cache'),
        'meta'  => ['title' => 'Nginx Redis Cache']
    ]);

    // Submenu: Admin Panel (Cached URLs)
    $wp_admin_bar->add_node([
        'id'     => 'nginx_redis_cache_admin',
        'parent' => 'nginx_redis_cache',
        'title'  => 'Admin Panel',
        'href'   => admin_url('admin.php?page=nginx-redis-cache'),
        'meta'   => ['title' => 'Cached URLs']
    ]);

// Flush All Cache menu item
$wp_admin_bar->add_node([
    'id'     => 'nginx_redis_cache_flush',
    'parent' => 'nginx_redis_cache',
    'title'  => 'Flush All Cache',
    'href'   => wp_nonce_url(admin_url('admin.php?page=nginx-redis-cache&nrcf_flush_all=1'), 'nrcf_flush_all'),
]);

}, 100);

// Flush All Cache page
function nrcf_flush_cache_page() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['nrcf_flush_all'])) {
        check_admin_referer('nrcf_flush_all_action');
        $count = nrcf_flush_nginx_cache();
        echo '<div class="updated"><p>Deleted ' . intval($count) . ' cache keys.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Flush Nginx Redis Cache</h1>
        <form method="post">
            <?php wp_nonce_field('nrcf_flush_all_action'); ?>
            <p>
                <input type="submit" name="nrcf_flush_all" class="button button-primary"
                       value="Flush Entire Cache">
            </p>
        </form>
    </div>
    <?php
}

// -----------------------------
// 6️⃣ Cached URLs admin page with pagination
// -----------------------------
function nrcf_cache_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Handle flush request
    if (!empty($_GET['nrcf_flush_all']) && check_admin_referer('nrcf_flush_all')) {
        $count = nrcf_flush_nginx_cache();
        echo '<div class="updated"><p>Deleted ' . intval($count) . ' cache keys.</p></div>';
    }

    // Purge request
    if (!empty($_GET['nrcf_purge_keys']) && check_admin_referer('nrcf_purge_keys')) {
        $keys = array_map('urldecode', explode(',', $_GET['nrcf_purge_keys']));
        nrcf_purge_keys($keys);
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success"><p>Cache purged successfully!</p></div>';
        });
    }

    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

    $all_items = nrcf_get_cached_urls(1000);
    $total_items = count($all_items);
    $total_pages = max(1, ceil($total_items / $per_page));
    $offset = ($current_page - 1) * $per_page;
    $items = array_slice($all_items, $offset, $per_page);

    ?>
    <div class="wrap">
        <h1>Nginx Redis Cached URLs</h1>

        <?php if (empty($items)) : ?>
            <p>No cached URLs found.</p>
        <?php else : ?>

            <?php nrcf_render_pagination($current_page, $total_pages); ?>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>URL</th>
                        <th>Devices</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url('http://' . $item['url']); ?>" target="_blank">
                                    <?php echo esc_html($item['url']); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(implode(', ', $item['devices'])); ?></td>
                            <td>
                                <?php $all_keys = implode(',', array_map('rawurlencode', $item['keys'])); ?>
                                <a class="button button-small"
                                   href="<?php echo wp_nonce_url(add_query_arg('nrcf_purge_keys', $all_keys), 'nrcf_purge_keys'); ?>">
                                    Purge
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php nrcf_render_pagination($current_page, $total_pages); ?>

        <?php endif; ?>
    </div>
    <?php
}

// -----------------------------
// 7️⃣ Pagination helper
// -----------------------------
function nrcf_render_pagination($current, $total) {
    if ($total <= 1) return;

    echo '<div class="tablenav"><div class="tablenav-pages">';
    if ($current > 1) echo '<a class="prev-page" href="' . esc_url(add_query_arg('paged', $current - 1)) . '">&laquo; Previous</a>';
    for ($i = 1; $i <= $total; $i++) {
        if ($i == $current) echo '<span class="current">' . $i . '</span>';
        else echo '<a href="' . esc_url(add_query_arg('paged', $i)) . '">' . $i . '</a>';
    }
    if ($current < $total) echo '<a class="next-page" href="' . esc_url(add_query_arg('paged', $current + 1)) . '">Next &raquo;</a>';
    echo '</div></div>';
}

// -----------------------------
// 8️⃣ Flush cache after post update
// -----------------------------
add_action('save_post', function ($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    $url = get_permalink($post_id);
    if ($url) nrcf_purge_url($url);
});

// -----------------------------
// 9️⃣ Admin notices: configuration check
// -----------------------------
add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen) return;

    // Only show notice on plugin pages
    if (!in_array($screen->id, ['toplevel_page_nginx-redis-cache', 'nginx-redis-cache'])) {
        return;
    }

    // Required constants for Nginx Redis Cache plugin
    $required_constants = [
        'NGINX_REDIS_HOST',
        'NGINX_REDIS_PORT',
        'NGINX_REDIS_PREFIX',
    ];

    $missing = [];
    $values  = [];

    foreach ($required_constants as $const) {
        if (!defined($const)) {
            $missing[] = $const;
        } else {
            $values[$const] = constant($const);
        }
    }

    if (!empty($missing)) {
        echo '<div class="notice notice-error"><p>';
        echo 'Nginx Redis Cache plugin is not fully configured. Missing constants: <strong>';
        echo implode(', ', $missing);
        echo '</strong></p></div>';
        return;
    }

    // Test Redis connection
    $connected = false;
    try {
        $redis = new Redis();
        $host  = NGINX_REDIS_HOST;
        $port  = NGINX_REDIS_PORT;
        $timeout = 1;
        if (@$redis->connect($host, $port, $timeout)) {
            $connected = true;
        }
    } catch (Exception $e) {
        $connected = false;
    }

    echo '<div class="notice ' . ($connected ? 'notice-success' : 'notice-error') . '"><p>';
    echo '<strong>Nginx Redis Cache Configuration:</strong><br>';
    foreach ($values as $k => $v) {
        echo esc_html($k . ' = ' . $v) . '<br>';
    }
    echo 'Redis connection: ' . ($connected ? '<span style="color:green;">OK</span>' : '<span style="color:red;">Failed</span>') ;
    echo '</p></div>';
});

add_action('admin_enqueue_scripts', function() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Find all flush links
        document.querySelectorAll('a[href*="nrcf_flush_all"]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to flush **All Nginx Redis cache**? This cannot be undone.')) {
                    e.preventDefault(); // cancel navigation
                }
            });
        });
    });
    </script>
    <?php
});
