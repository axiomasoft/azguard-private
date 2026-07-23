// Source: anonymized production project
// Два файла в одном примере: auth.setup.ts (одноразовый логин → storageState)
// и order-checkout.spec.ts (сценарий залогиненного пользователя + многошаговая форма).

// ───────────────────────── e2e/auth.setup.ts ─────────────────────────
// Project "setup": логинимся ОДИН раз за прогон и сохраняем сессию в файл.
// Остальные projects подхватывают её через use.storageState и стартуют залогиненными.
import { test as setup, expect } from '@playwright/test';

const STORAGE_STATE = 'playwright/.auth/user.json';

setup('authenticate', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(process.env.E2E_USER ?? 'user@example.com');
  await page.getByTestId('login-password').fill(process.env.E2E_PASSWORD ?? 'password');
  await page.getByTestId('login-submit').click();

  // Ждём не таймаутом, а признаком успешного входа (URL/элемент дашборда).
  await page.waitForURL('**/dashboard');
  await expect(page.getByTestId('user-menu')).toBeVisible();

  // Сохраняем cookies + localStorage; playwright/.auth/ — в .gitignore.
  await page.context().storageState({ path: STORAGE_STATE });
});

// ─────────────────────── e2e/order-checkout.spec.ts ───────────────────────
// Сценарий уже залогинен (storageState из конфига). Домены нейтральны: Order/Article.
import { test, expect } from '@playwright/test';

test.describe('Order checkout', () => {
  test('user adds an article and completes a multi-step order', async ({ page }) => {
    await page.goto('/catalog');

    // Действия — через data-testid, а не через CSS-классы или видимый текст.
    await page.getByTestId('article-card-1').getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count')).toHaveText('1');

    await page.goto('/checkout');

    // Шаг 1: контактные данные. Уникальный суффикс → повторный прогон не падает на unique-ключах.
    const suffix = Date.now();
    await page.getByTestId('order-email').fill(`buyer+${suffix}@example.com`);
    await page.getByTestId('order-phone').fill('+10000000000');
    await page.getByTestId('checkout-next').click();

    // Переход на шаг 2 — ждём появления его контента (авто-вэйтинг, без sleep).
    await expect(page.getByTestId('checkout-step-shipping')).toBeVisible();

    // Шаг 2: загрузка файла из фикстур (пишется в изолированный media-test диск).
    await page.getByTestId('order-attachment').setInputFiles('e2e/fixtures/document.pdf');
    await page.getByTestId('checkout-submit').click();

    // Проверяем итог по содержательному признаку (роль/текст), а не по верстке.
    await expect(page.getByRole('heading', { name: /order confirmed/i })).toBeVisible();
    await expect(page).toHaveURL(/\/orders\/\d+/);
  });
});
