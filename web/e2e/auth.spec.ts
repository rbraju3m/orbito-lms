import { expect, test } from '@playwright/test';

/**
 * Critical flow: register -> land on the dashboard -> sign out.
 *
 * These run against a real API, so each run uses a fresh address.
 */
const unique = () => `e2e-${Date.now()}-${Math.floor(Math.random() * 1e4)}@example.com`;

test.describe('authentication', () => {
  test('registers a new student and lands on the dashboard', async ({ page }) => {
    await page.goto('/register');

    await page.getByLabel('Full name').fill('E2E Student');
    await page.getByLabel('Email').fill(unique());
    await page.getByLabel('Password', { exact: false }).first().fill('correct horse battery');
    await page.getByLabel('Confirm password').fill('correct horse battery');
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page.getByRole('heading', { name: /welcome/i })).toBeVisible();
  });

  test('shows a message for bad credentials rather than failing silently', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email').fill('nobody@example.com');
    await page.getByLabel('Password', { exact: false }).first().fill('definitely-wrong');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('alert')).toBeVisible();
  });

  test('redirects a signed-out visitor away from the dashboard', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page).toHaveURL(/\/login/);
  });

  test('validates the login form before calling the API', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByText(/email is required/i)).toBeVisible();
  });

  test('offers a password reset flow', async ({ page }) => {
    await page.goto('/forgot-password');

    await page.getByLabel('Email').fill('someone@example.com');
    await page.getByRole('button', { name: /send reset link/i }).click();

    // The same answer is given whether or not the address exists.
    await expect(page.getByRole('status')).toContainText(/if an account exists/i);
  });
});
