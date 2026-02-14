// Side Panel JavaScript

let currentTabId = null;
let allRequests = [];

// Функція для отримання перекладених текстів
function i18n(key) {
  return chrome.i18n.getMessage(key) || key;
}

// Ініціалізація i18n для статичних елементів
function initI18n() {
  // Встановлюємо мову документа
  const locale = chrome.i18n.getUILanguage();
  document.documentElement.setAttribute('lang', locale);
  
  // Оновлюємо всі елементи з атрибутом data-i18n
  document.querySelectorAll('[data-i18n]').forEach(element => {
    const key = element.getAttribute('data-i18n');
    element.textContent = i18n(key);
  });
  
  // Оновлюємо атрибути title
  document.querySelectorAll('[data-i18n-title]').forEach(element => {
    const key = element.getAttribute('data-i18n-title');
    element.setAttribute('title', i18n(key));
  });
  
  // Оновлюємо placeholder
  document.querySelectorAll('[data-i18n-placeholder]').forEach(element => {
    const key = element.getAttribute('data-i18n-placeholder');
    element.setAttribute('placeholder', i18n(key));
  });
}

// Ініціалізація
async function init() {
  try {
    // Ініціалізуємо переклади
    initI18n();
    
    // Отримуємо поточну активну вкладку
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab) {
      currentTabId = tab.id;
      await loadTabData(tab.id);
    }
    
    setupEventListeners();
    startAutoRefresh();
  } catch (error) {
    console.error('Initialization error:', error);
  }
}

// Налаштування слухачів подій
function setupEventListeners() {
  // Кнопка очищення
  document.getElementById('clearBtn').addEventListener('click', clearData);
  
  // Кнопка приховування інструкцій
  document.getElementById('toggleSetup').addEventListener('click', toggleSetup);
  
  // Слухач повідомлень від background script
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message.type === 'REQUEST_COMPLETED' && message.tabId === currentTabId) {
      addRequest(message.data);
      allRequests = message.allRequests || [];
      updateUI();
    }
  });
  
  // Слухач зміни активної вкладки
  chrome.tabs.onActivated.addListener(async (activeInfo) => {
    currentTabId = activeInfo.tabId;
    await loadTabData(activeInfo.tabId);
  });
}

// Завантаження даних вкладки
async function loadTabData(tabId) {
  try {
    // Спробуємо отримати дані з background script
    const response = await chrome.runtime.sendMessage({
      type: 'GET_TAB_DATA',
      tabId: tabId
    });
    
    if (response && response.requests) {
      allRequests = response.requests;
      updateUI();
    } else {
      // Спробуємо завантажити з storage
      const result = await chrome.storage.local.get([`tab_${tabId}`]);
      if (result[`tab_${tabId}`]) {
        allRequests = result[`tab_${tabId}`];
        updateUI();
      } else {
        allRequests = [];
        updateUI();
      }
    }
  } catch (error) {
    console.error('Error loading tab data:', error);
    allRequests = [];
    updateUI();
  }
}

// Оновлення UI
function updateUI() {
  updateSummary();
  updateCurrentInfo();
  updateRequestsList();
  
  // Приховуємо інструкції, якщо є дані
  if (allRequests.length > 0) {
    document.getElementById('setupSection').classList.add('collapsed');
  }
}

// Оновлення summary cards
function updateSummary() {
  const totalRequests = allRequests.length;
  const totalQueries = allRequests.reduce((sum, req) => sum + (req.queries || 0), 0);
  const avgTime = totalRequests > 0 
    ? (allRequests.reduce((sum, req) => sum + (req.executionTime || 0), 0) / totalRequests).toFixed(2)
    : 0;
  
  document.getElementById('totalRequests').textContent = totalRequests;
  document.getElementById('totalQueries').textContent = totalQueries;
  document.getElementById('avgTime').textContent = `${avgTime}ms`;
}

// Оновлення інформації про поточну сторінку
function updateCurrentInfo() {
  const currentInfoDiv = document.getElementById('currentInfo');
  const latestRequest = allRequests[allRequests.length - 1];
  
  if (!latestRequest) {
    currentInfoDiv.innerHTML = `
      <h3>${i18n('currentPageTitle')}</h3>
      <div class="info-empty">${i18n('currentPageEmpty')}</div>
    `;
    return;
  }
  
  const html = `
    <h3>${i18n('currentPageTitle')}</h3>
    <div class="info-grid">
      ${latestRequest.framework ? `
        <div class="info-item">
          <div class="info-label">${i18n('labelFramework')}</div>
          <div class="info-value framework">
            ${latestRequest.framework} ${latestRequest.version || ''}
          </div>
        </div>
      ` : ''}
      
      ${latestRequest.database ? `
        <div class="info-item">
          <div class="info-label">${i18n('labelDatabase')}</div>
          <div class="info-value database">
            ${latestRequest.database}
          </div>
        </div>
      ` : ''}
      
      ${latestRequest.route ? `
        <div class="info-item">
          <div class="info-label">${i18n('labelRoute')}</div>
          <div class="info-value route">${escapeHtml(latestRequest.route)}</div>
        </div>
      ` : ''}
      
      <div class="info-item">
        <div class="info-label">${i18n('labelQueries')}</div>
        <div class="info-value">${latestRequest.queries || 0}</div>
      </div>
      
      <div class="info-item">
        <div class="info-label">${i18n('labelExecutionTime')}</div>
        <div class="info-value">${latestRequest.executionTime || 0}ms</div>
      </div>
      
      ${latestRequest.memoryUsage ? `
        <div class="info-item">
          <div class="info-label">${i18n('labelMemory')}</div>
          <div class="info-value">${latestRequest.memoryUsage}MB</div>
        </div>
      ` : ''}
      
      <div class="info-item">
        <div class="info-label">${i18n('labelMethod')}</div>
        <div class="info-value">${latestRequest.method || 'GET'}</div>
      </div>
    </div>
  `;
  
  currentInfoDiv.innerHTML = html;
}

// Оновлення списку запитів
function updateRequestsList() {
  const listDiv = document.getElementById('requestsList');
  
  if (allRequests.length === 0) {
    listDiv.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <p>${i18n('requestsEmpty')}</p>
        <small>${i18n('requestsEmptyHint')}</small>
      </div>
    `;
    return;
  }
  
  // Показуємо запити в зворотному порядку (найновіші зверху)
  const requestsHtml = [...allRequests].reverse().map(request => {
    const time = new Date(request.timestamp).toLocaleTimeString('uk-UA');
    const url = new URL(request.url);
    const pathname = url.pathname + url.search;
    
    return `
      <div class="request-item">
        <div class="request-header">
          <span class="request-method">${request.method || 'GET'}</span>
          <span class="request-time">${time}</span>
        </div>
        <div class="request-url" title="${escapeHtml(request.url)}">
          ${escapeHtml(pathname)}
        </div>
        <div class="request-stats">
          ${request.queries !== undefined ? `
            <div class="stat-badge queries">
              <span class="value">${request.queries}</span>
              <span>${i18n('statSql')}</span>
            </div>
          ` : ''}
          
          ${request.executionTime !== undefined ? `
            <div class="stat-badge time">
              <span class="value">${request.executionTime}ms</span>
            </div>
          ` : ''}
          
          ${request.memoryUsage !== undefined ? `
            <div class="stat-badge memory">
              <span class="value">${request.memoryUsage}MB</span>
            </div>
          ` : ''}
          
          ${request.framework ? `
            <div class="stat-badge framework">
              <span class="value">${escapeHtml(request.framework)}</span>
            </div>
          ` : ''}
        </div>
      </div>
    `;
  }).join('');
  
  listDiv.innerHTML = requestsHtml;
}

// Додавання нового запиту
function addRequest(requestData) {
  // Додаємо timestamp якщо його немає
  if (!requestData.timestamp) {
    requestData.timestamp = Date.now();
  }
  
  allRequests.push(requestData);
  
  // Обмежуємо кількість запитів
  if (allRequests.length > 100) {
    allRequests.shift();
  }
}

// Очищення даних
async function clearData() {
  if (!confirm(i18n('confirmClear'))) {
    return;
  }
  
  try {
    await chrome.runtime.sendMessage({
      type: 'CLEAR_TAB_DATA',
      tabId: currentTabId
    });
    
    allRequests = [];
    updateUI();
    
    // Показуємо інструкції знову
    document.getElementById('setupSection').classList.remove('collapsed');
  } catch (error) {
    console.error('Error clearing data:', error);
  }
}

// Toggle setup section
function toggleSetup() {
  const setupSection = document.getElementById('setupSection');
  const btn = document.getElementById('toggleSetup');
  
  if (setupSection.classList.contains('collapsed')) {
    setupSection.classList.remove('collapsed');
    btn.textContent = i18n('btnHideSetup');
  } else {
    setupSection.classList.add('collapsed');
    btn.textContent = i18n('btnShowSetup');
  }
}

// Автоматичне оновлення
function startAutoRefresh() {
  setInterval(() => {
    if (currentTabId) {
      loadTabData(currentTabId);
    }
  }, 2000); // Оновлюємо кожні 2 секунди
}

// Утиліта для екранування HTML
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Запуск при завантаженні
document.addEventListener('DOMContentLoaded', init);

console.log('Laravel & CodeIgniter Debugger: Side panel loaded');
