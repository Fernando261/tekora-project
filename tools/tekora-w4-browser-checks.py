"""W4 acceptance against synthetic memory-fixture GET exports, never a live backend."""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--base-url', default='http://127.0.0.1:8063')
    p.add_argument('--pages', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--axe', type=Path, required=True)
    p.add_argument('--executable', default=shutil.which('chromium'))
    p.add_argument('--record', action='store_true')
    p.add_argument('--quick', action='store_true')
    p.add_argument('--names', nargs='*')
    p.add_argument('--matrix-only', action='store_true')
    p.add_argument('--resume-flows', action='store_true', help='Reuse a completed matrix JSON, repeat all functional checks and videos.')
    args = p.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname == '127.0.0.1' and not origin.path
    pages = json.loads(args.pages.read_text());
    if args.names: pages = {name:pages[name] for name in args.names}
    args.output.mkdir(parents=True, exist_ok=args.resume_flows)
    result = {'matrix': [], 'axe': [], 'checks': {}, 'page_errors': [], 'console_errors': [], 'expected_http_errors': [], 'failed_resources': []}
    if args.resume_flows:
        result = json.loads((args.output/'browser-results.json').read_text())
        assert result['matrix'] and not any(a['violations'] for a in result['axe'])
    observer = """window.opsCLS=0;new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)opsCLS+=e.value}).observe({type:'layout-shift',buffered:true});"""

    def observe(page):
        page.add_init_script(observer)
        page.on('pageerror', lambda e: result['page_errors'].append(str(e)))
        page.on('requestfailed', lambda r: result['failed_resources'].append(r.url))
        page.on('response', lambda r: result['failed_resources'].append(f'{r.status} {r.url}') if r.status >= 400 and r.request.resource_type != 'document' else None)
        def console(message):
            if message.type != 'error': return
            if 'status of 4' in message.text or 'status of 5' in message.text:
                result['expected_http_errors'].append({'text':message.text,'location':message.location})
            else: result['console_errors'].append({'text': message.text, 'location': message.location})
        page.on('console', console)

    def goto(page, name):
        data = pages[name]
        response = page.goto(args.base_url + data['path'], wait_until='networkidle')
        assert response.status == data['status'] and response.header_value('x-tekora-preview') == 'synthetic-static-memory-export', (name, response.status)
        page.evaluate('document.fonts.ready'); page.wait_for_timeout(720)

    def verify(page, name):
        metrics = page.evaluate("""()=>{
          const controls=[...document.querySelectorAll('main input:not([type=hidden]),main select,main textarea,main button,main .account-button')].filter(n=>n.getClientRects().length);
          const ids=[...document.querySelectorAll('[id]')].map(n=>n.id);
          return {no_horizontal:document.documentElement.scrollWidth<=innerWidth+1,controls_inside:controls.every(n=>{const r=n.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1}),duplicate_ids:ids.length-new Set(ids).size,h1:document.querySelectorAll('main h1').length,inline_scripts:document.querySelectorAll('script:not([src])').length,cls:opsCLS};
        }""")
        assert page.title().endswith(' · Tekora') and metrics['no_horizontal'] and metrics['controls_inside'] and not metrics['duplicate_ids'] and metrics['h1'] == 1 and not metrics['inline_scripts'], (name, metrics)
        return metrics

    def shot(page, name, full=False):
        page.screenshot(path=str(args.output/name), full_page=full)

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path=args.executable, args=['--disable-dev-shm-usage'])
            gpu = browser.new_browser_cdp_session().send('SystemInfo.getInfo')['gpu']
            result['environment'] = {'browser': browser.version, 'headless': True, 'renderer': gpu.get('auxAttributes', {}).get('glRenderer')}
            widths = [] if args.resume_flows else ([320] if args.quick else [320,375,768,1024,1100,1280,1440,1920])
            for width in widths:
                context = browser.new_context(viewport={'width':width,'height':1000}, has_touch=width<1100)
                page = context.new_page(); observe(page)
                for name in pages:
                    goto(page,name); metrics=verify(page,name); result['matrix'].append({'width':width,'page':name,**metrics})
                    if width in (375,1440) or args.quick:
                        shot(page,f'{name}-{width}.png')
                    if width in (375,1440) or args.quick:
                        page.evaluate(args.axe.read_text())
                        audit=page.evaluate("async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})")
                        result['axe'].append({'width':width,'page':name,'violations':audit['violations'],'incomplete':[{'id':v['id'],'nodes':len(v['nodes'])} for v in audit['incomplete']]})
                    (args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
                context.close();print(f'Matrix width {width}: {len(pages)} pages',flush=True)
            if args.quick or args.matrix_only:
                assert not result['page_errors'] and not result['console_errors'] and not result['failed_resources']
                assert not any(a['violations'] for a in result['axe'])
                return
            context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();observe(page)
            for name in ['request-create','reservation-propose','services']:
                goto(page,name)
                geometry=page.locator('[data-ops-scene]').evaluate("""e=>({perspective:getComputedStyle(e).perspective,preserve:getComputedStyle(e.firstElementChild).transformStyle,planes:[...e.querySelectorAll('.tk-ops-plane')].map(n=>({matrix:getComputedStyle(n).transform,z:new DOMMatrix(getComputedStyle(n).transform).m43}))})""")
                assert geometry['perspective']=='900px' and geometry['preserve']=='preserve-3d' and len({p['z'] for p in geometry['planes']})==3
                result['checks'][name+'_3d']=geometry
            goto(page,'requests-sent');link=page.locator('main article a').first;before=link.bounding_box();card=page.locator('[data-ops-card]').first;box=card.bounding_box();page.mouse.move(box['x']+box['width']*.8,box['y']+box['height']*.3);page.wait_for_timeout(320)
            after=link.bounding_box();assert all(abs(before[k]-after[k])<.5 for k in before);assert card.evaluate("e=>e.style.getPropertyValue('--ops-card-x')")
            shot(page,'card-hover.png');result['checks']['hover_targets_fixed']=True
            goto(page,'request-create');page.locator('#message').focus();shot(page,'request-focus.png');assert page.locator('#message').evaluate('e=>getComputedStyle(e).outlineStyle')!='none'
            page.keyboard.press('Tab');assert page.evaluate('document.activeElement.type')=='submit'
            page.keyboard.press('Shift+Tab');assert page.locator('#message').evaluate('e=>e===document.activeElement');result['checks']['keyboard_focus_order']=True
            goto(page,'requests-sent');page.select_option('#status','pending');page.get_by_role('button',name='Filtrar',exact=True).click();page.wait_for_load_state('networkidle');assert 'status=pending' in page.url;assert page.locator('#status').input_value()=='pending'
            page.get_by_role('link',name='Limpiar filtros',exact=True).click();page.wait_for_load_state('networkidle');assert not urlsplit(page.url).query
            page.locator('.catalog-pagination a[rel=next]').click();page.wait_for_load_state('networkidle');assert 'page=2' in page.url;result['checks']['native_filters_clear_pagination']=True
            goto(page,'request-detail');page.get_by_role('link',name='Mensajes anteriores',exact=True).click();page.wait_for_load_state('networkidle');assert 'messages_cursor=' in page.url;assert page.get_by_role('link',name='Mensajes más recientes',exact=True).is_visible();shot(page,'conversation-pagination.png');result['checks']['native_cursor_temporal_navigation']=True
            goto(page,'review-create');page.locator('input[name=rating][value="4"]').check();assert page.locator('input[name=rating]:checked').input_value()=='4';shot(page,'review-stars-selected.png')
            goto(page,'availability');before=page.locator('[data-interval-row]').count();page.locator('[data-add-interval]').first.click();assert page.locator('[data-interval-row]').count()==before+1
            while page.locator('[data-interval-row]').count()<42:page.locator('[data-add-interval]').first.click()
            assert page.locator('[data-add-interval]:not([disabled])').count()==0
            names=page.locator('[data-interval-row] input[name]').evaluate_all('(nodes)=>nodes.map(n=>n.name)');assert len(names)==len(set(names));shot(page,'availability-42-rows.png')
            page.locator('[data-remove-interval]').last.click();assert page.locator('[data-interval-row]').count()==41;assert page.locator('[data-add-interval]:not([disabled])').count()==7;result['checks']['availability_add_remove_unique_42_limit']=True
            context.close()
            forms=['request-create','request-detail','reservation-propose','review-create','availability','service-create','service-edit','profile']
            context=browser.new_context(viewport={'width':375,'height':844}, java_script_enabled=False);page=context.new_page()
            for name in forms:
                goto(page,name);assert page.locator('main form').count()>0;assert page.locator('main button[type=submit]').first.is_visible();assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
            goto(page,'review-create');page.locator('input[name=rating][value="5"]').check();assert page.locator('input[name=rating]:checked').input_value()=='5';shot(page,'no-js-review-stars.png')
            goto(page,'availability');assert page.locator('input[type=time]').count()>=28;assert page.locator('[data-add-interval]:visible').count()==0;shot(page,'no-js-availability.png');result['checks']['no_js_eight_form_pages']=True;context.close()
            context=browser.new_context(viewport={'width':1440,'height':1000}, reduced_motion='reduce');page=context.new_page();observe(page)
            context.new_cdp_session(page).send('Emulation.setEmulatedMedia', {'features':[{'name':'prefers-reduced-motion','value':'reduce'},{'name':'prefers-reduced-transparency','value':'reduce'}]})
            for name in forms:
                goto(page,name);verify(page,name);assert page.locator('.tk-ops-plane').evaluate_all('(nodes)=>nodes.every(n=>n.getAnimations().length===0)')
            shot(page,'reduced-motion-profile.png');result['checks']['reduced_motion_and_transparency']=True;context.close()
            context=browser.new_context(viewport={'width':375,'height':844},has_touch=True);page=context.new_page();goto(page,'request-create');assert page.locator('.tk-ops-plane').evaluate_all('(nodes)=>nodes.every(n=>n.getAnimations().length===0)');result['checks']['touch_static']=True;context.close()
            context=browser.new_context(viewport={'width':1440,'height':1000});context.route('**/operations-*.js',lambda route:route.abort());page=context.new_page();goto(page,'request-create');assert page.locator('#message').is_visible() and page.get_by_role('button',name='Confirmar y enviar solicitud').is_enabled();shot(page,'failed-module-form.png');result['checks']['failed_module_static_form']=True;context.close()
            if args.record:
                for mode,width in [('desktop',1440),('mobile',375),('reduced-motion',1440)]:
                    context=browser.new_context(viewport={'width':width,'height':1000 if width==1440 else 844},has_touch=width==375,reduced_motion='reduce' if mode=='reduced-motion' else 'no-preference',record_video_dir=str(args.output/'video-temp'),record_video_size={'width':width,'height':1000 if width==1440 else 844})
                    page=context.new_page();goto(page,'request-create');page.locator('#message').fill('Quiero conversar sobre la identidad visual de mi proyecto.');page.locator('#message').focus();page.wait_for_timeout(650)
                    goto(page,'requests-sent');page.locator('main article a').first.hover();page.wait_for_timeout(650)
                    goto(page,'request-detail');page.locator('#conversacion').scroll_into_view_if_needed();page.wait_for_timeout(750);page.get_by_role('link',name='Mensajes anteriores',exact=True).click();page.wait_for_load_state('networkidle');page.wait_for_timeout(650)
                    for name,selector in [('reservation-propose','#reservation-date'),('reservation-provider','.reservation-actions'),('review-create','.review-rating'),('availability','[data-day="1"]'),('services','main article'),('notifications','main article'),('profile','#display_name')]:
                        goto(page,name);page.locator(selector).first.scroll_into_view_if_needed();page.wait_for_timeout(600)
                        if name=='review-create':page.locator('input[name=rating][value="4"]').check();page.wait_for_timeout(450)
                        if name=='availability' and mode!='reduced-motion':page.locator('[data-add-interval]').first.click();page.wait_for_timeout(450)
                    video=page.video;page.close();video.save_as(str(args.output/f'operations-{mode}.webm'));context.close()
            assert not result['page_errors'] and not result['console_errors'] and not result['failed_resources'],result
            assert not any(a['violations'] for a in result['axe']),[(a['page'],a['width'],[v['id'] for v in a['violations']]) for a in result['axe'] if a['violations']]
            browser.close()
    finally:
        (args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
        print(json.dumps({'matrix':len(result['matrix']),'axe':len(result['axe']),'violations':sum(len(a['violations']) for a in result['axe']),'max_cls':max((r['cls'] for r in result['matrix']),default=0),'errors':len(result['page_errors'])+len(result['console_errors'])+len(result['failed_resources'])}),flush=True)


if __name__=='__main__':main()
