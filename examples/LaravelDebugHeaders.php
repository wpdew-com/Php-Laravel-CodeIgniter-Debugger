<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel Debug Headers Middleware
 * 
 * Додає HTTP заголовки з інформацією про виконання запиту
 * для Chrome розширення Laravel & CodeIgniter Debugger
 * 
 * ВАЖЛИВО: Використовуйте тільки в local/development оточенні!
 */
class DebugHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Перевірка оточення - працює тільки локально
        if (!app()->environment(['local', 'development'])) {
            return $next($request);
        }

        // Увімкнення логування SQL запитів
        DB::enableQueryLog();
        
        // Зберігаємо час початку виконання
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $startMemory = memory_get_usage();
        
        // Виконуємо запит
        $response = $next($request);
        
        // Збираємо метрики
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        $memoryUsage = round(memory_get_usage() / 1024 / 1024, 2);
        $memoryPeak = round(memory_get_peak_usage() / 1024 / 1024, 2);
        $queryCount = count(DB::getQueryLog());
        
        // Отримуємо інформацію про роут
        $route = $request->route();
        $routeName = $route ? ($route->getName() ?? $route->uri()) : 'unknown';
        $routeAction = $route ? $route->getActionName() : 'unknown';
        
        // Визначення типу бази даних
        $databaseType = $this->getDatabaseType();
        
        // Додаємо заголовки до відповіді
        $response->headers->set('X-Laravel-Version', app()->version());
        $response->headers->set('X-Laravel-Route', $routeName);
        $response->headers->set('X-Debug-Queries', $queryCount);
        $response->headers->set('X-Debug-Time', $executionTime);
        $response->headers->set('X-Debug-Memory', $memoryUsage);
        $response->headers->set('X-Debug-Memory-Peak', $memoryPeak);
        $response->headers->set('X-Debug-Method', $request->method());
        $response->headers->set('X-Debug-Action', $routeAction);
        $response->headers->set('X-Debug-Database', $databaseType);
        
        // Додаткова інформація про PHP
        if (function_exists('opcache_get_status')) {
            $opcache = opcache_get_status();
            if ($opcache && isset($opcache['opcache_enabled'])) {
                $response->headers->set('X-Debug-Opcache', $opcache['opcache_enabled'] ? 'enabled' : 'disabled');
            }
        }
        
        // Інформація про сесію (якщо є)
        if ($request->hasSession()) {
            $response->headers->set('X-Debug-Session-Id', substr($request->session()->getId(), 0, 8) . '...');
        }
        
        // Інформація про авторизацію
        if (auth()->check()) {
            $response->headers->set('X-Debug-User-Id', auth()->id());
            $response->headers->set('X-Debug-User-Email', auth()->user()->email ?? 'unknown');
        }
        
        // Інформація про кеш (якщо використовується Redis/Memcached)
        try {
            $cacheDriver = config('cache.default');
            $response->headers->set('X-Debug-Cache-Driver', $cacheDriver);
        } catch (\Exception $e) {
            // Ігноруємо помилки
        }
        
        // Debug режим
        $response->headers->set('X-Debug-Mode', config('app.debug') ? 'enabled' : 'disabled');
        $response->headers->set('X-Debug-Environment', app()->environment());
        
        return $response;
    }
    
    /**
     * Визначення типу бази даних
     * 
     * @return string
     */
    private function getDatabaseType(): string
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            
            // Маппінг драйверів Laravel до зрозумілих назв
            $driverMap = [
                'mysql' => 'MySQL',
                'sqlite' => 'SQLite',
                'pgsql' => 'PostgreSQL',
                'sqlsrv' => 'SQL Server',
                'mongodb' => 'MongoDB',
            ];
            
            return $driverMap[$driver] ?? ucfirst($driver);
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
}
