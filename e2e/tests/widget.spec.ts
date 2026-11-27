import { test, expect } from '@playwright/test';
import { openFresh, sendText, answerUntilReview } from './helpers';

test.describe('StepChat widget e2e', () => {
  let errors: string[] = [];

  test.beforeEach(async ({ page }) => {
    errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  });

  test.afterEach(() => {
    const filteredErrors = errors.filter(e => !e.includes('favicon.ico'));
    expect(filteredErrors).toEqual([]);
  });

  test('full happy path submits the lead', async ({ page }) => {
    await openFresh(page);
    await answerUntilReview(page);
    await expect(page.locator('.card .row')).toHaveCount(6); // name, email, plan, topics, phone (empty), note
    await page.locator('.composer').getByRole('button', { name: 'Submit' }).click();
    await expect(page.locator('.log')).toContainText('Thank you!');
    await expect(page.locator('.composer').getByRole('button', { name: 'Start over' })).toBeVisible();
  });

  test('invalid email shows an error and stays on the step', async ({ page }) => {
    await openFresh(page);
    await sendText(page, 'Ada');
    await sendText(page, 'not-an-email');
    await expect(page.locator('.msg.err').last()).toBeVisible();
    await expect(page.locator('.composer input[type="email"]')).toBeVisible();
  });

  test('reload resumes the conversation', async ({ page }) => {
    await openFresh(page);
    await sendText(page, 'Ada');
    await expect(page.locator('.composer input[type="email"]')).toBeVisible();
    await page.reload();
    await expect(page.locator('.log')).toContainText('Ada');
    await expect(page.locator('.composer input[type="email"]')).toBeVisible();
  });

  test('edit from review updates the answer', async ({ page }) => {
    await openFresh(page);
    await answerUntilReview(page);
    // Find the edit button for the email row and click via JS
    await page.locator('.card .row').filter({ hasText: 'Your email?' }).getByRole('button', { name: 'Edit' }).evaluate((el: HTMLButtonElement) => el.click());
    await expect(page.locator('.composer input[type="email"]')).toBeVisible();
    await sendText(page, 'new@example.com');
    // Wait for any card to be visible and check email value in the last row with "Your email?"
    await page.waitForTimeout(500); // brief wait for DOM update
    const emailRows = page.locator('.card .row').filter({ hasText: 'Your email?' });
    const count = await emailRows.count();
    // Check the last one (most recent)
    await expect(emailRows.nth(count - 1)).toContainText('new@example.com');
  });

  test('restart clears the conversation', async ({ page }) => {
    await openFresh(page);
    await sendText(page, 'Ada');
    await expect(page.locator('.composer input[type="email"]')).toBeVisible();
    await page.locator('.head button', { hasText: '↻' }).click();
    await expect(page.locator('.composer input[type="text"]')).toBeVisible();
    await expect(page.locator('.log .msg.user')).toHaveCount(0);
  });

  test('two tabs: stale tab gets the current step (409)', async ({ page }) => {
    const pageA = page;
    await openFresh(pageA);
    const pageB = await page.context().newPage();
    // Don't clear cookies for pageB - it should share session with pageA
    await pageB.goto('/tests/Fixtures/e2e/index.html');
    await expect(pageB.locator('.composer input, .composer textarea, .composer .chip').first()).toBeVisible();
    
    await pageA.locator('.composer input').fill('Ada');
    await pageA.locator('.composer').getByRole('button', { name: 'Send' }).click();
    await expect(pageA.locator('.composer input[type="email"]')).toBeVisible({ timeout: 10000 });
    
    await pageB.locator('.composer input').fill('Bob');
    await pageB.locator('.composer').getByRole('button', { name: 'Send' }).click();
    await expect(pageB.locator('.msg.err')).toContainText('The conversation moved on');
    await expect(pageB.locator('.composer input[type="email"]')).toBeVisible();
    await pageB.close();
  });

  test('offline shows a connection error', async ({ page }) => {
    await openFresh(page);
    await page.route('**/endpoint.php', r => r.abort());
    await sendText(page, 'Ada');
    await expect(page.locator('.msg.err')).toContainText('Connection problem');
    await expect(page.locator('.composer input[type="text"]')).toBeVisible();
    errors = []; // the aborted fetch logs a console error; reset after test
  });

  test('fires acw:complete with the summary', async ({ page }) => {
    await openFresh(page);
    await page.evaluate(() => {
      (window as any).__done = null;
      document.addEventListener('acw:complete', (e: any) => {
        (window as any).__done = e.detail;
      });
    });
    await answerUntilReview(page);
    await page.locator('.composer').getByRole('button', { name: 'Submit' }).click();
    await expect.poll(() => page.evaluate(() => (window as any).__done?.flow)).toBe('lead');
  });

  test('inline mode renders without launcher', async ({ page }) => {
    await openFresh(page, '/tests/Fixtures/e2e/inline.html');
    await expect(page.locator('.launcher')).toHaveCount(0);
    await expect(page.locator('#chat [data-acw]')).toHaveCount(1);
  });

  test('RTL locale switches direction and labels', async ({ page }) => {
    await openFresh(page, '/tests/Fixtures/e2e/rtl.html');
    await expect(page.locator('.root')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('.composer').getByRole('button', { name: 'ارسال' })).toBeVisible();
  });
});