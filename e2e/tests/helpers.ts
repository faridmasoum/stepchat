import { Page, expect } from '@playwright/test';

export async function openFresh(page: Page, path = '/tests/Fixtures/e2e/index.html') {
  await page.goto(path);
  await page.evaluate(() => localStorage.clear());
  await page.context().clearCookies();
  await page.goto(path);
  await expect(page.locator('.composer input, .composer textarea, .composer .chip').first()).toBeVisible();
}

export async function sendText(page: Page, value: string) {
  const input = page.locator('.composer input, .composer textarea').first();
  await input.fill(value);
  await page.locator('.composer').getByRole('button', { name: 'Send' }).click();
}

export async function answerUntilReview(page: Page, email = 'ada@example.com') {
  await sendText(page, 'Ada');
  await expect(page.locator('.composer input[type="email"]')).toBeVisible();
  await sendText(page, email);
  await page.locator('.composer .chip', { hasText: 'Pro' }).click();
  await page.locator('.composer .chip', { hasText: 'API' }).click();
  await page.locator('.composer .chip', { hasText: 'Pricing' }).click();
  await page.locator('.composer').getByRole('button', { name: 'Continue' }).click();
  await expect(page.locator('.composer input[type="tel"]')).toBeVisible();
  await page.locator('.composer').getByRole('button', { name: 'Skip' }).click();
  await expect(page.locator('.composer textarea')).toBeVisible();
  await sendText(page, 'Hello there');
  await expect(page.locator('.card')).toBeVisible();
}