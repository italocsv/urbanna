import { test, expect } from '@playwright/test';

test('test', async ({ page }) => {
  await page.goto('https://seller.shopee.com.br/');
  await page.goto('https://seller.shopee.com.br/portal/product/list/live/all?operationSortBy=recommend_v2');
  await page.getByRole('textbox', { name: 'Pesquisar Nome do Produto,' }).fill('304666255');
  const page1Promise = page.waitForEvent('popup');
  await page.goto('https://seller.shopee.com.br/portal/product/list/live/all?operationSortBy=recommend_v2&keyword=304666255&page=1');
  const page1 = await page1Promise;
  await page1.goto('https://seller.shopee.com.br/portal/product/47517094746');
});