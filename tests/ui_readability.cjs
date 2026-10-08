const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const browser = await chromium.launch({headless:true, executablePath:process.env.CHROME_BIN || undefined, args:['--no-sandbox']});
  const page = await browser.newPage(); const errors=[];
  page.on('pageerror', error=>errors.push(error.message));
  const fixture=fs.readFileSync('/tmp/ui-preview.html','utf8');
  await page.goto('http://127.0.0.1:8099');
  await page.setContent(fixture);
  await page.waitForFunction(()=>document.querySelector('.scan-table-wrap[role="region"]'));
  fs.mkdirSync('/tmp/ui-screens',{recursive:true});
  for (const [name,width] of [['desktop',1440],['mobile',390]]) {
    await page.setViewportSize({width,height:1100});
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'page has no horizontal overflow');
    assert.notEqual(await page.locator('#scan-results .scan-col-entry').first().evaluate(e=>getComputedStyle(e).display),'none','price plan stays accessible on mobile');
    assert.equal(await page.locator('.paper-kv .price-plan dd').allTextContents().then(x=>x.join('/')),'48,050/46,260/55,200');
    await page.screenshot({path:`/tmp/ui-screens/${name}.png`,fullPage:true});
  }
  const guide=page.locator('.reading-guide summary').first();
  await guide.focus();await page.keyboard.press('Enter');
  assert(await guide.evaluate(e=>e.parentElement.open),'keyboard opens explanations');
  assert.deepEqual(errors,[]);
  await browser.close();console.log('UI_BROWSER_PASS desktop/mobile layout, visible prices, keyboard details');
})().catch(e=>{console.error(e);process.exit(1);});
