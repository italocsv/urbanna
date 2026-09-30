import { test, expect } from '@playwright/test';

test('test', async ({ page }) => {
  await page.goto('https://www.mercadolivre.com.br/');
  await page.getByRole('combobox', { name: 'Digite o que você quer' }).fill('b');
  await page.getByRole('combobox', { name: 'Digite o que você quer' }).click();
  await page.getByRole('combobox', { name: 'Digite o que você quer' }).fill('bolsa santa lolla');
  await page.getByRole('combobox', { name: 'Digite o que você quer' }).press('Enter');
});