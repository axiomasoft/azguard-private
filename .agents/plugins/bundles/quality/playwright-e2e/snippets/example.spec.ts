// Source: anonymized production project
// Two files in one example: auth.setup.ts (one-time login → storageState)
// and order-checkout.spec.ts (logged in user script + multi-step form).

// ───────────────────────── e2e/auth.setup.ts ─────────────────────────
// Project "setup": log in ONCE per run and save the session to a file.
// Others projects pick it up through use.storageState and start logged in.
import { test as setup, expect } from '@playwright/test';

const STORAGE_STATE = 'playwright/.auth/user.json';

setup('authenticate', async ({ page }) => {
  await page.goto('/login');
  await page.getByTestId('login-email').fill(process.env.E2E_USER ?? 'user@example.com');
  await page.getByTestId('login-password').fill(process.env.E2E_PASSWORD ?? 'password');
  await page.getByTestId('login-submit').click();

  // We are waiting not for a timeout, but for a sign of successful entry (URL/dashboard element).
  await page.waitForURL('**/dashboard');
  await expect(page.getByTestId('user-menu')).toBeVisible();

  // Save cookies + localStorage; playwright/.auth/ — in .gitignore.
  await page.context().storageState({ path: STORAGE_STATE });
});

// ─────────────────────── e2e/order-checkout.spec.ts ───────────────────────
// Script is already logged in (storageState from the config). Domains are neutral: Order/Article.
import { test, expect } from '@playwright/test';

test.describe('Order checkout', () => {
  test('user adds an article and completes a multi-step order', async ({ page }) => {
    await page.goto('/catalog');

    // Actions - via data-testid, and not through CSS-classes or visible text.
    await page.getByTestId('article-card-1').getByTestId('add-to-cart').click();
    await expect(page.getByTestId('cart-count')).toHaveText('1');

    await page.goto('/checkout');

    // Step 1: contact information. Unique suffix → rerun does not crash on unique-keys.
    const suffix = Date.now();
    await page.getByTestId('order-email').fill(`buyer+${suffix}@example.com`);
    await page.getByTestId('order-phone').fill('+10000000000');
    await page.getByTestId('checkout-next').click();

    // Go to step 2 - wait for its content to appear (auto-waiting, without sleep).
    await expect(page.getByTestId('checkout-step-shipping')).toBeVisible();

    // Step 2: Loading a file from fixtures (is written to isolated media-test disk).
    await page.getByTestId('order-attachment').setInputFiles('e2e/fixtures/document.pdf');
    await page.getByTestId('checkout-submit').click();

    // Checking the result based on content (role/text), and not by layout.
    await expect(page.getByRole('heading', { name: /order confirmed/i })).toBeVisible();
    await expect(page).toHaveURL(/\/orders\/\d+/);
  });
});
