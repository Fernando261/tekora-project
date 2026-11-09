"""Native Chromium 100/200/100 zoom of the four public marketplace pages."""
import argparse
import json
import shutil
import tempfile
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url',default='http://127.0.0.1:8057')
    parser.add_argument('--fixture',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--executable',default=shutil.which('chromium'))
    args=parser.parse_args();origin=urlsplit(args.base_url)
    assert origin.scheme=='http' and origin.hostname=='127.0.0.1' and not origin.path
    fixture=json.loads(args.fixture.read_text());args.output.mkdir(parents=True,exist_ok=False)
    result={'method':'Chromium settingsPrivate.setDefaultZoom in deleted temporary profile; native zoom without CSS zoom or viewport emulation','checks':[],'page_errors':[],'failed_resources':[]}
    try:
        with tempfile.TemporaryDirectory(prefix='tekora-w3-zoom-') as profile, sync_playwright() as p:
            context=p.chromium.launch_persistent_context(profile,executable_path=args.executable,headless=True,no_viewport=True,record_video_dir=str(args.output/'video-temp'),record_video_size={'width':1440,'height':1000},args=['--window-size=1440,1000','--disable-dev-shm-usage'])
            settings=context.pages[0];settings.goto('chrome://settings/appearance');settings.wait_for_timeout(300)
            assert settings.evaluate('typeof chrome.settingsPrivate?.setDefaultZoom')=='function'
            page=context.new_page();page.on('pageerror',lambda e:result['page_errors'].append(str(e)));page.on('requestfailed',lambda r:result['failed_resources'].append(r.url))
            for zoom in (1,2,1):
                settings.evaluate('(z)=>new Promise(resolve=>chrome.settingsPrivate.setDefaultZoom(z,resolve))',zoom)
                for name,path in [('catalog','/servicios'),('service',fixture['service_path']),('providers','/prestadores'),('profile',fixture['profile_path'])]:
                    response=page.goto(args.base_url+path,wait_until='networkidle');assert response.header_value('x-tekora-preview')=='synthetic-static-memory-export';page.evaluate('document.fonts.ready');page.wait_for_timeout(800)
                    metrics=page.evaluate('''()=>({outer:outerWidth,css:innerWidth,dpr:devicePixelRatio,scroll:document.documentElement.scrollWidth,controls_inside:[...document.querySelectorAll('header a,main input,main select,main button,main .account-button')].every(n=>{const r=n.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1})})''')
                    assert metrics['outer']==1440 and metrics['css']==1440/zoom and metrics['dpr']==zoom and metrics['scroll']<=metrics['css']+1 and metrics['controls_inside'],(zoom,name,metrics)
                    if zoom==2:
                        page.screenshot(path=str(args.output/f'{name}-zoom-200.png'),full_page=False)
                        target=page.locator('#q') if name=='catalog' else page.locator('.catalog-actions .account-button') if name=='service' else page.locator('[data-market-card] a').first
                        target.focus();target.scroll_into_view_if_needed();page.wait_for_timeout(180)
                        assert target.evaluate('e=>getComputedStyle(e).outlineStyle')!='none'
                        page.screenshot(path=str(args.output/f'{name}-zoom-200-focus.png'),full_page=False)
                    result['checks'].append({'zoom_percent':zoom*100,'page':name,**metrics})
            video=page.video;page.close();video.save_as(str(args.output/'marketplace-native-zoom.webm'));context.close()
        assert not result['page_errors'] and not result['failed_resources']
        print(json.dumps({'checks':len(result['checks']),'native_zoom_200':True}))
    finally:
        (args.output/'native-zoom.json').write_text(json.dumps(result,indent=2)+'\n')


if __name__=='__main__':main()
