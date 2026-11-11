"""W6.2b browser acceptance over guarded W5 memory-only static exports."""
import argparse
import json
import re
import shutil
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--baseline-url', required=True, help='Identical guarded export from the mandatory base SHA')
    parser.add_argument('--pages', type=Path, required=True)
    parser.add_argument('--axe', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname == '127.0.0.1' and not origin.path
    baseline_origin = urlsplit(args.baseline_url)
    assert baseline_origin.scheme == 'http' and baseline_origin.hostname == '127.0.0.1' and not baseline_origin.path
    args.output.mkdir(parents=True, exist_ok=False)
    exported = json.loads((args.pages / 'w5-pages.json').read_text())
    linked = json.loads((args.pages / 'w5-linked-pages.json').read_text())
    pages = {name: exported[name]['path'] for name in [
        'account-client', 'account-provider', 'account-dual', 'account-admin', 'account-none',
        'activation-pending', 'profile-photo', 'edit-name', 'roles', 'forgot', 'reset',
    ]}
    pages.update({name: item['path'] for name, item in linked.items()})
    pages.update({'home': '/', 'login': '/login', 'registration': '/registro'})
    retired = re.compile(r'^/(?:mindbloom(?:/|$)|comunidad(?:/|$)|conferencias(?:/|$)|citas(?:/|$)|consultas(?:/|$)|profesionistas(?:/|$)|profesionista(?:/|$)|google(?:/|$)|cuenta/citas(?:/|$)|(?:login|registro|logout|dashboard)-profesionista(?:/|$))')
    result = {'source': 'Real GET controllers with synthetic SQLite :memory: fixtures; static GET/HEAD server; no browser POST',
              'matrix': [], 'axe': [], 'errors': [], 'checks': {}, 'existing_overflow': []}

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=shutil.which('chromium'), args=['--disable-dev-shm-usage'])
        result['browser'] = browser.version
        for width in [320, 375, 768, 1024, 1100, 1440, 1920]:
            context = browser.new_context(viewport={'width': width, 'height': 1000}, reduced_motion='reduce')
            page = context.new_page()
            page.on('pageerror', lambda error: result['errors'].append(str(error)))
            page.on('requestfailed', lambda request: result['errors'].append(request.url))
            page.on('response', lambda response: result['errors'].append(f'{response.status} {response.url}') if response.status >= 400 else None)
            for name, path in pages.items():
                response = page.goto(args.base_url + path, wait_until='networkidle')
                assert response.status == 200, (name, response.status)
                assert response.header_value('x-tekora-preview') == 'synthetic-static-memory-export'
                page.evaluate('document.fonts.ready')
                assert 'MindBloom' not in page.locator('body').inner_text(), name
                assert page.locator('.account-legacy,.tk-legacy-panel,.tk-identity-history,.legacy-access').count() == 0
                urls = page.locator('a[href],form[action]').evaluate_all('(nodes)=>nodes.map(n=>n.href||n.action)')
                assert not any(retired.match(urlsplit(url).path) for url in urls), (name, urls)
                metrics = page.evaluate('''()=>({no_horizontal:document.documentElement.scrollWidth<=innerWidth+1,
                    scroll_width:document.documentElement.scrollWidth,
                    h1:document.querySelectorAll('main h1').length,
                    duplicate_ids:(()=>{const ids=[...document.querySelectorAll('[id]')].map(n=>n.id);return ids.length-new Set(ids).size})()})''')
                assert metrics['h1'] == 1 and metrics['duplicate_ids'] == 0, (name, width, metrics)
                if not metrics['no_horizontal']:
                    baseline_page = context.new_page()
                    baseline_page.goto(args.baseline_url + path, wait_until='networkidle')
                    baseline_page.evaluate('document.fonts.ready')
                    baseline_width = baseline_page.evaluate('document.documentElement.scrollWidth')
                    baseline_page.close()
                    assert metrics['scroll_width'] == baseline_width, (name, width, metrics, baseline_width)
                    result['existing_overflow'].append({'page': name, 'viewport': width,
                        'base_scroll_width': baseline_width, 'current_scroll_width': metrics['scroll_width']})
                result['matrix'].append({'page': name, 'width': width, **metrics})
                if width in (375, 1440):
                    page.evaluate(args.axe.read_text())
                    violations = page.evaluate("async()=> (await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})).violations")
                    result['axe'].append({'page': name, 'width': width, 'violations': violations})
                    assert not violations, (name, width, violations)
                    if name in ['login', 'profile-photo', 'client-dashboard', 'provider-dashboard']:
                        page.screenshot(path=str(args.output / f'{name}-{width}.png'), full_page=True)
            context.close()
            print(f'{width}px: {len(pages)} pages', flush=True)
        context = browser.new_context(viewport={'width': 375, 'height': 844})
        page = context.new_page()
        page.goto(args.base_url + pages['account-dual'], wait_until='networkidle')
        summary = page.locator('[data-account-navigation] > summary')
        summary.focus()
        page.keyboard.press('Enter')
        assert page.locator('[data-account-navigation]').evaluate('(node)=>node.open')
        controls = page.locator('.tk-sidebar-scroll a:visible,.tk-sidebar-scroll button:visible')
        page.locator('.tk-sidebar-scroll').focus()
        for control in controls.all():
            page.keyboard.press('Tab')
            assert control.evaluate('(node)=>node===document.activeElement')
        assert page.locator('.logout-btn').evaluate('(node)=>node===document.activeElement')
        assert page.locator('.logout-form').get_attribute('method').upper() == 'POST'
        assert page.locator('.logout-form input[name=_token]').input_value()
        page.keyboard.press('Shift+Tab')
        assert page.locator('.tk-sidebar-navigation a[href$="/perfil"]').evaluate('(node)=>node===document.activeElement')
        result['checks']['mobile_keyboard_menu_all_links_logout_and_reverse_tab'] = True
        context.close()
        context = browser.new_context(viewport={'width': 375, 'height': 844}, java_script_enabled=False)
        page = context.new_page()
        for name in ['login', 'registration', 'reset', 'profile-photo']:
            page.goto(args.base_url + pages[name], wait_until='networkidle')
            assert page.locator('main form[method=POST] input[name=_token]').count() >= 1
            assert page.locator('main form[method=POST] button[type=submit]').first.is_visible()
        result['checks']['native_forms_available_without_javascript'] = True
        context.close()
        browser.close()
    assert not result['errors'], result['errors']
    (args.output / 'browser-results.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({'matrix': len(result['matrix']), 'axe': len(result['axe']), 'errors': result['errors'], 'checks': result['checks']}))


if __name__ == '__main__':
    main()
