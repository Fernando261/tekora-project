"""Cinematic Marketplace acceptance against memory-fixture static exports only."""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', default='http://127.0.0.1:8057')
    parser.add_argument('--fixture', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--axe', type=Path, required=True)
    parser.add_argument('--executable', default=shutil.which('chromium'))
    parser.add_argument('--record', action='store_true')
    args = parser.parse_args()
    origin = urlsplit(args.base_url)
    assert origin.scheme == 'http' and origin.hostname == '127.0.0.1' and not origin.path
    fixture = json.loads(args.fixture.read_text())
    args.output.mkdir(parents=True, exist_ok=False)
    result = {'matrix': [], 'axe': [], 'checks': {}, 'expected_http_errors': [], 'console_errors': [], 'page_errors': [], 'failed_resources': []}
    service, profile = fixture['service_path'], fixture['profile_path']

    def observe(page):
        page.add_init_script('''window.w3CLS=0;new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.w3CLS+=e.value}).observe({type:'layout-shift',buffered:true});''')
        page.on('pageerror', lambda e: result['page_errors'].append(str(e)))
        page.on('requestfailed', lambda r: result['failed_resources'].append(r.url))
        page.on('response', lambda r: result['failed_resources'].append(f'{r.status} {r.url}') if r.status >= 400 and r.request.resource_type != 'document' else None)
        def console(message):
            if message.type != 'error': return
            if message.location.get('url', '').endswith('/preview/invalid-filters') and '422' in message.text:
                result['expected_http_errors'].append(message.text)
            else: result['console_errors'].append({'text': message.text, 'location': message.location})
        page.on('console', console)

    def goto(page, path, status=200):
        response = page.goto(args.base_url + path, wait_until='networkidle')
        assert response.status == status and response.header_value('x-tekora-preview') == 'synthetic-static-memory-export', (path, response.status)
        page.evaluate('document.fonts.ready')
        page.wait_for_timeout(850)

    def shot(page, name, full=False):
        page.screenshot(path=str(args.output / name), full_page=full)

    def verify(page, label):
        assert page.title().endswith(' · Tekora')
        assert 'MindBloom' not in page.locator('body').inner_text()
        metrics = page.evaluate('''()=>{
            const nodes=[...document.querySelectorAll('header a,main input,main select,main button,main .account-button,main .mp-card-action')];
            return {no_horizontal:document.documentElement.scrollWidth<=innerWidth+1,
                controls_inside:nodes.every(n=>{const r=n.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1}),
                duplicate_ids:(()=>{const ids=[...document.querySelectorAll('[id]')].map(n=>n.id);return ids.length-new Set(ids).size})(),
                h1:document.querySelectorAll('h1').length,inline_scripts:document.querySelectorAll('script:not([src])').length,
                cls:window.w3CLS};}''')
        assert metrics['no_horizontal'] and metrics['controls_inside'] and not metrics['duplicate_ids'] and metrics['h1']==1 and metrics['inline_scripts']==0, (label,metrics)
        return metrics

    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path=args.executable,args=['--disable-dev-shm-usage'])
            gpu=browser.new_browser_cdp_session().send('SystemInfo.getInfo')['gpu']
            result['environment']={'browser':browser.version,'headless':True,'renderer':gpu.get('auxAttributes',{}).get('glRenderer')}
            paths=[('catalog','/servicios'),('service',service),('providers','/prestadores'),('profile',profile),('search',fixture['search_path']),('empty','/servicios?q=sin-resultados'),('no-reviews','/preview/service-no-reviews'),('providers-empty','/preview/providers-empty'),('long-text','/preview/long-text'),('invalid','/preview/invalid-filters')]
            for width in (320,375,768,1024,1100,1280,1440,1920):
                context=browser.new_context(viewport={'width':width,'height':1000},has_touch=width<1100)
                page=context.new_page();observe(page)
                for label,path in paths:
                    goto(page,path,422 if label=='invalid' else 200)
                    metrics=verify(page,(width,label));result['matrix'].append({'width':width,'page':label,**metrics})
                    if width in (375,1440) or width==320 and label=='catalog':shot(page,f'{label}-{width}.png',full=label in ('service','profile','empty','invalid','no-reviews','providers-empty'))
                    if width in (375,1440) and label in ('catalog','service','providers','profile','empty','invalid'):
                        page.evaluate(args.axe.read_text())
                        audit=page.evaluate("async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})")
                        result['axe'].append({'width':width,'page':label,'violations':audit['violations'],'incomplete':[{'id':i['id'],'nodes':len(i['nodes'])} for i in audit['incomplete']]})
                context.close();(args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')

            context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();observe(page);goto(page,'/servicios')
            geometry=page.locator('[data-market-scene]').evaluate('''e=>({perspective:getComputedStyle(e).perspective,preserve:getComputedStyle(e.firstElementChild).transformStyle,z:[...e.querySelectorAll('.mp-object')].map(n=>new DOMMatrix(getComputedStyle(n).transform).m43)})''')
            assert geometry['perspective']!='none' and geometry['preserve']=='preserve-3d' and len(set(geometry['z']))>=3
            scene=page.locator('[data-market-scene]');box=scene.bounding_box();stage=scene.locator('[data-market-plane]');before=stage.evaluate('e=>getComputedStyle(e).transform')
            page.mouse.move(box['x']+box['width']*.85,box['y']+box['height']*.2);page.wait_for_timeout(400)
            after=stage.evaluate('e=>getComputedStyle(e).transform');assert before!=after
            values=scene.evaluate('e=>({x:parseFloat(e.style.getPropertyValue("--mp-rx")),y:parseFloat(e.style.getPropertyValue("--mp-ry"))})');assert abs(values['x'])<=2.01 and abs(values['y'])<=3.01
            shot(page,'scene-pointer.png');result['checks']['real_3d_geometry_and_bounded_pointer']={'geometry':geometry,'pointer':values}
            card=page.locator('[data-market-card]').first;card.scroll_into_view_if_needed();card.hover();page.wait_for_timeout(700)
            action=card.locator('.mp-card-action');before=action.bounding_box();plane=card.locator('[data-market-plane]');initial=plane.evaluate('e=>getComputedStyle(e).transform');bounds=card.bounding_box()
            page.mouse.move(bounds['x']+bounds['width']*.85,bounds['y']+40);page.wait_for_timeout(350);final=action.bounding_box()
            assert all(abs(before[k]-final[k])<.2 for k in ('x','y','width','height'))
            assert initial!=plane.evaluate('e=>getComputedStyle(e).transform');shot(page,'card-hover.png');result['checks']['card_background_3d_targets_fixed']=True
            # Real GET submission and pagination, never client-side search.
            goto(page,'/servicios');page.locator('#q').fill('Diseño');page.select_option('#category','diseno');page.select_option('#modality','remote');page.get_by_role('button',name='Buscar',exact=False).click();page.wait_for_load_state('networkidle');page.wait_for_timeout(850)
            assert page.locator('.catalog-result-count').inner_text()=='14 servicios disponibles';assert page.locator('[data-market-card]').count()==12
            shot(page,'filters-active-1440.png');page.locator('.catalog-pagination a[rel=next]').click();page.wait_for_load_state('networkidle')
            assert page.locator('[data-market-card]').count()==2
            assert parse_qs(urlsplit(page.url).query)=={'q':['Diseño'],'category':['diseno'],'modality':['remote'],'page':['2']}
            assert page.locator('#q').input_value()=='Diseño' and page.locator('#category').input_value()=='diseno' and page.locator('#modality').input_value()=='remote'
            page.locator('.mp-reset').click();page.wait_for_load_state('networkidle');assert not urlsplit(page.url).query
            goto(page,profile+'?page=2&reviews_page=2');assert page.locator('[data-market-card]').count()==3
            assert page.locator('nav[aria-label="Páginas de reseñas"] a[rel=prev]').get_attribute('href').endswith('reviews_page=1&page=2#resenas') or parse_qs(urlsplit(page.locator('nav[aria-label="Páginas de reseñas"] a[rel=prev]').get_attribute('href')).query)=={'reviews_page':['1'],'page':['2']}
            result['checks']['native_search_filter_clear_and_independent_pagination']=True
            goto(page,service);page.get_by_role('link',name='Conocer al prestador y sus servicios',exact=False).click();page.wait_for_load_state('networkidle');assert urlsplit(page.url).path==profile
            goto(page,service);page.get_by_role('link',name='Solicitar servicio',exact=False).click();page.wait_for_load_state('networkidle');assert urlsplit(page.url).path=='/login'
            result['checks']['public_journey_provider_and_guest_request_login']=True
            goto(page,'/servicios');page.locator('#q').focus();shot(page,'focus-search.png');assert page.locator('#q').evaluate('e=>getComputedStyle(e).outlineStyle')!='none'
            for label,path in [('service',service),('profile',profile)]:
                goto(page,path);page.locator('.provider-availability').screenshot(path=str(args.output/f'availability-{label}.png'));page.locator('.public-reviews').screenshot(path=str(args.output/f'reviews-{label}.png'))
            session=context.new_cdp_session(page);session.send('Emulation.setCPUThrottlingRate',{'rate':4});goto(page,'/servicios');verify(page,'cpu4');page.locator('#q').fill('sin-resultados');page.get_by_role('button',name='Buscar',exact=False).click();page.wait_for_load_state('networkidle');assert page.locator('.catalog-empty').is_visible();session.send('Emulation.setCPUThrottlingRate',{'rate':1});result['checks']['cpu_4_native_controls']=True;context.close()

            context=browser.new_context(viewport={'width':375,'height':844},java_script_enabled=False);page=context.new_page()
            for label,path in [('catalog','/servicios'),('service',service),('providers','/prestadores'),('profile',profile)]:
                goto(page,path);assert page.locator('h1').is_visible();assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1');assert page.locator('main [inert],main [hidden]').count()==0
            goto(page,'/servicios');page.locator('#q').fill('Diseño');page.select_option('#category','diseno');page.select_option('#modality','remote');page.get_by_role('button',name='Buscar',exact=False).click();page.wait_for_load_state('networkidle');assert page.locator('[data-market-card]').count()==12;shot(page,'no-js-search-375.png');result['checks']['no_js_four_pages_and_native_search']=True;context.close()
            context=browser.new_context(viewport={'width':375,'height':844},has_touch=True,is_mobile=True);page=context.new_page();observe(page);goto(page,'/servicios');assert page.locator('[data-market-scene] [data-market-plane]').evaluate('e=>e.getAnimations().length')==0;result['checks']['touch_static_scene']=True;context.close()
            context=browser.new_context(viewport={'width':1440,'height':1000},reduced_motion='reduce');page=context.new_page();observe(page);goto(page,'/servicios');assert page.locator('[data-market-scene] [data-market-plane]').evaluate('e=>e.getAnimations().length')==0
            box=page.locator('[data-market-scene]').bounding_box();page.mouse.move(box['x']+40,box['y']+40);assert page.locator('[data-market-scene]').get_attribute('style') is None;shot(page,'reduced-motion-1440.png');session=context.new_cdp_session(page);session.send('Emulation.setEmulatedMedia',{'features':[{'name':'prefers-reduced-motion','value':'reduce'},{'name':'prefers-reduced-transparency','value':'reduce'}]});assert page.locator('.catalog-filters').evaluate('e=>getComputedStyle(e).backgroundImage')=='none';shot(page,'reduced-transparency-1440.png');result['checks']['reduced_motion_transparency']=True;context.close()
            # A failed enhancement module leaves the real content/forms available.
            context=browser.new_context(viewport={'width':375,'height':844});page=context.new_page();page.route('**/marketplace-*.js',lambda route:route.abort());goto(page,'/servicios');assert page.locator('h1').is_visible() and page.locator('form[method=GET]').is_visible();result['checks']['failed_js_module_static_fallback']=True;context.close()
            if args.record:
                for width,height,name,motion in [(1440,1000,'desktop','no-preference'),(375,844,'mobile','no-preference'),(1440,1000,'reduced-motion','reduce')]:
                    context=browser.new_context(viewport={'width':width,'height':height},has_touch=width<800,reduced_motion=motion,record_video_dir=str(args.output/'video-temp'),record_video_size={'width':width,'height':height});page=context.new_page();observe(page);goto(page,'/servicios')
                    box=page.locator('[data-market-scene]').bounding_box();page.mouse.move(box['x']+box['width']*.85,box['y']+80);page.wait_for_timeout(450)
                    page.locator('[data-market-card]').first.hover();page.wait_for_timeout(350);page.locator('#q').fill('Diseño');page.select_option('#category','diseno');page.select_option('#modality','remote');page.get_by_role('button',name='Buscar',exact=False).click();page.wait_for_load_state('networkidle');page.wait_for_timeout(650)
                    goto(page,service);page.get_by_role('link',name='Conocer al prestador y sus servicios',exact=False).click();page.wait_for_load_state('networkidle');page.wait_for_timeout(800);page.locator('.public-reviews').scroll_into_view_if_needed();page.wait_for_timeout(450)
                    video=page.video;context.close();video.save_as(str(args.output/f'marketplace-{name}.webm'))
            browser.close()
        assert not result['page_errors'] and not result['console_errors'] and not result['failed_resources'], result
        assert all(not row['violations'] for row in result['axe'])
        assert all(row['cls']==0 for row in result['matrix']), [(row['width'],row['page'],row['cls']) for row in result['matrix'] if row['cls']]
        print(json.dumps({'cases':len(result['matrix']),'axe':len(result['axe']),'checks':result['checks'],'unexpected_errors':0}))
    finally:
        (args.output/'browser-results.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')


if __name__=='__main__':
    main()
