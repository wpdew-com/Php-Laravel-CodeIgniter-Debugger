// Background Service Worker для перехоплення запитів

// Зберігаємо дані про запити для кожної вкладки
const tabData = new Map();

// Відкриття Side Panel при кліку на іконку розширення
chrome.action.onClicked.addListener((tab) => {
  chrome.sidePanel.open({ windowId: tab.windowId });
});

// Перехоплення відповідей від сервера
chrome.webRequest.onCompleted.addListener(
  (details) => {
    // Ігноруємо запити, які не є основними документами або fetch/xhr
    if (!['main_frame', 'xmlhttprequest', 'other'].includes(details.type)) {
      return;
    }

    // Отримуємо заголовки відповіді
    const headers = details.responseHeaders || [];
    
    // Шукаємо кастомні заголовки від Laravel Debugbar
    const debugData = extractDebugData(headers);
    
    if (debugData) {
      // Зберігаємо дані для вкладки
      const tabId = details.tabId;
      
      if (!tabData.has(tabId)) {
        tabData.set(tabId, []);
      }
      
      const requests = tabData.get(tabId);
      requests.push({
        url: details.url,
        method: details.method,
        timestamp: Date.now(),
        ...debugData
      });
      
      // Зберігаємо тільки останні 100 запитів
      if (requests.length > 100) {
        requests.shift();
      }
      
      // Відправляємо оновлення в side panel
      chrome.runtime.sendMessage({
        type: 'REQUEST_COMPLETED',
        tabId: tabId,
        data: debugData,
        allRequests: requests
      }).catch(() => {
        // Side panel може бути закрита
      });
      
      // Зберігаємо в storage для персистентності
      chrome.storage.local.set({ [`tab_${tabId}`]: requests });
    }
  },
  { urls: ["<all_urls>"] },
  ["responseHeaders"]
);

// Функція для витягування debug інформації з заголовків
function extractDebugData(headers) {
  const data = {
    framework: null,
    version: null,
    route: null,
    queries: 0,
    executionTime: 0,
    memoryUsage: 0,
    rawHeaders: {}
  };
  
  let hasDebugInfo = false;
  
  headers.forEach(header => {
    const name = header.name.toLowerCase();
    const value = header.value;
    
    // Laravel Debugbar headers
    if (name === 'x-debug-queries' || name === 'x-laravel-queries') {
      data.queries = parseInt(value) || 0;
      hasDebugInfo = true;
    }
    
    // X-Debug-Query-Count для PhpDebugHeaders.php
    if (name === 'x-debug-query-count') {
      data.queries = parseInt(value) || 0;
      hasDebugInfo = true;
    }
    
    if (name === 'x-debug-time' || name === 'x-laravel-time') {
      data.executionTime = parseFloat(value) || 0;
      hasDebugInfo = true;
    }
    
    if (name === 'x-debug-memory' || name === 'x-laravel-memory') {
      data.memoryUsage = parseFloat(value) || 0;
      hasDebugInfo = true;
    }
    
    if (name === 'x-laravel-version' || name === 'x-framework-version') {
      data.version = value;
      data.framework = 'Laravel';
      hasDebugInfo = true;
    }
    
    if (name === 'x-codeigniter-version') {
      data.version = value;
      data.framework = 'CodeIgniter';
      hasDebugInfo = true;
    }
    
    if (name === 'x-wordpress-version') {
      data.version = value;
      data.framework = 'WordPress';
      hasDebugInfo = true;
    }
    
    // Vanilla PHP (PhpDebugHeaders.php)
    if (name === 'x-debug-framework' && !data.framework) {
      // Формат: "PHP 8.2.0"
      const match = value.match(/^PHP\s+([\d.]+)/i);
      if (match) {
        data.framework = 'PHP';
        data.version = match[1];
        hasDebugInfo = true;
      }
    }
    
    if (name === 'x-debug-route' || name === 'x-laravel-route') {
      data.route = value;
      hasDebugInfo = true;
    }
    
    // Тип бази даних
    if (name === 'x-debug-database') {
      data.database = value;
      hasDebugInfo = true;
    }
    
    // Зберігаємо всі debug-заголовки
    if (name.startsWith('x-debug-') || name.startsWith('x-laravel-') || name.startsWith('x-codeigniter-') || name.startsWith('x-wordpress-')) {
      data.rawHeaders[name] = value;
    }
  });
  
  // Спроба визначити фреймворк, якщо не знайдено явно
  if (!data.framework && hasDebugInfo) {
    data.framework = 'Unknown PHP Framework';
  }
  
  return hasDebugInfo ? data : null;
}

// Очищення даних при закритті вкладки
chrome.tabs.onRemoved.addListener((tabId) => {
  tabData.delete(tabId);
  chrome.storage.local.remove(`tab_${tabId}`);
});

// Відправка даних при запиті з side panel
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message.type === 'GET_TAB_DATA') {
    const tabId = message.tabId;
    const requests = tabData.get(tabId) || [];
    sendResponse({ requests });
  }
  
  if (message.type === 'CLEAR_TAB_DATA') {
    const tabId = message.tabId;
    tabData.delete(tabId);
    chrome.storage.local.remove(`tab_${tabId}`);
    sendResponse({ success: true });
  }
  
  return true; // Async response
});

console.log('Laravel & CodeIgniter Debugger: Background worker started');
