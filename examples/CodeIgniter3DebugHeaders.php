<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter 3 Debug Headers Hook
 * 
 * Додає HTTP заголовки з інформацією про виконання запиту
 * для Chrome розширення Laravel & CodeIgniter Debugger
 * 
 * ВАЖЛИВО: Використовуйте тільки в development оточенні!
 */

class DebugHeaders
{
    protected $CI;
    protected $startTime;
    protected $startMemory;
    
    public function __construct()
    {
        $this->CI =& get_instance();
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $this->startMemory = memory_get_usage();
    }
    
    /**
     * Встановлення debug заголовків
     * Викликається на етапі post_controller
     */
    public function set_headers()
    {
        // Перевірка оточення
        if (ENVIRONMENT !== 'development') {
            return;
        }
        
        // Збираємо метрики
        $executionTime = round((microtime(true) - $this->startTime) * 1000, 2);
        $memoryUsage = round(memory_get_usage() / 1024 / 1024, 2);
        $memoryPeak = round(memory_get_peak_usage() / 1024 / 1024, 2);
        
        // Встановлюємо основні заголовки
        header('X-CodeIgniter-Version: ' . CI_VERSION);
        header('X-Debug-Time: ' . $executionTime);
        header('X-Debug-Memory: ' . $memoryUsage);
        header('X-Debug-Memory-Peak: ' . $memoryPeak);
        header('X-Debug-Environment: ' . ENVIRONMENT);
        
        // Інформація про роут
        $router = $this->CI->router;
        if ($router) {
            $class = $router->class ?? 'unknown';
            $method = $router->method ?? 'unknown';
            header('X-Debug-Route: ' . $class . '/' . $method);
            header('X-Debug-Controller: ' . $class);
            header('X-Debug-Method: ' . $method);
        }
        
        // HTTP метод
        header('X-Debug-Request-Method: ' . $_SERVER['REQUEST_METHOD']);
        
        // Інформація про базу даних (якщо підключена)
        if (isset($this->CI->db) && $this->CI->db) {
            try {
                // Кількість запитів
                $queries = $this->CI->db->queries;
                header('X-Debug-Queries: ' . count($queries));
                
                // Загальний час виконання запитів
                $totalQueryTime = 0;
                foreach ($this->CI->db->query_times as $time) {
                    $totalQueryTime += $time;
                }
                header('X-Debug-Queries-Time: ' . round($totalQueryTime * 1000, 2));
                
                // Драйвер бази даних
                header('X-Debug-Database-Driver: ' . $this->CI->db->dbdriver);
                
                // Назва бази даних
                header('X-Debug-Database-Name: ' . $this->CI->db->database);
                
                // Тип бази даних (людино-зрозуміла назва)
                $databaseType = $this->getDatabaseType($this->CI->db->dbdriver);
                header('X-Debug-Database: ' . $databaseType);
            } catch (Exception $e) {
                // Ігноруємо помилки
            }
        }
        
        // Інформація про кеш (якщо є)
        if (isset($this->CI->cache)) {
            try {
                $cacheInfo = $this->CI->cache->cache_info();
                if ($cacheInfo) {
                    header('X-Debug-Cache-Driver: ' . get_class($this->CI->cache));
                }
            } catch (Exception $e) {
                // Ігноруємо помилки
            }
        }
        
        // Інформація про сесію (якщо є)
        if (isset($this->CI->session)) {
            try {
                $sessionId = session_id();
                if ($sessionId) {
                    header('X-Debug-Session-Id: ' . substr($sessionId, 0, 8) . '...');
                }
            } catch (Exception $e) {
                // Ігноруємо помилки
            }
        }
        
        // PHP версія
        header('X-Debug-PHP-Version: ' . PHP_VERSION);
        
        // Opcache статус
        if (function_exists('opcache_get_status')) {
            $opcache = opcache_get_status();
            if ($opcache && isset($opcache['opcache_enabled'])) {
                header('X-Debug-Opcache: ' . ($opcache['opcache_enabled'] ? 'enabled' : 'disabled'));
            }
        }
        
        // Кількість завантажених файлів
        $includedFiles = get_included_files();
        header('X-Debug-Files-Loaded: ' . count($includedFiles));
        
        // Benchmark інформація (якщо є)
        if (isset($this->CI->benchmark)) {
            $benchmarkPoints = $this->CI->benchmark->marker;
            if (!empty($benchmarkPoints)) {
                header('X-Debug-Benchmark-Points: ' . count($benchmarkPoints));
            }
        }
    }
    
    /**
     * Додатковий метод для логування детальної інформації
     * Може бути використаний для запису в файл
     */
    public function log_debug_info()
    {
        if (ENVIRONMENT !== 'development') {
            return;
        }
        
        $debugInfo = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'execution_time' => round((microtime(true) - $this->startTime) * 1000, 2),
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2),
            'memory_peak' => round(memory_get_peak_usage() / 1024 / 1024, 2),
        );
        
        // Додаємо інформацію про запити до БД
        if (isset($this->CI->db)) {
            $debugInfo['database_queries'] = count($this->CI->db->queries);
        }
        
        // Можна записати в лог файл
        log_message('debug', 'Debug Headers: ' . json_encode($debugInfo));
    }
    
    /**
     * Визначення типу бази даних
     * 
     * @param string $driver
     * @return string
     */
    private function getDatabaseType($driver)
    {
        $driverMap = array(
            'mysqli' => 'MySQL',
            'mysql' => 'MySQL',
            'sqlite3' => 'SQLite',
            'sqlite' => 'SQLite',
            'postgre' => 'PostgreSQL',
            'pdo' => 'PDO',
            'sqlsrv' => 'SQL Server',
            'oci8' => 'Oracle',
            'odbc' => 'ODBC',
            'cubrid' => 'CUBRID',
            'ibase' => 'Firebird',
        );
        
        return isset($driverMap[$driver]) ? $driverMap[$driver] : ucfirst($driver);
    }
}

/* End of file DebugHeaders.php */
/* Location: ./application/hooks/DebugHeaders.php */
