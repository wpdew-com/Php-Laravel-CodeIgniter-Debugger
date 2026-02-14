<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use Config\Database;

/**
 * CodeIgniter 4 Debug Headers Filter
 * 
 * Додає HTTP заголовки з інформацією про виконання запиту
 * для Chrome розширення Laravel & CodeIgniter Debugger
 * 
 * ВАЖЛИВО: Використовуйте тільки в development оточенні!
 */
class DebugHeaders implements FilterInterface
{
    protected $startTime;
    protected $startMemory;
    
    /**
     * Before filter - зберігаємо початкові метрики
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $this->startMemory = memory_get_usage();
        
        // Можна увімкнути Query logging
        if (ENVIRONMENT === 'development') {
            // Database query logging автоматично увімкнений в development
        }
    }

    /**
     * After filter - додаємо debug заголовки до відповіді
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Працює тільки в development оточенні
        if (ENVIRONMENT !== 'development') {
            return $response;
        }
        
        try {
            // Збираємо метрики
            $executionTime = round((microtime(true) - $this->startTime) * 1000, 2);
            $memoryUsage = round(memory_get_usage() / 1024 / 1024, 2);
            $memoryPeak = round(memory_get_peak_usage() / 1024 / 1024, 2);
            
            // Встановлюємо основні заголовки
            $response->setHeader('X-CodeIgniter-Version', \CodeIgniter\CodeIgniter::CI_VERSION);
            $response->setHeader('X-Debug-Time', (string) $executionTime);
            $response->setHeader('X-Debug-Memory', (string) $memoryUsage);
            $response->setHeader('X-Debug-Memory-Peak', (string) $memoryPeak);
            $response->setHeader('X-Debug-Environment', ENVIRONMENT);
            
            // Інформація про роут
            $router = service('router');
            if ($router) {
                $controllerName = $router->controllerName() ?? 'unknown';
                $methodName = $router->methodName() ?? 'unknown';
                
                $response->setHeader('X-Debug-Route', $controllerName . '/' . $methodName);
                $response->setHeader('X-Debug-Controller', $controllerName);
                $response->setHeader('X-Debug-Method', $methodName);
            }
            
            // HTTP метод
            $response->setHeader('X-Debug-Request-Method', $request->getMethod());
            
            // Інформація про базу даних
            try {
                $db = Database::connect();
                
                if ($db) {
                    // Кількість запитів
                    $queries = $db->getQueries();
                    $response->setHeader('X-Debug-Queries', (string) count($queries));
                    
                    // Загальний час виконання запитів
                    $queryTimes = $db->getQueryTimes();
                    $totalQueryTime = array_sum($queryTimes);
                    $response->setHeader('X-Debug-Queries-Time', (string) round($totalQueryTime * 1000, 2));
                    
                    // Інформація про підключення
                    $response->setHeader('X-Debug-Database-Driver', $db->DBDriver);
                    $response->setHeader('X-Debug-Database-Name', $db->getDatabase());
                    $response->setHeader('X-Debug-Database-Platform', $db->getPlatform());
                    $response->setHeader('X-Debug-Database-Version', $db->getVersion());
                    
                    // Тип бази даних (людино-зрозуміла назва)
                    $databaseType = $this->getDatabaseType($db->DBDriver);
                    $response->setHeader('X-Debug-Database', $databaseType);
                }
            } catch (\Exception $e) {
                // База даних може бути не підключена
                $response->setHeader('X-Debug-Database', 'not-connected');
            }
            
            // Інформація про кеш
            try {
                $cache = \Config\Services::cache();
                if ($cache) {
                    $cacheInfo = $cache->getCacheInfo();
                    if ($cacheInfo) {
                        $response->setHeader('X-Debug-Cache-Driver', get_class($cache));
                    }
                }
            } catch (\Exception $e) {
                // Ігноруємо помилки
            }
            
            // Інформація про сесію
            try {
                $session = service('session');
                if ($session) {
                    $sessionId = session_id();
                    if ($sessionId) {
                        $response->setHeader('X-Debug-Session-Id', substr($sessionId, 0, 8) . '...');
                    }
                }
            } catch (\Exception $e) {
                // Ігноруємо помилки
            }
            
            // PHP інформація
            $response->setHeader('X-Debug-PHP-Version', PHP_VERSION);
            
            // Opcache статус
            if (function_exists('opcache_get_status')) {
                $opcache = opcache_get_status();
                if ($opcache && isset($opcache['opcache_enabled'])) {
                    $response->setHeader('X-Debug-Opcache', $opcache['opcache_enabled'] ? 'enabled' : 'disabled');
                }
            }
            
            // Кількість завантажених файлів
            $includedFiles = get_included_files();
            $response->setHeader('X-Debug-Files-Loaded', (string) count($includedFiles));
            
            // Debug режим
            $response->setHeader('X-Debug-Mode', CI_DEBUG ? 'enabled' : 'disabled');
            
            // User Agent
            $response->setHeader('X-Debug-User-Agent', substr($request->getUserAgent()->getAgentString(), 0, 50));
            
            // IP адреса
            $response->setHeader('X-Debug-IP-Address', $request->getIPAddress());
            
        } catch (\Exception $e) {
            // Якщо щось пішло не так, додаємо заголовок з помилкою
            $response->setHeader('X-Debug-Error', 'Error generating debug headers');
            log_message('error', 'Debug headers error: ' . $e->getMessage());
        }
        
        return $response;
    }
    
    /**
     * Визначення типу бази даних
     * 
     * @param string $driver
     * @return string
     */
    private function getDatabaseType(string $driver): string
    {
        $driverMap = [
            'MySQLi' => 'MySQL',
            'Postgre' => 'PostgreSQL',
            'SQLite3' => 'SQLite',
            'SQLSRV' => 'SQL Server',
            'OCI8' => 'Oracle',
            'ODBC' => 'ODBC',
        ];
        
        return $driverMap[$driver] ?? ucfirst($driver);
    }
}
