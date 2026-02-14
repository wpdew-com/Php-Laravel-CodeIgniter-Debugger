<?php
/**
 * PHP Debug Headers - для чистого PHP (без фреймворків)
 * 
 * Простий клас для додавання debug заголовків у ваш PHP додаток.
 * Працює з будь-яким PHP проектом (vanilla PHP, custom frameworks).
 * 
 * @version 1.0
 * @license MIT
 */

class PhpDebugHeaders
{
    private static $instance = null;
    private $startTime;
    private $startMemory;
    private $queryCount = 0;
    private $route = '';
    private $forceDebug = false;
    private $databaseType = null;
    
    /**
     * Приватний конструктор для Singleton паттерну
     */
    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
        
        // Автоматично додаємо headers при завершенні скрипта
        register_shutdown_function([$this, 'sendHeaders']);
    }
    
    /**
     * Отримати єдиний екземпляр класу
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Ініціалізація debug режиму
     * Викликайте цю функцію на початку вашого скрипта
     * 
     * @param bool $forceDebug Форсувати debug режим навіть на production (не рекомендується!)
     */
    public static function init($forceDebug = false)
    {
        $instance = self::getInstance();
        $instance->forceDebug = $forceDebug;
        return $instance;
    }
    
    /**
     * Встановити поточний route/endpoint
     * 
     * @param string $route Опис поточного роуту (наприклад: "GET /api/users")
     */
    public function setRoute($route)
    {
        $this->route = $route;
        return $this;
    }
    
    /**
     * Збільшити лічильник SQL запитів
     * Викликайте цю функцію після кожного SQL запиту
     * 
     * @param int $count Кількість запитів (за замовчуванням 1)
     */
    public function incrementQueryCount($count = 1)
    {
        $this->queryCount += $count;
        return $this;
    }
    
    /**
     * Встановити кількість SQL запитів
     * 
     * @param int $count Загальна кількість запитів
     */
    public function setQueryCount($count)
    {
        $this->queryCount = $count;
        return $this;
    }
    
    /**
     * Автоматичний підрахунок SELECT запитів у mysqli
     * Wrapper для mysqli_query який рахує SELECT запити
     * 
     * @param mysqli $connection
     * @param string $query
     * @return mysqli_result|bool
     */
    public function mysqliQuery($connection, $query)
    {
        // Визначаємо тип БД (mysqli завжди MySQL/MariaDB)
        if ($this->databaseType === null) {
            $this->databaseType = 'MySQL';
        }
        
        $result = mysqli_query($connection, $query);
        
        // Рахуємо тільки SELECT запити
        if (stripos(trim($query), 'SELECT') === 0) {
            $this->incrementQueryCount();
        }
        
        return $result;
    }
    
    /**
     * Автоматичний підрахунок SELECT запитів у PDO
     * Wrapper для PDO::query який рахує SELECT запити
     * 
     * @param PDO $pdo
     * @param string $query
     * @return PDOStatement|false
     */
    public function pdoQuery($pdo, $query)
    {
        // Визначаємо тип БД з PDO драйвера
        if ($this->databaseType === null) {
            $this->databaseType = $this->detectDatabaseType($pdo);
        }
        
        $result = $pdo->query($query);
        
        // Рахуємо тільки SELECT запити
        if (stripos(trim($query), 'SELECT') === 0) {
            $this->incrementQueryCount();
        }
        
        return $result;
    }
    
    /**
     * Відправити debug заголовки
     * Викликається автоматично при завершенні скрипта
     */
    public function sendHeaders()
    {
        // Перевірка що headers ще не відправлені
        if (headers_sent($file, $line)) {
            error_log("[PhpDebugHeaders] Headers already sent in {$file} on line {$line}");
            return;
        }
        
        // Перевірка середовища (не відправляємо на production)
        if (!$this->isDebugMode()) {
            error_log("[PhpDebugHeaders] Debug mode disabled. Set APP_ENV=development or use init(true)");
            return;
        }
        
        // Підрахунок метрик
        $executionTime = round((microtime(true) - $this->startTime) * 1000, 2);
        $memoryUsage = round((memory_get_usage() - $this->startMemory) / 1024 / 1024, 2);
        $peakMemory = round(memory_get_peak_usage() / 1024 / 1024, 2);
        
        // Визначення route якщо не встановлено вручну
        if (empty($this->route)) {
            $this->route = $this->detectRoute();
        }
        
        // Відправка заголовків
        header('X-Debug-Framework: PHP ' . PHP_VERSION);
        header('X-Debug-Route: ' . $this->route);
        header('X-Debug-Query-Count: ' . $this->queryCount);
        header('X-Debug-Execution-Time: ' . $executionTime);
        header('X-Debug-Memory: ' . $memoryUsage);
        header('X-Debug-Peak-Memory: ' . $peakMemory);
        header('X-Debug-Method: ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
        
        // Тип бази даних (якщо визначено)
        if ($this->databaseType !== null) {
            header('X-Debug-Database: ' . $this->databaseType);
        }
        
        // Додаткова інформація
        header('X-Debug-Timestamp: ' . date('Y-m-d H:i:s'));
        header('X-Debug-Server: ' . php_uname('n'));
        
        // Логування для діагностики
        error_log("[PhpDebugHeaders] Headers sent: Route={$this->route}, Queries={$this->queryCount}, Time={$executionTime}ms");
    }
    
    /**
     * Перевірка чи увімкнено debug режим
     */
    private function isDebugMode()
    {
        // Якщо форсовано увімкнено
        if ($this->forceDebug) {
            return true;
        }
        
        // Перевірка змінних оточення
        $env = getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: 'production';
        
        // Debug тільки на development/local
        return in_array(strtolower($env), ['development', 'dev', 'local', 'testing']);
    }
    
    /**
     * Автоматичне визначення роуту
     */
    private function detectRoute()
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Видаляємо query string
        $path = parse_url($uri, PHP_URL_PATH);
        
        return $method . ' ' . $path;
    }
    
    /**
     * Визначення типу бази даних з PDO
     * 
     * @param PDO $pdo
     * @return string
     */
    private function detectDatabaseType($pdo)
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            
            // Маппінг драйверів PDO до зрозумілих назв
            $driverMap = [
                'mysql' => 'MySQL',
                'sqlite' => 'SQLite',
                'pgsql' => 'PostgreSQL',
                'sqlsrv' => 'SQL Server',
                'oci' => 'Oracle',
                'firebird' => 'Firebird',
                'dblib' => 'FreeTDS',
                'odbc' => 'ODBC',
            ];
            
            return $driverMap[$driver] ?? ucfirst($driver);
        } catch (Exception $e) {
            return 'Unknown';
        }
    }
}

// ============================================
// ПРИКЛАДИ ВИКОРИСТАННЯ
// ============================================

/*

// ==========================================
// ВАРІАНТ 1: Базове використання (найпростіше)
// ==========================================

// На початку вашого index.php або bootstrap файлу:
require_once 'PhpDebugHeaders.php';
PhpDebugHeaders::init(true);  // true = форсувати debug режим

// Весь ваш код тут...
echo "Hello World!";

// Headers відправляться автоматично в кінці скрипта


// ==========================================
// ВАРІАНТ 2: З встановленням роуту
// ==========================================

require_once 'PhpDebugHeaders.php';
$debug = PhpDebugHeaders::init(true);

// Встановіть поточний роут
$debug->setRoute('GET /api/users');

// Ваш код...


// ==========================================
// ВАРІАНТ 3: З підрахунком SQL запитів (ручний)
// ==========================================

require_once 'PhpDebugHeaders.php';
$debug = PhpDebugHeaders::init(true);

// Кожен раз після SQL запиту:
$result = mysqli_query($connection, "SELECT * FROM users");
$debug->incrementQueryCount(); // +1 запит

$result2 = mysqli_query($connection, "SELECT * FROM posts");
$debug->incrementQueryCount(); // +1 запит


// ==========================================
// ВАРІАНТ 4: З автоматичним підрахунком (mysqli)
// ==========================================

require_once 'PhpDebugHeaders.php';
$debug = PhpDebugHeaders::init(true);

$connection = mysqli_connect('localhost', 'user', 'pass', 'database');

// Використовуйте wrapper замість mysqli_query
$users = $debug->mysqliQuery($connection, "SELECT * FROM users");
$posts = $debug->mysqliQuery($connection, "SELECT * FROM posts");

// SQL запити рахуються автоматично!


// ==========================================
// ВАРІАНТ 5: З автоматичним підрахунком (PDO)
// ==========================================

require_once 'PhpDebugHeaders.php';
$debug = PhpDebugHeaders::init(true);

$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'pass');

// Використовуйте wrapper замість $pdo->query()
$users = $debug->pdoQuery($pdo, "SELECT * FROM users");
$posts = $debug->pdoQuery($pdo, "SELECT * FROM posts");

// SQL запити рахуються автоматично!


// ==========================================
// ВАРІАНТ 6: Повний приклад з API endpoint
// ==========================================

require_once 'PhpDebugHeaders.php';
$debug = PhpDebugHeaders::init(true);

// Визначаємо роут
$route = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
$debug->setRoute($route);

// Підключення до БД
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'root', '');

// Виконуємо запити
$users = $debug->pdoQuery($pdo, "SELECT * FROM users WHERE active = 1");
$settings = $debug->pdoQuery($pdo, "SELECT * FROM settings");
$posts = $debug->pdoQuery($pdo, "SELECT * FROM posts ORDER BY created_at DESC LIMIT 10");

// Формуємо відповідь
header('Content-Type: application/json');
echo json_encode([
    'users' => $users->fetchAll(PDO::FETCH_ASSOC),
    'posts' => $posts->fetchAll(PDO::FETCH_ASSOC)
]);

// Debug headers додаються автоматично!


// ==========================================
// ВАРІАНТ 7: Інтеграція з власним Router
// ==========================================

class SimpleRouter
{
    private $debug;
    
    public function __construct()
    {
        $this->debug = PhpDebugHeaders::init(true);
    }
    
    public function get($path, $callback)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === $path) {
            $this->debug->setRoute("GET $path");
            return $callback();
        }
    }
    
    public function post($path, $callback)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === $path) {
            $this->debug->setRoute("POST $path");
            return $callback();
        }
    }
}

$router = new SimpleRouter();

$router->get('/users', function() {
    return json_encode(['users' => []]);
});

$router->post('/users', function() {
    return json_encode(['created' => true]);
});


// ==========================================
// НАЛАШТУВАННЯ ЧЕРЕЗ .ENV (опціонально)
// ==========================================

// Створіть файл .env:
APP_ENV=development

// Або встановіть через PHP:
putenv('APP_ENV=development');

// І тоді можете використовувати без форсування:
$debug = PhpDebugHeaders::init();  // Працюватиме тільки на development


// ==========================================
// ВАРІАНТ 8: Автоматичне визначення типу БД
// ==========================================

// SQLite
require_once 'PhpDebugHeaders.php';
$debug = PhpDebugHeaders::init(true);

$pdo = new PDO('sqlite:database.db');
$users = $debug->pdoQuery($pdo, "SELECT * FROM users");

// Headers:
// X-Debug-Framework: PHP 8.2.0
// X-Debug-Database: SQLite  ← Автоматично визначено!
// X-Debug-Query-Count: 1


// MySQL через PDO
$pdo = new PDO('mysql:host=localhost;dbname=mydb', 'user', 'pass');
$users = $debug->pdoQuery($pdo, "SELECT * FROM users");

// Headers:
// X-Debug-Database: MySQL  ← Автоматично визначено!


// PostgreSQL через PDO
$pdo = new PDO('pgsql:host=localhost;dbname=mydb', 'user', 'pass');
$users = $debug->pdoQuery($pdo, "SELECT * FROM users");

// Headers:
// X-Debug-Database: PostgreSQL  ← Автоматично визначено!


// MySQL через mysqli
$connection = mysqli_connect('localhost', 'user', 'pass', 'mydb');
$users = $debug->mysqliQuery($connection, "SELECT * FROM users");

// Headers:
// X-Debug-Database: MySQL  ← Автоматично визначено!

*/
