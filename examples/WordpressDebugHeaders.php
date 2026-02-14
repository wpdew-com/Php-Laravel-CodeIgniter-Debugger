<?php
/**
 * Plugin Name: WordPress Debug Headers
 * Plugin URI: https://github.com/your-repo/wordpress-debug-headers
 * Description: Додає HTTP заголовки з інформацією про виконання запиту для Chrome розширення Laravel & CodeIgniter Debugger
 * Version: 1.0.0
 * Author: Your Name
 * License: MIT
 * 
 * ВАЖЛИВО: Використовуйте тільки в local/development оточенні!
 * 
 * Встановлення:
 * 1. Скопіюйте цей файл в: wp-content/plugins/wordpress-debug-headers/wordpress-debug-headers.php
 *    АБО додайте код в файл functions.php вашої теми
 * 2. Активуйте плагін в адмін панелі WordPress
 * 
 * Альтернативний спосіб (без плагіну):
 * Скопіюйте функції (без коментарів плагіна) в functions.php вашої активної теми
 */

// Запобігаємо прямому доступу
if (!defined('ABSPATH')) {
    exit;
}

class WP_Debug_Headers {
    
    private $startTime;
    private $startMemory;
    private $queryCount = 0;
    private $queryTime = 0;
    
    /**
     * Ініціалізація
     */
    public function __construct() {
        // Працює тільки якщо увімкнено WP_DEBUG
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        // Зберігаємо час початку
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $this->startMemory = memory_get_usage();
        
        // Увімкнення логування SQL запитів
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }
        
        // Хук для додавання заголовків до відповіді
        add_action('send_headers', [$this, 'add_debug_headers']);
        
        // Хук після виконання всіх запитів (для точнішого підрахунку)
        add_action('shutdown', [$this, 'prepare_headers'], 1);
    }
    
    /**
     * Підготовка заголовків перед відправкою
     */
    public function prepare_headers() {
        global $wpdb;
        
        // Підраховуємо SQL запити
        if (!empty($wpdb->queries)) {
            $this->queryCount = count($wpdb->queries);
            
            // Рахуємо загальний час виконання запитів
            foreach ($wpdb->queries as $query) {
                $this->queryTime += $query[1];
            }
        }
    }
    
    /**
     * Додавання debug заголовків
     */
    public function add_debug_headers() {
        global $wp_version, $wpdb;
        
        // Перевіряємо, що заголовки ще не відправлені
        if (headers_sent()) {
            return;
        }
        
        // Перевіряємо оточення
        if (!$this->is_development_environment()) {
            return;
        }
        
        // Збираємо метрики
        $executionTime = round((microtime(true) - $this->startTime) * 1000, 2);
        $memoryUsage = round(memory_get_usage() / 1024 / 1024, 2);
        $memoryPeak = round(memory_get_peak_usage() / 1024 / 1024, 2);
        
        // Основні заголовки
        header('X-WordPress-Version: ' . $wp_version);
        header('X-Debug-Time: ' . $executionTime);
        header('X-Debug-Memory: ' . $memoryUsage);
        header('X-Debug-Memory-Peak: ' . $memoryPeak);
        header('X-Debug-Method: ' . $_SERVER['REQUEST_METHOD']);
        header('X-Debug-Environment: ' . $this->get_environment());
        
        // Інформація про базу даних
        if (isset($wpdb)) {
            $databaseType = $this->get_database_type($wpdb);
            header('X-Debug-Database: ' . $databaseType);
            header('X-Debug-Database-Name: ' . DB_NAME);
            header('X-Debug-Database-Host: ' . DB_HOST);
            header('X-Debug-Queries: ' . $this->queryCount);
            header('X-Debug-Queries-Time: ' . round($this->queryTime * 1000, 2));
            
            // Інформація про таблиці
            header('X-Debug-Table-Prefix: ' . $wpdb->prefix);
        }
        
        // Інформація про тему
        $theme = wp_get_theme();
        if ($theme) {
            header('X-Debug-Theme: ' . $theme->get('Name'));
            header('X-Debug-Theme-Version: ' . $theme->get('Version'));
        }
        
        // Інформація про користувача
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            header('X-Debug-User-Id: ' . $user->ID);
            header('X-Debug-User-Login: ' . $user->user_login);
            header('X-Debug-User-Role: ' . implode(', ', $user->roles));
        } else {
            header('X-Debug-User: guest');
        }
        
        // Інформація про роут/сторінку
        global $wp_query;
        if ($wp_query) {
            if (is_front_page()) {
                header('X-Debug-Route: front-page');
            } elseif (is_home()) {
                header('X-Debug-Route: home');
            } elseif (is_single()) {
                header('X-Debug-Route: single/' . get_post_type());
            } elseif (is_page()) {
                header('X-Debug-Route: page/' . get_queried_object_id());
            } elseif (is_category()) {
                header('X-Debug-Route: category/' . get_queried_object_id());
            } elseif (is_tag()) {
                header('X-Debug-Route: tag/' . get_queried_object_id());
            } elseif (is_archive()) {
                header('X-Debug-Route: archive');
            } elseif (is_search()) {
                header('X-Debug-Route: search');
            } elseif (is_404()) {
                header('X-Debug-Route: 404');
            } else {
                header('X-Debug-Route: unknown');
            }
            
            // Інформація про запит
            header('X-Debug-Queried-Object: ' . get_queried_object_id());
            header('X-Debug-Posts-Found: ' . $wp_query->found_posts);
        }
        
        // Інформація про кеш
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            header('X-Debug-Cache: object-cache-enabled');
        } else {
            header('X-Debug-Cache: default');
        }
        
        // Інформація про плагіни
        $active_plugins = get_option('active_plugins');
        header('X-Debug-Plugins-Count: ' . count($active_plugins));
        
        // PHP OPcache інформація
        if (function_exists('opcache_get_status')) {
            $opcache = opcache_get_status();
            if ($opcache && isset($opcache['opcache_enabled'])) {
                header('X-Debug-Opcache: ' . ($opcache['opcache_enabled'] ? 'enabled' : 'disabled'));
            }
        }
        
        // Multisite інформація
        if (is_multisite()) {
            header('X-Debug-Multisite: enabled');
            header('X-Debug-Site-Id: ' . get_current_blog_id());
            header('X-Debug-Network-Id: ' . get_current_network_id());
        }
        
        // Debug режим
        header('X-Debug-Mode: ' . (WP_DEBUG ? 'enabled' : 'disabled'));
        header('X-Debug-Display: ' . (WP_DEBUG_DISPLAY ? 'enabled' : 'disabled'));
        header('X-Debug-Log: ' . (WP_DEBUG_LOG ? 'enabled' : 'disabled'));
        
        // REST API request
        if (defined('REST_REQUEST') && REST_REQUEST) {
            header('X-Debug-Request-Type: REST-API');
        } elseif (defined('DOING_AJAX') && DOING_AJAX) {
            header('X-Debug-Request-Type: AJAX');
        } elseif (defined('DOING_CRON') && DOING_CRON) {
            header('X-Debug-Request-Type: CRON');
        } else {
            header('X-Debug-Request-Type: HTTP');
        }
    }
    
    /**
     * Визначення типу бази даних
     */
    private function get_database_type($wpdb) {
        if (method_exists($wpdb, 'db_version')) {
            $version = $wpdb->db_version();
            
            // Перевіряємо чи це MySQL чи MariaDB
            if (stripos($version, 'MariaDB') !== false) {
                return 'MariaDB';
            } else {
                return 'MySQL';
            }
        }
        
        // За замовчуванням WordPress використовує MySQL
        return 'MySQL';
    }
    
    /**
     * Визначення оточення
     */
    private function get_environment() {
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return WP_ENVIRONMENT_TYPE;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return 'development';
        }
        
        return 'production';
    }
    
    /**
     * Перевірка чи це development оточення
     */
    private function is_development_environment() {
        // Перевіряємо WP_DEBUG
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        // Перевіряємо WP_ENVIRONMENT_TYPE (WordPress 5.5+)
        if (defined('WP_ENVIRONMENT_TYPE')) {
            return in_array(WP_ENVIRONMENT_TYPE, ['development', 'local']);
        }
        
        // Перевіряємо localhost
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        if (in_array($server_name, ['localhost', '127.0.0.1', '::1'])) {
            return true;
        }
        
        // Перевіряємо .local і .test домени
        if (preg_match('/\.(local|test|dev)$/', $server_name)) {
            return true;
        }
        
        return false;
    }
}

// Ініціалізуємо клас
new WP_Debug_Headers();

/**
 * АЛЬТЕРНАТИВНИЙ СПОСІБ ВИКОРИСТАННЯ (без плагіна):
 * 
 * Якщо ви не хочете створювати плагін, можете додати наступний код 
 * в functions.php вашої теми:
 * 
 * require_once get_template_directory() . '/wordpress-debug-headers.php';
 * 
 * або скопіюйте весь код вище (без коментарів Plugin Name) в functions.php
 */

/**
 * ДОДАТКОВО: Функція для виведення детальної інформації про SQL запити
 * Використовуйте тільки для глибокої відладки!
 */
function wp_debug_show_queries() {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    global $wpdb;
    if (!empty($wpdb->queries)) {
        echo '<div style="background: #f0f0f0; padding: 20px; margin: 20px; border-left: 4px solid #0073aa;">';
        echo '<h3>SQL Queries (' . count($wpdb->queries) . ')</h3>';
        echo '<ol>';
        foreach ($wpdb->queries as $query) {
            echo '<li>';
            echo '<strong>Query:</strong> <code>' . htmlspecialchars($query[0]) . '</code><br>';
            echo '<strong>Time:</strong> ' . $query[1] . ' seconds<br>';
            echo '<strong>Called by:</strong> ' . $query[2];
            echo '</li><br>';
        }
        echo '</ol>';
        echo '</div>';
    }
}

// Якщо хочете бачити SQL запити внизу сторінки (тільки для адмінів)
// add_action('wp_footer', 'wp_debug_show_queries', 9999);
