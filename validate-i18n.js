#!/usr/bin/env node

/**
 * i18n Validation Script
 * 
 * Checks that all _locales messages.json files:
 * - Have valid JSON syntax
 * - Contain the same keys
 * - Have no empty values
 */

const fs = require('fs');
const path = require('path');

const LOCALES_DIR = '_locales';
const SUPPORTED_LOCALES = ['en', 'uk', 'ru'];

console.log('🔍 Валідація i18n файлів...\n');

let hasErrors = false;

// Об'єкт для збереження всіх ключів з кожної мови
const localesData = {};

// Крок 1: Читаємо всі messages.json файли
SUPPORTED_LOCALES.forEach(locale => {
  const filePath = path.join(LOCALES_DIR, locale, 'messages.json');
  
  console.log(`📄 Перевірка ${locale}...`);
  
  // Перевірка існування файлу
  if (!fs.existsSync(filePath)) {
    console.error(`   ❌ Файл не знайдено: ${filePath}`);
    hasErrors = true;
    return;
  }
  
  // Читання і парсинг JSON
  try {
    const content = fs.readFileSync(filePath, 'utf8');
    const data = JSON.parse(content);
    localesData[locale] = data;
    console.log(`   ✅ JSON валідний`);
  } catch (error) {
    console.error(`   ❌ Помилка парсингу JSON: ${error.message}`);
    hasErrors = true;
    return;
  }
});

if (Object.keys(localesData).length === 0) {
  console.error('\n❌ Не вдалося прочитати жоден файл локалізації');
  process.exit(1);
}

console.log('\n');

// Крок 2: Збираємо всі унікальні ключі
const allKeys = new Set();
Object.values(localesData).forEach(data => {
  Object.keys(data).forEach(key => allKeys.add(key));
});

console.log(`📊 Всього унікальних ключів: ${allKeys.size}\n`);

// Крок 3: Перевіряємо що всі мови мають всі ключі
SUPPORTED_LOCALES.forEach(locale => {
  if (!localesData[locale]) return;
  
  console.log(`🔎 Перевірка повноти ${locale}...`);
  
  const missingKeys = [];
  const emptyKeys = [];
  
  allKeys.forEach(key => {
    if (!(key in localesData[locale])) {
      missingKeys.push(key);
    } else {
      const message = localesData[locale][key].message;
      if (!message || message.trim() === '') {
        emptyKeys.push(key);
      }
    }
  });
  
  if (missingKeys.length > 0) {
    console.error(`   ❌ Відсутні ключі (${missingKeys.length}):`);
    missingKeys.forEach(key => console.error(`      - ${key}`));
    hasErrors = true;
  }
  
  if (emptyKeys.length > 0) {
    console.error(`   ❌ Пусті значення (${emptyKeys.length}):`);
    emptyKeys.forEach(key => console.error(`      - ${key}`));
    hasErrors = true;
  }
  
  if (missingKeys.length === 0 && emptyKeys.length === 0) {
    console.log(`   ✅ Всі ключі присутні та заповнені (${allKeys.size})`);
  }
});

console.log('\n');

// Крок 4: Перевіряємо структуру кожного ключа
console.log('🔍 Перевірка структури ключів...\n');

SUPPORTED_LOCALES.forEach(locale => {
  if (!localesData[locale]) return;
  
  console.log(`📋 Структура ${locale}:`);
  
  let structureErrors = 0;
  
  Object.entries(localesData[locale]).forEach(([key, value]) => {
    // Перевірка що value є об'єктом
    if (typeof value !== 'object' || value === null) {
      console.error(`   ❌ ${key}: має бути об'єктом`);
      structureErrors++;
      return;
    }
    
    // Перевірка наявності "message"
    if (!('message' in value)) {
      console.error(`   ❌ ${key}: відсутнє поле "message"`);
      structureErrors++;
      return;
    }
    
    // Перевірка що message є строкою
    if (typeof value.message !== 'string') {
      console.error(`   ❌ ${key}: "message" має бути строкою`);
      structureErrors++;
      return;
    }
  });
  
  if (structureErrors === 0) {
    console.log(`   ✅ Структура коректна`);
  } else {
    console.error(`   ❌ Знайдено ${structureErrors} помилок структури`);
    hasErrors = true;
  }
});

console.log('\n');

// Крок 5: Порівняння ключів між мовами
console.log('🔄 Порівняння ключів між мовами...\n');

const baseLocale = 'en';
const baseKeys = Object.keys(localesData[baseLocale] || {});

SUPPORTED_LOCALES.forEach(locale => {
  if (locale === baseLocale || !localesData[locale]) return;
  
  const localeKeys = Object.keys(localesData[locale]);
  
  // Ключі які є в базовій мові але відсутні в поточній
  const missing = baseKeys.filter(key => !localeKeys.includes(key));
  
  // Ключі які є в поточній але відсутні в базовій
  const extra = localeKeys.filter(key => !baseKeys.includes(key));
  
  if (missing.length > 0 || extra.length > 0) {
    console.log(`⚠️  ${locale} vs ${baseLocale}:`);
    
    if (missing.length > 0) {
      console.error(`   ❌ Відсутні (${missing.length}): ${missing.join(', ')}`);
      hasErrors = true;
    }
    
    if (extra.length > 0) {
      console.warn(`   ⚠️  Зайві (${extra.length}): ${extra.join(', ')}`);
    }
  } else {
    console.log(`   ✅ ${locale} синхронізовано з ${baseLocale}`);
  }
});

console.log('\n');

// Крок 6: Статистика перекладів
console.log('📈 Статистика перекладів:\n');

SUPPORTED_LOCALES.forEach(locale => {
  if (!localesData[locale]) return;
  
  const data = localesData[locale];
  const totalKeys = Object.keys(data).length;
  const totalChars = Object.values(data).reduce((sum, item) => {
    return sum + (item.message ? item.message.length : 0);
  }, 0);
  
  console.log(`   ${locale}: ${totalKeys} ключів, ${totalChars} символів`);
});

console.log('\n');

// Фінальний результат
if (hasErrors) {
  console.error('❌ Валідація не пройдена! Виправте помилки вище.\n');
  process.exit(1);
} else {
  console.log('✅ Всі перевірки пройдені успішно!\n');
  console.log('🎉 i18n файли готові до використання.\n');
  process.exit(0);
}
