"""W5 acceptance against synthetic memory-fixture GET exports, never a live backend."""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--base-url', default='http://127.0.0.1:8077')
    p.add_argument('--pages', type=Path, required=True)
    p.add_argument('--output', type=Path, required=True)
    p.add_argument('--axe', type=Path, required=True)
    p.add_argument('--executable', default=shutil.which('chromium'))
    p.add_argument('--record', action='store_true')
    p.add_argument('--quick', action='store_true')
    p.add_argument('--names', nargs='*')
    p.add_argument('--matrix-only', action='store_true')
    p.add_argument('--resume-flows', action='store_true', help='Reuse a completed matrix and repeat functional checks and video.')
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
    observer = """window.identityCLS=0;new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)identityCLS+=e.value}).observe({type:'layout-shift',buffered:true});"""

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
          return {no_horizontal:document.documentElement.scrollWidth<=innerWidth+1,controls_inside:controls.every(n=>{const r=n.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1}),duplicate_ids:ids.length-new Set(ids).size,h1:document.querySelectorAll('main h1').length,inline_scripts:document.querySelectorAll('script:not([src])').length,cls:identityCLS};
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
            for name in ['account-client','profile-no-photo','forgot']:
                goto(page,name)
                geometry=page.locator('[data-identity-scene]').evaluate("""e=>({perspective:getComputedStyle(e).perspective,preserve:getComputedStyle(e.firstElementChild).transformStyle,z:[...e.querySelectorAll('.tk-identity-plane')].map(n=>new DOMMatrix(getComputedStyle(n).transform).m43)})""")
                assert geometry['perspective']=='1000px' and geometry['preserve']=='preserve-3d' and len(set(geometry['z']))==3
                result['checks'][name+'_3d']=geometry
            goto(page,'account-client');target=page.locator('main [data-identity-card] a').first;before=target.bounding_box();card=page.locator('[data-identity-card]').first;box=card.bounding_box();page.mouse.move(box['x']+box['width']*.8,box['y']+box['height']*.3);page.wait_for_timeout(300);after=target.bounding_box();assert all(abs(before[k]-after[k])<.5 for k in before);shot(page,'card-hover.png');result['checks']['targets_fixed']=True
            page.get_by_role('link',name='Mis datos personales',exact=True).click();page.wait_for_load_state('networkidle');assert urlsplit(page.url).path=='/perfil';page.get_by_role('link',name='Editar nombre',exact=True).click();page.wait_for_load_state('networkidle');assert urlsplit(page.url).path=='/perfil/editar-nombre';result['checks']['native_center_profile_edit_navigation']=True
            goto(page,'edit-name');page.locator('#personal-name').focus();page.keyboard.press('Tab');assert page.evaluate('document.activeElement.type')=='submit';page.keyboard.press('Shift+Tab');assert page.locator('#personal-name').evaluate('e=>e===document.activeElement');shot(page,'edit-name-focus.png');result['checks']['keyboard_focus_order']=True
            posts=[];page.on('request',lambda r:posts.append(r.url) if r.method!='GET' else None)
            goto(page,'profile-no-photo');page.locator('input[type=file]').set_input_files({'name':'avatar.png','mimeType':'image/png','buffer':b'fixture-not-submitted'});page.wait_for_timeout(400);assert not posts;assert page.get_by_role('button',name='Guardar foto de perfil').is_enabled();shot(page,'native-file-selected.png');result['checks']['file_selection_never_auto_uploads']=True
            for name in ['forgot','reset']:
                goto(page,name);assert page.locator('input[name=email]').is_visible()
                for field in page.locator('input[type=password]').all():assert not field.get_attribute('value')
            context.close()
            forms=['activation-pending','profile-no-photo','edit-name','forgot','reset']
            context=browser.new_context(viewport={'width':375,'height':844},java_script_enabled=False);page=context.new_page()
            for name in forms:
                goto(page,name);assert page.locator('main form').count()>0;assert page.locator('main button[type=submit]').first.is_visible();assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1');shot(page,'no-js-'+name+'.png')
            page.locator('input[name=email]').fill('synthetic@example.test');page.locator('input[name=password]').fill('synthetic-not-submitted');page.locator('input[name=password_confirmation]').fill('synthetic-not-submitted');assert page.locator('form').evaluate('e=>e.checkValidity()');result['checks']['no_js_native_forms']=True;context.close()
            context=browser.new_context(viewport={'width':1440,'height':1000},reduced_motion='reduce');page=context.new_page();observe(page);context.new_cdp_session(page).send('Emulation.setEmulatedMedia',{'features':[{'name':'prefers-reduced-motion','value':'reduce'},{'name':'prefers-reduced-transparency','value':'reduce'}]})
            for name in ['account-client','profile-no-photo','forgot']:
                goto(page,name);verify(page,name);assert page.locator('.tk-identity-plane').evaluate_all('(nodes)=>nodes.every(n=>n.getAnimations().length===0)')
            shot(page,'reduced-security.png');result['checks']['reduced_preferences']=True;context.close()
            context=browser.new_context(viewport={'width':375,'height':844},has_touch=True);page=context.new_page();goto(page,'account-client');assert page.locator('.tk-identity-plane').evaluate_all('(nodes)=>nodes.every(n=>n.getAnimations().length===0)');result['checks']['touch_static']=True;context.close()
            context=browser.new_context(viewport={'width':1440,'height':1000});context.route('**/identity-*.js',lambda route:route.abort());page=context.new_page();goto(page,'reset');assert page.locator('input[name=password]').is_visible();assert page.get_by_role('button',name='Restablecer contraseña').is_enabled();shot(page,'failed-module-reset.png');result['checks']['failed_module_keeps_forms']=True;context.close()
            if args.record:
                for mode,width in [('desktop',1440),('mobile',375),('reduced-motion',1440)]:
                    context=browser.new_context(viewport={'width':width,'height':1000 if width==1440 else 844},has_touch=width==375,reduced_motion='reduce' if mode=='reduced-motion' else 'no-preference',record_video_dir=str(args.output/'video-temp'),record_video_size={'width':width,'height':1000 if width==1440 else 844})
                    page=context.new_page()
                    for name in ['account-client','account-provider','account-dual','account-admin','activation-pending','activation-active','profile-photo','profile-no-photo','edit-name','roles','forgot','reset','name-validation','session-expired']:
                        goto(page,name);page.wait_for_timeout(400)
                        if name=='profile-no-photo':page.locator('input[type=file]').scroll_into_view_if_needed();page.wait_for_timeout(500)
                        if name=='edit-name':page.locator('#personal-name').focus();page.wait_for_timeout(400)
                        if name=='forgot':page.locator('input[name=email]').focus();page.wait_for_timeout(400)
                        if name=='reset':page.locator('input[name=password_confirmation]').scroll_into_view_if_needed();page.wait_for_timeout(400)
                    video=page.video;page.close();video.save_as(str(args.output/f'identity-{mode}.webm'));context.close()
            assert not result['page_errors'] and not result['console_errors'] and not result['failed_resources'],result
            assert not any(a['violations'] for a in result['axe']),[(a['page'],a['width'],[v['id'] for v in a['violations']]) for a in result['axe'] if a['violations']]
            browser.close()
    finally:
        (args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
        print(json.dumps({'matrix':len(result['matrix']),'axe':len(result['axe']),'violations':sum(len(a['violations']) for a in result['axe']),'max_cls':max((r['cls'] for r in result['matrix']),default=0),'errors':len(result['page_errors'])+len(result['console_errors'])+len(result['failed_resources'])}),flush=True)


if __name__=='__main__':main()
