"""Run against the disposable memory export, never a live/persistent Laravel installation.

Requires Python Playwright outside the application's dependency manifests.
Example: python tools/tekora-w11-browser-checks.py --base-url http://127.0.0.1:8015 --output <new-directory> --record
"""
import argparse
import base64
import json
import shutil
import time
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import Error as PlaywrightError, sync_playwright


def wait_for_phase(page, expected):
    # Locator polling can skip a 200 ms phase; CDP evaluations preserve the strict CSP.
    deadline = time.monotonic() + 4
    while time.monotonic() < deadline:
        phase = page.evaluate('() => document.querySelector("[data-auth-panel]")?.dataset.authPhase')
        if phase == expected:
            return
        page.wait_for_timeout(10)
    raise AssertionError('Transient phase not observed: ' + expected)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8015')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    parser.add_argument('--record', action='store_true')
    parser.add_argument('--headed', action='store_true')
    parser.add_argument('--software', action='store_true')
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname in ('127.0.0.1', 'localhost') and not origin.path
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'viewports': [], 'transitions': [], 'checks': {}, 'page_errors': [], 'failed_resources': []}

    def observe(page):
        page.on('pageerror', lambda error: result['page_errors'].append(str(error)))
        page.on('requestfailed', lambda request: result['failed_resources'].append({'url': request.url, 'type': request.resource_type, 'failure': request.failure}))

    def goto(page, path):
        response = page.goto(args.base_url + path, wait_until='networkidle')
        assert response.status == 200 and response.header_value('x-tekora-preview') == 'synthetic-static-memory-export', 'Use the static memory-export router, never a real application.'

    def ready(page, expected):
        page.wait_for_url('**' + expected)
        page.locator('[data-auth-panel]:not(.is-flipping)').wait_for(state='attached')
        assert page.locator('[data-auth-content] form').count() == 1
        assert not page.locator('[data-auth-content]').evaluate('(node) => node.inert')
        assert page.locator('h1').is_visible()

    def snapshot(page, filename, full_page=True):
        page.screenshot(path=str(args.output / filename), full_page=full_page, timeout=10000)

    with sync_playwright() as playwright:
        launch = {'headless': not args.headed, 'args': ['--disable-dev-shm-usage']}
        if args.executable:
            launch['executable_path'] = args.executable
        if args.software:
            launch['args'].append('--disable-gpu')
        browser = playwright.chromium.launch(**launch)
        gpu = browser.new_browser_cdp_session().send('SystemInfo.getInfo')['gpu']
        result['graphics'] = {'devices': gpu.get('devices'), 'renderer': gpu.get('auxAttributes', {}).get('glRenderer'), 'feature_status': gpu.get('featureStatus'), 'software_flag': args.software, 'headed': args.headed}

        # The shared layout reaches catalog, profiles, dashboard and all central inboxes.
        paths = ['/', '/login', '/registro', '/servicios', '/prestadores', '/cuenta', '/cuenta/prestador/dashboard', '/cuenta/solicitudes', '/cuenta/mensajes', '/cuenta/reservas', '/empty', '/errors']
        for width in (320, 375, 768, 1440):
            context = browser.new_context(viewport={'width': width, 'height': 950})
            page = context.new_page()
            observe(page)
            for path in paths:
                goto(page, path)
                page.wait_for_timeout(300)
                assert page.locator('h1').is_visible(), (width, path)
                assert page.locator('[data-tekora-ambient]').count() == 1
                assert page.evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'), (width, path)
                assert page.locator('[data-tekora-ambient]').evaluate('(node) => getComputedStyle(node).pointerEvents') == 'none'
                result['viewports'].append({'width': width, 'path': path, 'no_overflow': True})
                if width in (375, 1440) and path in ('/', '/servicios', '/cuenta/prestador/dashboard'):
                    snapshot(page, f'{"home" if path == "/" else "catalog" if path == "/servicios" else "dashboard"}-{width}.png')
            context.close()

        # Complete physical flips with no cross-document View Transitions API.
        for width, start, destination, label in ((1440, '/login', '/registro', 'login-register'), (1440, '/registro', '/login', 'register-login'), (375, '/login', '/registro', 'mobile-login-register'), (375, '/registro', '/login', 'mobile-register-login')):
            options = {'viewport': {'width': width, 'height': 950}}
            if width == 375:
                options.update(has_touch=True, is_mobile=True)
            if args.record:
                options.update(record_video_dir=str(args.output / 'recording-temp'), record_video_size={'width': width, 'height': 950})
            context = browser.new_context(**options)
            context.add_init_script("Object.defineProperty(document, 'startViewTransition', {value: undefined});")
            context.add_init_script('''(() => {
                window.w11HeightCleanup = [];
                const remove = CSSStyleDeclaration.prototype.removeProperty;
                CSSStyleDeclaration.prototype.removeProperty = function (property) {
                    const card = document.querySelector('[data-auth-panel]');
                    if (property === 'height' && card && this === card.style && this.height) {
                        const before = card.getBoundingClientRect().height;
                        const result = remove.call(this, property);
                        window.w11HeightCleanup.push({before, after: card.getBoundingClientRect().height});
                        return result;
                    }
                    return remove.call(this, property);
                };
            })();''')
            page = context.new_page()
            observe(page)
            goto(page, start)
            baseline = page.evaluate('performance.timeOrigin')
            page.evaluate('''() => {
                window.w11Frames = [];
                let seen = false;
                const begin = performance.now();
                const sample = () => {
                    const card = document.querySelector('[data-auth-panel]');
                    const phase = card?.dataset.authPhase;
                    if (phase) seen = true;
                    if (seen) window.w11Frames.push({time: performance.now(), phase: phase || 'ready',
                        transform: getComputedStyle(card).transform, height: card.getBoundingClientRect().height,
                        inert: card.querySelector('[data-auth-content]').inert,
                        central_forms: document.querySelectorAll('[data-auth-content] form').length,
                        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1});
                    if ((phase || !seen) && performance.now() - begin < 5000) requestAnimationFrame(sample);
                };
                requestAnimationFrame(sample);
            }''')
            page.locator('[data-auth-switch]').click()
            ready(page, destination)
            page.wait_for_timeout(100)
            assert page.evaluate('performance.timeOrigin') == baseline, 'Unexpected document navigation during the complete flip.'
            frames = page.evaluate('window.w11Frames')
            phases = list(dict.fromkeys(frame['phase'] for frame in frames))
            assert phases == ['exit', 'turn', 'mark', 'enter', 'ready'], phases
            assert all(frame['central_forms'] == 1 and not frame['overflow'] for frame in frames)
            assert all(frame['inert'] for frame in frames if frame['phase'] != 'ready')
            assert any(frame['transform'].startswith('matrix3d') for frame in frames if frame['phase'] == 'turn')
            cleanup = page.evaluate('window.w11HeightCleanup')
            assert cleanup and all(abs(measurement['after'] - measurement['before']) <= 1 for measurement in cleanup), cleanup
            snapshot(page, label + '-arrival.png')
            first_input = page.locator('[data-auth-content] input:not([type=hidden]):not([type=radio])').first
            first_input.scroll_into_view_if_needed()
            assert first_input.evaluate('node => {const r=node.getBoundingClientRect();return document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)===node;}')
            first_input.focus()
            assert first_input.evaluate('node => document.activeElement === node')
            result['transitions'].append({'label': label, 'width': width, 'phases': phases, 'observed_ms': round(frames[-1]['time'] - frames[0]['time']), 'frame_count': len(frames), 'same_document_without_view_transitions': True, 'one_central_form': True, 'inert_while_hidden': True, 'input_receives_pointer_and_focus': True, 'height_cleanup': cleanup, 'frames': frames})
            video = page.video
            context.close()
            if video:
                shutil.copyfile(video.path(), args.output / (label + '.webm'))
            print('Full transition passed: ' + label, flush=True)
            # Still poses use separate runs; screenshots never interrupt the complete recorded flip.
            for phase, delay in (('turn', 90), ('mark', 70)):
                still = browser.new_context(viewport={'width': width, 'height': 950})
                page = still.new_page()
                goto(page, start)
                page.locator('[data-auth-switch]').dispatch_event('click', {'button': 0})
                wait_for_phase(page, phase)
                page.wait_for_timeout(delay)
                capture = still.new_cdp_session(page).send('Page.captureScreenshot', {'format': 'jpeg', 'quality': 92, 'captureBeyondViewport': False})
                (args.output / (label + '-' + phase + '.jpg')).write_bytes(base64.b64decode(capture['data']))
                still.close()

        context = browser.new_context(viewport={'width': 375, 'height': 950})
        page = context.new_page()
        observe(page)
        goto(page, '/login')
        # Keyboard activation, focus at arrival, duplicate activation and history navigation.
        menu = page.locator('[data-navigation] summary')
        menu.focus()
        page.keyboard.press('Enter')
        page.locator('[data-navigation] summary[aria-expanded="true"]').wait_for(state='attached')
        assert menu.get_attribute('aria-expanded') == 'true'
        page.keyboard.press('Escape')
        page.locator('[data-navigation] summary[aria-expanded="false"]').wait_for(state='attached')
        assert menu.get_attribute('aria-expanded') == 'false'
        assert menu.evaluate('node => document.activeElement === node')
        page.locator('[data-auth-switch]').focus()
        page.keyboard.press('Enter')
        ready(page, '/registro')
        assert page.locator('h1').evaluate('node => document.activeElement === node')
        old_length = page.evaluate('history.length')
        page.evaluate('document.querySelector("[data-auth-switch]").click(); document.querySelector("[data-auth-switch]").click();')
        ready(page, '/login')
        assert page.evaluate('history.length') == old_length + 1
        page.go_back()
        ready(page, '/registro')
        page.go_forward()
        ready(page, '/login')
        assert page.locator('#email').get_attribute('autocomplete') == 'email'
        assert page.locator('#password').get_attribute('autocomplete') == 'current-password'
        result['checks']['keyboard_double_click_history_autocomplete_attributes'] = True
        # Native POST from a newly arrived form. The static router intentionally rejects it.
        with page.expect_request(lambda request: request.method == 'POST') as native:
            page.locator('#email').fill('native-w11@example.test')
            page.locator('#password').fill('synthetic-test-value')
            page.get_by_role('button', name='Entrar a mi cuenta', exact=True).click()
        request = native.value
        assert urlsplit(request.url).path == '/login' and request.resource_type == 'document' and '_token=' in request.post_data
        result['checks']['native_post_with_csrf_field'] = True
        context.close()

        for reduced, javascript in ((True, True), (False, False)):
            context = browser.new_context(viewport={'width': 375, 'height': 950}, reduced_motion='reduce' if reduced else 'no-preference', java_script_enabled=javascript)
            page = context.new_page()
            goto(page, '/login')
            page.locator('[data-auth-switch]').click()
            ready(page, '/registro')
            page.locator('[data-auth-switch]').click()
            ready(page, '/login')
            if reduced:
                assert page.locator('.tekora-ambient-blue').evaluate('node => getComputedStyle(node).animationName') == 'none'
            result['checks']['reduced_motion_native_links' if reduced else 'no_javascript_native_links'] = True
            context.close()

        context = browser.new_context()
        page = context.new_page()
        goto(page, '/login')
        time_origin = page.evaluate('performance.timeOrigin')
        page.locator('[data-auth-switch]').dispatch_event('click', {'button': 0})
        wait_for_phase(page, 'turn')
        page.emulate_media(reduced_motion='reduce')
        ready(page, '/registro')
        assert page.evaluate('performance.timeOrigin') != time_origin
        result['checks']['motion_preference_change_during_flip'] = True
        context.close()

        context = browser.new_context()
        page = context.new_page()
        goto(page, '/errors')
        assert page.get_by_role('alert').first.is_visible()
        time_origin = page.evaluate('performance.timeOrigin')
        page.locator('[data-auth-switch]').click()
        ready(page, '/registro')
        assert page.evaluate('performance.timeOrigin') != time_origin
        result['checks']['current_validation_error_uses_normal_navigation'] = True
        # An error arriving in the GET is immediately visible, without a flip.
        error_html = context.request.get(args.base_url + '/errors/registro').text()
        page.goto(args.base_url + '/login', wait_until='networkidle')
        page.route('**/registro', lambda route: route.fulfill(status=200, content_type='text/html', body=error_html) if route.request.resource_type == 'fetch' else route.continue_())
        page.locator('[data-auth-switch]').click()
        ready(page, '/registro')
        assert page.get_by_role('alert').first.is_visible()
        assert page.locator('[data-auth-panel]').get_attribute('data-auth-phase') is None
        result['checks']['incoming_validation_error_immediate'] = True
        context.close()

        context = browser.new_context()
        page = context.new_page()
        goto(page, '/login')
        page.route('**/registro', lambda route: route.abort('failed') if route.request.resource_type == 'fetch' else route.continue_())
        page.locator('[data-auth-switch]').click()
        ready(page, '/registro')
        result['checks']['failed_get_falls_back_to_document_navigation'] = True
        context.close()

        context = browser.new_context()
        page = context.new_page()
        goto(page, '/login')
        # A native form submit wins over a still-pending enhancement GET.
        stalled = []
        def hold_get(route):
            if route.request.resource_type == 'fetch':
                stalled.append(route)
            else:
                route.continue_()
        page.route('**/registro', hold_get)
        page.locator('[data-auth-switch]').dispatch_event('click', {'button': 0})
        page.locator('#email').fill('pending-w11@example.test')
        page.locator('#password').fill('synthetic-test-value')
        with page.expect_request(lambda request: request.method == 'POST') as native:
            page.get_by_role('button', name='Entrar a mi cuenta', exact=True).click()
        assert urlsplit(native.value.url).path == '/login' and native.value.resource_type == 'document'
        page.wait_for_load_state('domcontentloaded')
        page.wait_for_timeout(300)
        assert urlsplit(page.url).path == '/login'
        result['checks']['native_post_cancels_pending_get'] = True
        for route in stalled:
            try:
                route.abort('aborted')
            except PlaywrightError:
                pass  # The browser may already have cancelled this intentionally stalled request.
        page.unroute_all(behavior='ignoreErrors')
        context.close()

        context = browser.new_context()
        # Simulate an unavailable animation engine; the watchdog must restore normal navigation.
        context.add_init_script("if(location.pathname === '/login') window.requestAnimationFrame = () => 0;")
        page = context.new_page()
        goto(page, '/login')
        page.locator('[data-auth-switch]').dispatch_event('click', {'button': 0})
        ready(page, '/registro')
        result['checks']['animation_failure_watchdog'] = True
        context.close()

        context = browser.new_context()
        page = context.new_page()
        goto(page, '/login')
        with context.expect_page() as tab:
            page.locator('[data-auth-switch]').click(modifiers=['Control'])
        popup = tab.value
        popup.wait_for_load_state('networkidle')
        assert urlsplit(page.url).path == '/login' and urlsplit(popup.url).path == '/registro'
        assert page.locator('[data-auth-panel]').get_attribute('data-auth-phase') is None
        result['checks']['modified_click_native_new_tab'] = True
        context.close()

        context = browser.new_context(viewport={'width': 1440, 'height': 950})
        page = context.new_page()
        observe(page)
        goto(page, '/servicios')
        before = page.locator('.tekora-ambient-blue').evaluate('node => getComputedStyle(node).transform')
        page.wait_for_timeout(1400)
        after = page.locator('.tekora-ambient-blue').evaluate('node => getComputedStyle(node).transform')
        assert before != after
        page.context.new_cdp_session(page).send('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-reduced-transparency', 'value': 'reduce'}]})
        if page.evaluate('matchMedia("(prefers-reduced-transparency: reduce)").matches'):
            assert page.locator('[data-tekora-ambient]').evaluate('node => getComputedStyle(node).display') == 'none'
            result['checks']['reduced_transparency'] = True
        else:
            result['checks']['reduced_transparency'] = 'emulation unavailable'
        page.set_viewport_size({'width': 720, 'height': 475})
        goto(page, '/registro')
        assert page.evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1')
        result['checks']['zoom_200_equivalent_720_css_pixels'] = True
        result['checks']['ambient_moves_and_never_intercepts_pointer'] = True
        context.close()

        if args.record:
            context = browser.new_context(viewport={'width': 1440, 'height': 950}, record_video_dir=str(args.output / 'recording-temp'), record_video_size={'width': 1440, 'height': 950})
            page = context.new_page()
            for path in ('/', '/servicios', '/cuenta/prestador/dashboard'):
                goto(page, path)
                page.wait_for_timeout(5000)
            video = page.video
            context.close()
            shutil.copyfile(video.path(), args.output / 'ambient-home-catalog-dashboard.webm')
        browser.close()

    assert not result['page_errors'], result['page_errors']
    assert not result['failed_resources'], result['failed_resources']
    (args.output / 'browser-results.json').write_text(json.dumps(result, indent=2) + '\n')
    shutil.rmtree(args.output / 'recording-temp', ignore_errors=True)
    print(json.dumps({'viewport_checks': len(result['viewports']), 'transitions': len(result['transitions']), 'checks': result['checks'], 'page_errors': result['page_errors'], 'failed_resources': result['failed_resources']}))


if __name__ == '__main__':
    main()
