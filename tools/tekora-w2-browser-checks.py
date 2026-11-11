"""Check the disposable SQLite-memory export, never a live Laravel installation.

Requires Python Playwright and Chromium outside the application dependencies.
"""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8016')
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    parser.add_argument('--axe', type=Path)
    parser.add_argument('--record', action='store_true')
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname in ('127.0.0.1', 'localhost') and not origin.path
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'matrix': [], 'axe': [], 'checks': {}, 'page_errors': [], 'failed_resources': []}

    def observe(page):
        page.on('pageerror', lambda error: result['page_errors'].append(str(error)))
        page.on('requestfailed', lambda request: result['failed_resources'].append(request.url))
        page.on('response', lambda response: result['failed_resources'].append(f'{response.status} {response.url}') if response.status >= 400 else None)

    def goto(page, path):
        response = page.goto(args.base_url + path, wait_until='networkidle')
        assert response.status == 200 and response.header_value('x-tekora-preview') == 'synthetic-static-memory-export', 'Use the static memory-export router.'
        page.wait_for_timeout(850)

    def capture(page, name):
        page.screenshot(path=str(args.output / name), full_page=True)

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=args.executable, args=['--disable-dev-shm-usage'])
        gpu = browser.new_browser_cdp_session().send('SystemInfo.getInfo')['gpu']
        result['environment'] = {'browser': browser.version, 'headed': False, 'renderer': gpu.get('auxAttributes', {}).get('glRenderer'), 'feature_status': gpu.get('featureStatus')}
        paths = ['/cuenta/cliente', '/cuenta/prestador/dashboard?period=7', '/cuenta/prestador/dashboard?period=30', '/cuenta/prestador/dashboard?period=90', '/preview/client-empty', '/preview/provider-empty', '/preview/provider-setup', '/cuenta', '/cuenta/prestador', '/servicios']
        for width in (320, 375, 768, 1024, 1440):
            context = browser.new_context(viewport={'width': width, 'height': 950})
            page = context.new_page()
            observe(page)
            for path in paths:
                goto(page, path)
                assert page.locator('h1').first.is_visible(), (width, path)
                assert page.evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'), (width, path, 'overflow')
                if 'dashboard?period=' in path:
                    assert page.locator('[data-kpi]').count() == 10
                    days = int(path.split('=')[-1])
                    rows = page.locator('.dashboard-data tbody tr')
                    assert rows.count() == days
                    counts = rows.locator('td').all_text_contents()
                    bars = page.locator('[data-dashboard-chart] rect')
                    assert bars.count() == days
                    assert [int(x.split(': ')[-1].split(' ')[0]) for x in bars.locator('title').all_text_contents()] == [int(x) for x in counts]
                    assert int(page.locator('[data-kpi="completed"] .dashboard-value').inner_text()) == sum(map(int, counts))
                if path == '/cuenta/cliente':
                    assert page.locator('[data-dashboard-card]').count() == 4
                    assert page.locator('.dashboard-value').all_text_contents() == ['3', '1', '1', '2']
                    assert 'Cuenta completa' in page.locator('.tk-client-meta').inner_text()
                result['matrix'].append({'width': width, 'path': path, 'no_overflow': True})
                screenshots = {'/cuenta/cliente': 'client', '/cuenta/prestador/dashboard?period=30': 'provider'}
                if width in (375, 1440) and path in screenshots:
                    capture(page, f'{screenshots[path]}-{width}.png')
                if width == 1440 and path in ('/cuenta/prestador/dashboard?period=7', '/cuenta/prestador/dashboard?period=90', '/preview/provider-empty', '/cuenta'):
                    capture(page, path.split('/')[-1].replace('?period=', '-') + '-1440.png')
                if width == 375 and path in ('/preview/client-empty', '/preview/provider-setup', '/cuenta/prestador'):
                    capture(page, path.split('/')[-1] + '-375.png')
                if args.axe and width in (375, 1440) and path in ('/cuenta/cliente', '/cuenta/prestador/dashboard?period=30', '/cuenta/prestador'):
                    page.evaluate(args.axe.read_text())
                    audit = page.evaluate("async () => await axe.run(document, {runOnly: {type: 'tag', values: ['wcag2a','wcag2aa','wcag21aa']}})")
                    entry = {'width': width, 'path': path, 'violations': audit['violations'], 'incomplete': [{'id': item['id'], 'nodes': len(item['nodes'])} for item in audit['incomplete']]}
                    result['axe'].append(entry)
            context.close()

        # Keyboard: native details, focus visibility, Escape, and the skip link.
        context = browser.new_context(viewport={'width': 375, 'height': 950})
        page = context.new_page()
        observe(page)
        goto(page, '/cuenta/cliente')
        page.keyboard.press('Tab')
        assert page.locator('.tk-shell-skip').evaluate('(node) => node === document.activeElement')
        assert page.locator('.tk-shell-skip').evaluate('(node) => getComputedStyle(node).outlineStyle') != 'none'
        menu = page.locator('[data-account-navigation] > summary')
        menu.focus()
        page.keyboard.press('Enter')
        assert page.locator('[data-account-navigation]').evaluate('(node) => node.open')
        page.keyboard.press('Escape')
        assert not page.locator('[data-account-navigation]').evaluate('(node) => node.open')
        assert menu.evaluate('(node) => node === document.activeElement')
        result['checks']['keyboard_skip_menu_escape'] = True
        context.close()

        for width in (375, 1440):
            context = browser.new_context(viewport={'width': width, 'height': 950}, java_script_enabled=False)
            page = context.new_page()
            for path in ('/cuenta/cliente', '/cuenta/prestador/dashboard?period=30'):
                goto(page, path)
                assert page.locator('.sidebar').is_visible()
                assert page.locator('.dashboard-value').first.is_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
                if 'prestador' in path:
                    page.locator('.dashboard-data > summary').click()
                    assert page.locator('.dashboard-data table').is_visible()
                    page.select_option('#dashboard-period', '7')
                    page.get_by_role('button', name='Aplicar', exact=True).click()
                    page.wait_for_url('**?period=7')
                    assert page.locator('.dashboard-data tbody tr').count() == 7
            context.close()
        result['checks']['no_javascript_native_menu_table_get_filters'] = True

        for width in (375, 1440):
            for role, path in (('client', '/cuenta/cliente'), ('provider', '/cuenta/prestador/dashboard?period=30')):
                options = {'viewport': {'width': width, 'height': 950}}
                if args.record:
                    options.update(record_video_dir=str(args.output / 'video-temp'), record_video_size={'width': width, 'height': 950})
                context = browser.new_context(**options)
                page = context.new_page()
                observe(page)
                goto(page, path)
                page.mouse.wheel(0, 700)
                page.wait_for_timeout(900)
                if role == 'provider':
                    page.locator('.dashboard-data > summary').click()
                    page.wait_for_timeout(700)
                    for days in ('7', '90'):
                        page.locator('#dashboard-period').scroll_into_view_if_needed()
                        before = page.evaluate('performance.timeOrigin')
                        page.select_option('#dashboard-period', days)
                        page.get_by_role('button', name='Aplicar', exact=True).click()
                        page.wait_for_url('**?period=' + days)
                        page.wait_for_timeout(900)
                        assert page.evaluate('performance.timeOrigin') != before
                        assert page.locator('.dashboard-data tbody tr').count() == int(days)
                    goto(page, '/preview/provider-empty')
                    goto(page, '/preview/provider-setup')
                else:
                    page.locator('.tk-dual-account').scroll_into_view_if_needed()
                    page.wait_for_timeout(700)
                    goto(page, '/preview/client-empty')
                video = page.video
                context.close()
                if video:
                    video.save_as(str(args.output / f'{role}-{width}.webm'))
                    video.delete()
        result['checks']['native_navigation_filters_and_fixture_states'] = True

        options = {'viewport': {'width': 1440, 'height': 950}, 'reduced_motion': 'reduce'}
        if args.record:
            options.update(record_video_dir=str(args.output / 'video-temp'), record_video_size={'width': 1440, 'height': 950})
        context = browser.new_context(**options)
        page = context.new_page()
        observe(page)
        page.context.new_cdp_session(page).send('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-reduced-motion', 'value': 'reduce'}, {'name': 'prefers-reduced-transparency', 'value': 'reduce'}]})
        for role, path in (('client', '/cuenta/cliente'), ('provider', '/cuenta/prestador/dashboard?period=30')):
            goto(page, path)
            assert page.locator('[data-dashboard-card]').first.evaluate('(node) => getComputedStyle(node).opacity') == '1'
            assert page.locator('.account-card').first.evaluate('(node) => getComputedStyle(node).backgroundImage') == 'none'
            assert page.evaluate("document.getAnimations().filter(a => a.playState === 'running').length") == 0
            page.locator('.tk-dashboard .account-button').first.hover()
            assert page.locator('.tk-dashboard .account-button').first.evaluate('(node) => getComputedStyle(node).transform') == 'none'
            page.mouse.move(0, 0)
            capture(page, role + '-reduced-1440.png')
            page.mouse.wheel(0, 750)
            page.wait_for_timeout(700)
        video = page.video
        context.close()
        if video:
            video.save_as(str(args.output / 'reduced-motion.webm'))
            video.delete()
        result['checks']['reduced_motion_and_transparency'] = True

        # W1 authentication still uses the shared Anime chunk after the Vite split.
        context = browser.new_context(viewport={'width': 375, 'height': 950})
        page = context.new_page()
        observe(page)
        goto(page, '/login')
        page.locator('[data-auth-switch]').first.click()
        page.wait_for_url('**/registro')
        page.wait_for_timeout(1200)
        assert page.locator('[data-auth-panel]').is_visible()
        assert page.locator('[data-auth-content] form').count() == 1
        assert not page.locator('[data-auth-content]').evaluate('(node) => node.inert')
        result['checks']['w11_authentication_shared_chunk_smoke'] = True
        context.close()
        browser.close()

    (args.output / 'browser-results.json').write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n')
    assert not result['page_errors'], result['page_errors']
    assert not result['failed_resources'], result['failed_resources']
    assert not any(item['violations'] for item in result['axe']), 'Inspect accessibility violations in browser-results.json'
    print(json.dumps({'matrix_cases': len(result['matrix']), 'axe_runs': len(result['axe']), 'checks': result['checks'], 'errors': 0}, ensure_ascii=False))


if __name__ == '__main__':
    main()
