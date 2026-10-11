"""Takes the store screenshots from a throwaway Nextcloud (see screenshots.yml)."""
import os
import sys

from playwright.sync_api import sync_playwright

BASE = 'http://localhost:8080'
OUT = sys.argv[1]


def files(page):
    page.goto(BASE + '/apps/files/files?dir=/Screenshots')
    page.wait_for_selector('tr:has-text("chat-lunch")', timeout=60000)
    page.wait_for_timeout(3000)


with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_context(viewport={'width': 1440, 'height': 900}, locale='en-US').new_page()
    page.goto(BASE + '/login')
    page.fill('input[name="user"]', 'demo')
    page.fill('input[name="password"]', os.environ['SHOT_PASSWORD'])
    page.keyboard.press('Enter')
    page.wait_for_url('**/apps/**', timeout=60000)

    try:
        files(page)
        header = page.locator('header button, header a[role="button"]')
        print('header buttons:', [header.nth(i).get_attribute('aria-label') for i in range(header.count())])
        page.keyboard.press('Control+f')
        page.wait_for_timeout(1500)
        page.keyboard.type('booking code', delay=40)
        page.wait_for_timeout(1500)
        page.screenshot(path=OUT + '/step-local-search.png')
        everywhere = page.get_by_role('button', name='Search everywhere')
        print('search everywhere buttons:', everywhere.count())
        if everywhere.count():
            everywhere.first.click()
        page.wait_for_timeout(6000)
        page.screenshot(path=OUT + '/search.png')
        print('result section visible:', page.get_by_text('Text in images').count())
        page.keyboard.press('Escape')

        files(page)
        page.locator('tr:has-text("chat-lunch")').first.click(button='right')
        page.wait_for_timeout(1000)
        page.screenshot(path=OUT + '/step-menu.png')
        items = page.get_by_role('menuitem')
        print('menu:', [items.nth(i).inner_text().strip() for i in range(items.count())])
        page.get_by_role('menuitem', name='details').first.click()
        page.wait_for_timeout(3000)
        tabs = page.get_by_role('tab')
        print('tabs:', [tabs.nth(i).inner_text().strip() for i in range(tabs.count())])
        page.screenshot(path=OUT + '/step-sidebar.png')
        page.get_by_role('tab', name='Text').first.click()
        page.wait_for_timeout(3000)
        page.screenshot(path=OUT + '/sidebar.png')
    finally:
        page.screenshot(path=OUT + '/step-last.png')
    browser.close()
