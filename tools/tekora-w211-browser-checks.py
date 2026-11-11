"""Sidebar acceptance on synthetic static exports, never on a live application."""
import argparse
import json
import shutil
import statistics
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8017')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--axe', type=Path, required=True)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    parser.add_argument('--record', action='store_true')
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname == '127.0.0.1' and not origin.path
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'matrix': [], 'axe': [], 'checks': {}, 'page_errors': [], 'console_errors': [], 'failed_resources': []}
    provider = '/cuenta/prestador/dashboard'
    client = '/cuenta/cliente'
    scroll = '[data-sidebar-scroll]'
    summary = '[data-account-navigation] > summary'
    init = '''window.sidebarCLS=0;
        new PerformanceObserver(list=>{for(const e of list.getEntries()) if(!e.hadRecentInput) window.sidebarCLS+=e.value}).observe({type:'layout-shift', buffered:true});'''

    def observe(page):
        page.add_init_script(init)
        page.on('pageerror', lambda e: result['page_errors'].append(str(e)))
        page.on('console', lambda m: result['console_errors'].append(m.text) if m.type == 'error' else None)
        page.on('requestfailed', lambda r: result['failed_resources'].append(r.url))
        page.on('response', lambda r: result['failed_resources'].append(f'{r.status} {r.url}') if r.status >= 400 else None)

    def goto(page, path):
        response = page.goto(args.base_url + path, wait_until='networkidle')
        assert response.status == 200 and response.header_value('x-tekora-preview') == 'synthetic-static-memory-export'
        page.evaluate('document.fonts.ready')
        page.wait_for_timeout(220)

    def open_menu(page):
        if not page.locator('[data-account-navigation]').evaluate('e=>e.open'):
            page.locator(summary).click()
        page.wait_for_timeout(80)

    def shot(page, name):
        page.screenshot(path=str(args.output / name), full_page=False)

    def state(page):
        return page.locator(scroll).evaluate('''e=>({top:e.scrollTop,max:e.scrollHeight-e.clientHeight,
            before:e.parentElement.dataset.scrollBefore,after:e.parentElement.dataset.scrollAfter})''')

    def position(page, fraction):
        page.locator(scroll).evaluate('(e,f)=>e.scrollTop=(e.scrollHeight-e.clientHeight)*f', fraction)
        page.wait_for_timeout(100)

    def focus_clear(page):
        return page.evaluate('''()=>{const e=document.activeElement,s=document.querySelector('[data-sidebar-scroll]');
            const a=e.getBoundingClientRect(),b=s.getBoundingClientRect();
            return s.contains(e)&&a.top>=b.top+18&&a.bottom<=b.bottom-18&&a.left>=b.left+4&&a.right<=b.right-4;}''')

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=args.executable, args=['--disable-dev-shm-usage'])
            gpu = browser.new_browser_cdp_session().send('SystemInfo.getInfo')['gpu']
            result['environment'] = {'browser': browser.version, 'headless': True, 'renderer': gpu.get('auxAttributes', {}).get('glRenderer')}
            paths = [('provider-dual', provider), ('client-dual', client), ('client-only', '/preview/client-empty'),
                     ('provider-only', '/preview/provider-empty'), ('admin', '/preview/admin'), ('dual-admin', '/preview/dual-admin'),
                     ('large-counts', '/preview/large-counts'), ('unavailable', '/preview/unavailable-count')]
            for width in (320, 375, 768, 1024, 1100, 1280, 1440, 1920):
                context = browser.new_context(viewport={'width': width, 'height': 900}, has_touch=width < 1100)
                page = context.new_page()
                observe(page)
                for role, path in paths:
                    goto(page, path)
                    initial_cls = page.evaluate('window.sidebarCLS')
                    open_menu(page)
                    metrics = page.locator(scroll).evaluate('''e=>{
                        const s=getComputedStyle(e), rect=e.getBoundingClientRect(), panel=e.parentElement.getBoundingClientRect();
                        const labels=[...e.querySelectorAll('.tk-nav-label')].map(n=>{
                            const r=document.createRange();r.selectNodeContents(n);
                            return {text:n.textContent.trim(),lines:r.getClientRects().length,wrap:getComputedStyle(n).overflowWrap};
                        });
                        const badges=[...e.querySelectorAll('.tk-nav-badge')].map(n=>{const r=n.getBoundingClientRect(),a=n.parentElement.getBoundingClientRect(),l=n.previousElementSibling.getBoundingClientRect();return {value:n.textContent.trim(),nowrap:getComputedStyle(n).whiteSpace,inside:r.right<=a.right-5&&r.left>=l.right+5};});
                        return {sidebar_width:panel.width,panel_inside_viewport:panel.left>=0&&panel.right<=innerWidth,
                            overflow:s.overflowY,scrollbar:s.scrollbarWidth,
                            scrollbar_webkit:getComputedStyle(e,'::-webkit-scrollbar').display,no_horizontal:e.scrollWidth<=e.clientWidth+1,
                            global_no_horizontal:document.documentElement.scrollWidth<=innerWidth+1,
                            min_target:Math.min(...[...e.querySelectorAll('.menu-item')].map(n=>n.getBoundingClientRect().height)),labels,badges};
                    }''')
                    assert metrics['no_horizontal'] and metrics['global_no_horizontal'] and metrics['panel_inside_viewport'], (width, role, metrics)
                    assert metrics['overflow'] == 'auto' and metrics['scrollbar'] == 'none' and metrics['scrollbar_webkit'] == 'none'
                    assert metrics['min_target'] >= 43.9
                    assert all(label['wrap'] == 'normal' for label in metrics['labels'])
                    assert all(badge['inside'] and badge['nowrap'] == 'nowrap' for badge in metrics['badges'])
                    if width >= 1100:
                        assert metrics['sidebar_width'] == (240 if width < 1280 else 256 if width < 1600 else 264)
                        assert all(label['lines'] == 1 for label in metrics['labels']), (width, role, metrics['labels'])
                    if role in ('provider-dual', 'client-dual'):
                        assert [b['value'] for b in metrics['badges']] == ['29', '5']
                    if role == 'large-counts':
                        assert [b['value'] for b in metrics['badges']] == ['125', '125']
                    if role == 'unavailable':
                        assert page.locator('.tk-nav-status').count() == 1
                    position(page, 0)
                    first = state(page)
                    if first['max'] > 2:
                        assert first['before'] == 'false' and first['after'] == 'true'
                        position(page, .5)
                        assert state(page)['before'] == 'true' and state(page)['after'] == 'true'
                        position(page, 1)
                        assert state(page)['before'] == 'true' and state(page)['after'] == 'false'
                    page.locator('.tk-sidebar-navigation .logout-btn').focus()
                    assert focus_clear(page), (width, role, 'logout focus hidden')
                    position(page, 0)
                    result['matrix'].append({'width': width, 'role': role, 'initial_cls': initial_cls, **metrics, 'logout_reachable': True})
                    names = {(1440,'provider-dual'):'provider-1440.png',(1280,'provider-dual'):'provider-1280.png',
                             (1440,'client-dual'):'client-1440.png',(375,'provider-dual'):'mobile-375.png',
                             (320,'provider-dual'):'mobile-320.png',(1440,'dual-admin'):'dual-admin-1440.png',
                             (1440,'large-counts'):'large-counts-1440.png',(375,'unavailable'):'unavailable-375.png'}
                    if (width, role) in names:
                        # Blur the logout after checking it; this shot illustrates the start of the menu.
                        page.locator(scroll).evaluate('e=>document.activeElement.blur()')
                        shot(page, names[(width, role)])
                    if width in (375,1440) and role in ('provider-dual','client-only','admin','large-counts','unavailable'):
                        page.evaluate(args.axe.read_text())
                        audit = page.evaluate("async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})")
                        result['axe'].append({'width':width,'role':role,'violations':audit['violations'],'incomplete':[{'id':i['id'],'nodes':len(i['nodes'])} for i in audit['incomplete']]})
                context.close()
                (args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')

            context = browser.new_context(viewport={'width':1440,'height':1000})
            page=context.new_page();observe(page);goto(page,provider)
            for name, fraction in [('scroll-start',0),('scroll-middle',.5),('scroll-end',1)]:
                position(page,fraction);shot(page,name+'.png')
            position(page,0)
            page.locator(scroll).hover();page.mouse.wheel(0,420);page.wait_for_timeout(180)
            assert state(page)['top']>0
            page.mouse.wheel(0,5000);page.wait_for_timeout(180)
            assert state(page)['after']=='false'
            result['checks']['wheel_and_scroll_edges']=True
            # Traverse every visible native control, including the final logout, without a focus trap.
            position(page,0);page.locator(scroll).focus()
            controls=page.locator('.tk-sidebar-scroll a:visible,.tk-sidebar-scroll button:visible,.tk-sidebar-scroll summary:visible')
            for control in controls.all():
                page.keyboard.press('Tab')
                assert control.evaluate('e=>e===document.activeElement')
                assert focus_clear(page), control.text_content()
            assert page.locator('.logout-btn').evaluate('e=>e===document.activeElement')
            shot(page,'focus-logout.png');page.keyboard.press('Shift+Tab')
            profile=page.locator('.tk-sidebar-navigation a[href$="/perfil"]')
            assert profile.evaluate('e=>e===document.activeElement')
            assert page.locator('.account-legacy').count()==0
            page.keyboard.press('Tab');assert page.locator('.logout-btn').evaluate('e=>e===document.activeElement')
            page.keyboard.press('Tab');assert not page.locator(scroll).evaluate('e=>e.contains(document.activeElement)')
            result['checks']['all_native_links_tab_shift_tab_and_exit']=True
            position(page,0)
            notifications=page.locator('.tk-sidebar-navigation a').filter(has=page.locator('.tk-nav-label',has_text='Notificaciones'))
            notifications.hover();shot(page,'hover-notifications.png')
            notifications.focus();shot(page,'focus-notifications.png')
            (args.output/'navigation-accessibility.yml').write_text(page.locator('.tk-sidebar-navigation').aria_snapshot())
            # Sampling rAF during wheel input is evidence for this software renderer only.
            page.evaluate('''window.sidebarFrames=[];window.sampleUntil=performance.now()+1000;
                function sample(t){window.sidebarFrames.push(t);if(t<window.sampleUntil)requestAnimationFrame(sample)}requestAnimationFrame(sample);''')
            page.locator(scroll).hover()
            for _ in range(5):page.mouse.wheel(0,80);page.wait_for_timeout(100)
            page.wait_for_timeout(550)
            frames=page.evaluate('window.sidebarFrames');intervals=[b-a for a,b in zip(frames,frames[1:])]
            result['scroll_frame_intervals_ms']={'samples':len(intervals),'median':statistics.median(intervals),'p95':sorted(intervals)[int(len(intervals)*.95)]}
            context.close()

            context=browser.new_context(viewport={'width':375,'height':844},has_touch=True,is_mobile=True)
            page=context.new_page();observe(page);goto(page,provider)
            assert not page.locator('[data-account-navigation]').evaluate('e=>e.open')
            page.locator(summary).focus();page.keyboard.press('Space');assert page.locator('[data-account-navigation]').evaluate('e=>e.open')
            page.locator('.logout-btn').focus();page.keyboard.press('Escape')
            assert not page.locator('[data-account-navigation]').evaluate('e=>e.open') and page.locator(summary).evaluate('e=>e===document.activeElement')
            open_menu(page);position(page,0)
            # Send native touch input through Chromium, without replacing scrolling in app JS.
            bounds=page.locator(scroll).bounding_box();session=context.new_cdp_session(page)
            x=bounds['x']+bounds['width']/2;y=bounds['y']+bounds['height']*.8
            session.send('Input.dispatchTouchEvent',{'type':'touchStart','touchPoints':[{'x':x,'y':y}]})
            for offset in (40,80,120,160,200):
                session.send('Input.dispatchTouchEvent',{'type':'touchMove','touchPoints':[{'x':x,'y':y-offset}]});page.wait_for_timeout(30)
            session.send('Input.dispatchTouchEvent',{'type':'touchEnd','touchPoints':[]});page.wait_for_timeout(250)
            assert state(page)['top']>0
            position(page,0);page.locator('.tk-sidebar-navigation a[href$="/cuenta"]').click();page.wait_for_load_state('networkidle')
            assert page.url==args.base_url+'/cuenta' and not page.locator('[data-account-navigation]').evaluate('e=>e.open')
            page.set_viewport_size({'width':1280,'height':900})
            page.wait_for_function('() => document.querySelector("[data-account-navigation]").open')
            page.set_viewport_size({'width':375,'height':844})
            page.wait_for_function('() => !document.querySelector("[data-account-navigation]").open')
            page.set_viewport_size({'width':844,'height':375});open_menu(page)
            assert page.locator('.sidebar').evaluate('''e=>{const r=e.getBoundingClientRect(),s=e.firstElementChild.getBoundingClientRect();return r.bottom<=innerHeight&&r.left>=0&&r.right<=innerWidth&&r.height>=s.height+1;}''')
            shot(page,'mobile-landscape.png')
            result['checks']['touch_escape_space_native_navigation_breakpoint']=True
            context.close()

            context=browser.new_context(viewport={'width':375,'height':844},java_script_enabled=False)
            page=context.new_page();goto(page,provider);page.locator(summary).click()
            assert page.locator(scroll).is_visible()
            page.locator(scroll).focus()
            for control in page.locator('.tk-sidebar-scroll a:visible,.tk-sidebar-scroll button:visible,.tk-sidebar-scroll summary:visible').all():
                page.keyboard.press('Tab');assert control.evaluate('e=>e===document.activeElement')
            assert page.locator('.logout-btn').evaluate('e=>e===document.activeElement')
            shot(page,'no-js-mobile.png');result['checks']['native_no_js_menu_and_last_control']=True;context.close()

            context=browser.new_context(viewport={'width':1440,'height':1000},reduced_motion='reduce')
            page=context.new_page();observe(page);goto(page,provider)
            assert page.locator('.tk-sidebar-navigation .menu-item').first.evaluate('e=>getComputedStyle(e).transitionDuration')=='0s'
            session=context.new_cdp_session(page);session.send('Emulation.setEmulatedMedia',{'features':[{'name':'prefers-reduced-transparency','value':'reduce'}]})
            assert page.evaluate('matchMedia("(prefers-reduced-transparency: reduce)").matches')
            assert page.locator('.sidebar').evaluate('e=>getComputedStyle(e).backgroundImage')=='none'
            shot(page,'reduced-preferences.png');result['checks']['reduced_motion_and_transparency']=True;context.close()

            if args.record:
                for width,height,name in [(1440,1000,'desktop'),(375,844,'mobile')]:
                    context=browser.new_context(viewport={'width':width,'height':height},record_video_dir=str(args.output/'video-temp'),record_video_size={'width':width,'height':height})
                    page=context.new_page();observe(page);goto(page,provider);open_menu(page);position(page,0);page.wait_for_timeout(450)
                    page.locator(scroll).hover()
                    for _ in range(4):page.mouse.wheel(0,220);page.wait_for_timeout(280)
                    page.locator('.logout-btn').focus();page.wait_for_timeout(500)
                    position(page,0)
                    for text in ['Explorar servicios','Notificaciones','Mensajes']:
                        page.locator('.tk-sidebar-navigation a').filter(has=page.locator('.tk-nav-label',has_text=text)).hover();page.wait_for_timeout(250)
                    if width<1100:
                        page.keyboard.press('Escape');page.wait_for_timeout(300);open_menu(page)
                    page.locator('.tk-sidebar-navigation a[href$="/cuenta"]').click();page.wait_for_load_state('networkidle');open_menu(page);page.wait_for_timeout(400)
                    video=page.video;context.close();video.save_as(str(args.output/f'sidebar-{name}.webm'))
            browser.close()
        assert not result['page_errors'] and not result['console_errors'] and not result['failed_resources']
        assert all(not audit['violations'] for audit in result['axe'])
        assert all(row['initial_cls']==0 for row in result['matrix'])
        print(json.dumps({'matrix':len(result['matrix']),'axe':len(result['axe']),'checks':result['checks'],'errors':0}))
    finally:
        (args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')


if __name__=='__main__':
    main()
