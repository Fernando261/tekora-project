"""W2.1 browser acceptance against the loopback-only, memory-fixture static preview.

Uses external Python Playwright/Chromium and optional axe-core; no app dependencies.
"""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8017')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    parser.add_argument('--axe', type=Path)
    parser.add_argument('--record', action='store_true')
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname == '127.0.0.1' and not origin.path
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'matrix': [], 'axe': [], 'checks': {}, 'capsule': [], 'page_errors': [], 'failed_resources': []}
    client, provider = '/cuenta/cliente', '/cuenta/prestador/dashboard'

    def observe(page):
        page.on('pageerror', lambda error: result['page_errors'].append(str(error)))
        page.on('requestfailed', lambda request: result['failed_resources'].append(request.url))
        page.on('response', lambda response: result['failed_resources'].append(f'{response.status} {response.url}') if response.status >= 400 else None)

    def goto(page, path):
        response = page.goto(args.base_url + path, wait_until='networkidle')
        assert response.status == 200 and response.header_value('x-tekora-preview') == 'synthetic-static-memory-export', 'Never run against a live Laravel app.'
        page.wait_for_timeout(650)

    def choose(page, key):
        page.locator(f'button[data-dashboard-tab="{key}"]').click()
        page.wait_for_timeout(650)
        verify(page, key)

    def verify(page, key):
        assert page.locator('[data-dashboard]').get_attribute('data-dashboard-view') == key
        assert page.locator('[role=tab][aria-selected=true]').get_attribute('data-dashboard-tab') == key
        assert page.locator('[role=tabpanel]').get_attribute('aria-busy') is None
        for module in page.locator('[data-dashboard-module]').all():
            assert module.is_visible() == (key in module.get_attribute('data-dashboard-module').split())
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')

    def shot(page, name):
        page.screenshot(path=str(args.output / name), full_page=True)

    def chart(page, days):
        assert page.locator('[data-kpi]').count() == 10
        assert page.locator('.dashboard-data tbody tr').count() == days
        assert page.locator('[data-dashboard-chart] rect').count() == days
        counts = list(map(int, page.locator('.dashboard-data tbody td').all_text_contents()))
        assert [int(value.rsplit(': ', 1)[-1].split()[0]) for value in page.locator('[data-dashboard-chart] rect title').all_text_contents()] == counts
        assert page.evaluate('''() => {
            const svg = document.querySelector('[data-dashboard-chart]');
            const points = svg.querySelector('polyline').points;
            return [...svg.querySelectorAll('rect')].every((rect, i) =>
                Math.abs(points[i].x - (rect.x.baseVal.value + rect.width.baseVal.value / 2)) < .001 &&
                Math.abs(points[i].y - rect.y.baseVal.value) < .001);
        }''')

    def capsule(page, index, label):
        frames = page.evaluate('''(index) => new Promise(resolve => {
            const track = document.querySelector('[data-dashboard-track]');
            const pill = track.querySelector('[data-dashboard-pill]');
            const buttons = [...track.querySelectorAll('button')];
            const samples = [], start = performance.now();
            const measure = () => {
                const parts = getComputedStyle(pill).clipPath.split(' round')[0].slice(6).trim().split(/\\s+/).map(parseFloat);
                const [t, r = t, b = t, l = r] = parts;
                const tab = buttons[index].getBoundingClientRect(), bounds = track.getBoundingClientRect();
                samples.push({ms:performance.now()-start, left:l, right:r, width:bounds.width-l-r,
                    target_left:tab.left-bounds.left,target_right:bounds.right-tab.right,
                    overflow:document.documentElement.scrollWidth > innerWidth+1});
                if (performance.now()-start < 750) requestAnimationFrame(measure); else resolve(samples);
            };
            buttons[index].click(); requestAnimationFrame(measure);
        })''', index)
        assert len(frames) > 5
        final = frames[-1]
        assert abs(final['left'] - final['target_left']) < 1 and abs(final['right'] - final['target_right']) < 1
        assert not any(frame['overflow'] for frame in frames)
        target_width = final['width']
        assert max(frame['width'] for frame in frames) > target_width + 20, (label, 'pill must stretch, not just slide')
        result['capsule'].append({'label': label, 'frames': frames})

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=args.executable, args=['--disable-dev-shm-usage'])
            gpu = browser.new_browser_cdp_session().send('SystemInfo.getInfo')['gpu']
            result['environment'] = {'browser': browser.version, 'headless': True, 'renderer': gpu.get('auxAttributes', {}).get('glRenderer'), 'feature_status': gpu.get('featureStatus')}
            for width in (320, 375, 768, 1024, 1440, 1920):
                context = browser.new_context(viewport={'width': width, 'height': 1000})
                page = context.new_page()
                observe(page)
                for role, path, views in [('client', client, ['summary', 'reservations', 'requests']), ('provider', provider + '?period=30', ['summary', 'activity', 'agenda', 'requests'])]:
                    goto(page, path)
                    assert page.locator('[data-dashboard]').get_attribute('data-dashboard-motion') == 'ready'
                    if role == 'provider':
                        chart(page, 30)
                    else:
                        assert page.locator('[data-client-kpi] .dashboard-value').all_text_contents() == ['3', '1', '1', '2']
                    for key in views:
                        choose(page, key)
                        if width <= 1024:
                            for control in page.locator('.tk-shell-bell,.tk-shell-person,.dashboard-kpi-link,.account-button,.tk-panel-link').all():
                                if control.is_visible():
                                    assert control.bounding_box()['height'] >= 43.9, (width, role, key, 'touch target')
                        result['matrix'].append({'width': width, 'path': path, 'view': key, 'no_overflow': True})
                        if width in (375, 1440):
                            shot(page, f'{role}-{key}-{width}.png')
                        if width == 1440 and role == 'provider':
                            page.screenshot(path=str(args.output / f'compare-{key}.png'), full_page=False)
                        if args.axe and width in (375, 1440):
                            page.evaluate(args.axe.read_text())
                            audit = page.evaluate("async () => await axe.run(document, {runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})")
                            result['axe'].append({'width': width, 'role': role, 'view': key, 'violations': audit['violations'], 'incomplete': [{'id': item['id'], 'nodes': len(item['nodes'])} for item in audit['incomplete']]})
                for days in (7, 90):
                    goto(page, provider + f'?period={days}')
                    chart(page, days)
                    choose(page, 'activity')
                    result['matrix'].append({'width': width, 'period': days, 'view': 'activity', 'no_overflow': True})
                for label, path in [('client-empty', '/preview/client-empty'), ('provider-empty', '/preview/provider-empty'), ('provider-setup', '/preview/provider-setup'), ('account', '/cuenta'), ('form', '/cuenta/prestador'), ('catalog', '/servicios')]:
                    goto(page, path)
                    assert page.locator('h1').first.is_visible()
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'), (width, path)
                    result['matrix'].append({'width': width, 'path': path, 'no_overflow': True})
                    if width in (375, 1440) and 'empty' in label or width == 375 and label == 'provider-setup':
                        shot(page, f'{label}-{width}.png')
                context.close()
                (args.output / 'browser-results.json').write_text(json.dumps(result, indent=2, ensure_ascii=False))

            context = browser.new_context(viewport={'width': 1440, 'height': 1000})
            page = context.new_page()
            observe(page)
            goto(page, provider)
            capsule(page, 3, 'right-360-550')
            capsule(page, 0, 'left-360-550')
            page.locator('[role=tab]').first.focus()
            assert page.locator('[role=tab]').first.evaluate('''node => ['altKey','ctrlKey','metaKey'].every(modifier => {
                const event=new KeyboardEvent('keydown',{key:'ArrowLeft',[modifier]:true,bubbles:true,cancelable:true});
                return node.dispatchEvent(event);
            })'''), 'Do not intercept modified browser shortcuts.'
            for key, expected in [('ArrowRight', 'activity'), ('End', 'requests'), ('Home', 'summary'), ('ArrowLeft', 'requests'), ('ArrowRight', 'summary'), ('Space', 'summary'), ('Enter', 'summary')]:
                page.keyboard.press(key)
                page.wait_for_timeout(650)
                verify(page, expected)
                assert page.locator(f'[data-dashboard-tab="{expected}"]').evaluate('(n) => n === document.activeElement')
            page.keyboard.press('Tab')
            # Header actions and the native GET filter precede the content in DOM order.
            for _ in range(4):
                if page.locator('[role=tabpanel]').evaluate('(n) => n === document.activeElement'):
                    break
                page.keyboard.press('Tab')
            assert page.locator('[role=tabpanel]').evaluate('(n) => n === document.activeElement')
            page.keyboard.press('Shift+Tab')
            for _ in range(4):
                if page.locator('[role=tab][aria-selected=true]').evaluate('(n) => n === document.activeElement'):
                    break
                page.keyboard.press('Shift+Tab')
            assert page.locator('[role=tab][aria-selected=true]').evaluate('(n) => n === document.activeElement')
            for index in (3, 1, 2, 0, 2, 3, 0):
                page.locator('[role=tab]').nth(index).click()
                page.wait_for_timeout(35)
            page.wait_for_timeout(750)
            verify(page, 'summary')
            page.locator('[role=tab]').nth(2).click()
            page.keyboard.press('Escape')
            verify(page, 'agenda')
            for width in (375, 1024, 1920, 720, 1440):
                page.set_viewport_size({'width': width, 'height': 1000})
                page.wait_for_timeout(150)
                verify(page, 'agenda')
            # A long translated label changes the actual measured tab width.
            page.locator('[role=tab]').first.locator('span').evaluate('(n) => n.textContent = "Resumen de mi actividad"')
            page.wait_for_timeout(150)
            choose(page, 'summary')
            capsule(page, 3, 'variable-label-width')
            cpu = context.new_cdp_session(page)
            cpu.send('Emulation.setCPUThrottlingRate', {'rate': 4})
            choose(page, 'summary')
            capsule(page, 3, 'cpu4-right')
            capsule(page, 0, 'cpu4-left')
            cpu.send('Emulation.setCPUThrottlingRate', {'rate': 1})
            result['checks']['synthetic_cpu_throttle_4'] = True
            result['checks']['keyboard_rapid_reversal_resize_reflow_720'] = True
            context.close()

            for width in (375, 1440):
                context = browser.new_context(viewport={'width': width, 'height': 1000}, java_script_enabled=False)
                page = context.new_page()
                for path in (client, provider):
                    goto(page, path)
                    menu = page.locator('[data-account-navigation]')
                    assert not menu.evaluate('(n) => n.open')
                    menu.locator(':scope > summary').click()
                    assert page.locator('.sidebar').is_visible()
                    menu.locator(':scope > summary').click()
                    assert page.locator('a[data-dashboard-tab]').count() in (3, 4)
                    assert page.locator('[data-dashboard-module][hidden]').count() == 0
                    page.locator('a[data-dashboard-tab]').last.click()
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+1')
                    if path == provider:
                        page.locator('.dashboard-data > summary').click()
                        assert page.locator('.dashboard-data table').is_visible()
                        page.select_option('#dashboard-period', '7')
                        page.get_by_role('button', name='Aplicar', exact=True).click()
                        page.wait_for_url('**?period=7')
                        assert page.locator('.dashboard-data tbody tr').count() == 7
                context.close()
            result['checks']['no_js_anchors_table_native_filter'] = True

            for width in (375, 1440):
                for role, path, views in [('client', client, ['requests', 'reservations', 'summary']), ('provider', provider, ['requests', 'agenda', 'activity', 'summary'])]:
                    options = {'viewport': {'width': width, 'height': 950}}
                    if args.record:
                        options.update(record_video_dir=str(args.output / 'video-temp'), record_video_size={'width': width, 'height': 950})
                    context = browser.new_context(**options)
                    page = context.new_page()
                    observe(page)
                    goto(page, path)
                    for key in views:
                        choose(page, key)
                    if role == 'provider':
                        for days in ('7', '30', '90'):
                            before = page.evaluate('performance.timeOrigin')
                            page.select_option('#dashboard-period', days)
                            page.get_by_role('button', name='Aplicar', exact=True).click()
                            page.wait_for_url('**?period=' + days)
                            page.wait_for_timeout(650)
                            assert page.evaluate('performance.timeOrigin') != before
                            choose(page, 'activity')
                            chart(page, int(days))
                        goto(page, '/preview/provider-empty')
                        choose(page, 'agenda')
                        goto(page, '/preview/provider-setup')
                    else:
                        goto(page, '/preview/client-empty')
                        choose(page, 'reservations')
                    video = page.video
                    context.close()
                    if video:
                        video.save_as(str(args.output / f'{role}-{width}.webm'))
                        video.delete()

            options = {'viewport': {'width': 1440, 'height': 950}, 'reduced_motion': 'reduce'}
            if args.record:
                options.update(record_video_dir=str(args.output / 'video-temp'), record_video_size={'width': 1440, 'height': 950})
            context = browser.new_context(**options)
            page = context.new_page()
            observe(page)
            context.new_cdp_session(page).send('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-reduced-motion', 'value': 'reduce'}, {'name': 'prefers-reduced-transparency', 'value': 'reduce'}]})
            for role, path in [('client', client), ('provider', provider)]:
                goto(page, path)
                page.locator('[role=tab]').last.click()
                verify(page, 'requests')
                assert page.evaluate('document.getAnimations().filter(a => a.playState === "running").length') == 0
                assert page.locator('.account-card').first.evaluate('(n) => getComputedStyle(n).backgroundImage') == 'none'
                page.locator('.tk-dashboard .account-button').first.hover()
                assert page.locator('.tk-dashboard .account-button').first.evaluate('(n) => getComputedStyle(n).transform') == 'none'
                page.mouse.move(0, 0)
                shot(page, role + '-reduced-1440.png')
            video = page.video
            context.close()
            if video:
                video.save_as(str(args.output / 'reduced-motion.webm'))
                video.delete()
            result['checks']['reduced_motion_transparency'] = True

            # One intentional failed request verifies that animation is optional.
            context = browser.new_context(viewport={'width': 375, 'height': 950})
            page = context.new_page()
            page.on('pageerror', lambda error: result['page_errors'].append(str(error)))
            await_pattern = '**/build/assets/index-*.js'
            page.route(await_pattern, lambda route: route.abort())
            goto(page, client)
            assert page.locator('[data-dashboard]').get_attribute('data-dashboard-motion') == 'fallback'
            choose(page, 'reservations')
            result['checks']['intentional_animation_chunk_failure'] = True
            context.close()

            context = browser.new_context(viewport={'width': 375, 'height': 950})
            page = context.new_page()
            observe(page)
            goto(page, '/login')
            page.locator('[data-auth-switch]').first.click()
            page.wait_for_url('**/registro')
            page.wait_for_timeout(1200)
            assert page.locator('[data-auth-content] form').count() == 1
            assert not page.locator('[data-auth-content]').evaluate('(n) => n.inert')
            result['checks']['w11_login_register_smoke'] = True
            context.close()
            browser.close()
        assert not result['page_errors'], result['page_errors']
        assert not result['failed_resources'], result['failed_resources']
        assert not any(item['violations'] for item in result['axe']), 'Inspect axe results'
        print(json.dumps({'cases': len(result['matrix']), 'axe_runs': len(result['axe']), 'capsule_runs': len(result['capsule']), 'checks': result['checks']}))
    finally:
        (args.output / 'browser-results.json').write_text(json.dumps(result, indent=2, ensure_ascii=False) + '\n')


if __name__ == '__main__':
    main()
