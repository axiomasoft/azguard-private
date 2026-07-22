// Source: anonymized production project
// playwright.config.ts — конфиг E2E: webServer поднимает приложение, projects-браузеры,
// отдельный project "setup" для одноразового логина через storageState, изоляция данных через webServer.env.
import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const STORAGE_STATE = 'playwright/.auth/user.json';

export default defineConfig({
  testDir: './e2e',
  // В CI ловим случайно закоммиченный test.only и даём ретраи на флейки.
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // Один воркер в CI, если E2E делят общую БД; локально — параллельно.
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'html',

  use: {
    baseURL: BASE_URL,
    // Действия-якоря цепляем за data-testid, а не за классы/текст.
    testIdAttribute: 'data-testid',
    // Артефакты только при падении — диагностика без раздувания.
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    // 1) Одноразовый логин: проходит форму и сохраняет сессию в storageState.
    { name: 'setup', testMatch: /.*\.setup\.ts/ },

    // 2) Сценарии залогиненного пользователя — стартуют уже с сессией.
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: STORAGE_STATE },
      dependencies: ['setup'],
      // Флоу без авторизации (регистрация, публичные страницы) держим отдельно.
      testIgnore: /.*\.public\.spec\.ts/,
    },
    {
      name: 'chromium-public',
      use: { ...devices['Desktop Chrome'] }, // без storageState
      testMatch: /.*\.public\.spec\.ts/,
    },
    // Доп. браузеры включай по необходимости:
    // { name: 'firefox', use: { ...devices['Desktop Firefox'], storageState: STORAGE_STATE }, dependencies: ['setup'] },
  ],

  // Поднимаем приложение перед прогоном; локально переиспользуем уже запущенный сервер.
  webServer: {
    command: 'npm run serve:e2e',
    url: BASE_URL,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
    // Изоляция: приложение пишет в тестовую БД и тестовый диск медиа, не в боевые.
    env: {
      APP_ENV: 'testing',
      DB_DATABASE: process.env.E2E_DB_DATABASE ?? 'app_e2e',
      MEDIA_DISK: 'media-test',
    },
  },
});
