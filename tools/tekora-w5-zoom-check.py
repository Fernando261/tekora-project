"""Native Chromium 100/200/100 zoom for seven identity page families."""
import argparse
import base64
import struct
import json
import shutil
import tempfile
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--base-url',default='http://127.0.0.1:8077');p.add_argument('--pages',type=Path,required=True);p.add_argument('--output',type=Path,required=True);p.add_argument('--executable',default=shutil.which('chromium'));args=p.parse_args()
    origin=urlsplit(args.base_url);assert origin.scheme=='http' and origin.hostname=='127.0.0.1' and not origin.path
    pages=json.loads(args.pages.read_text());args.output.mkdir(parents=True,exist_ok=False)
    names=['account-client','activation-pending','profile-photo','edit-name','roles','forgot','reset']
    result={'method':'Chromium settingsPrivate.setDefaultZoom in a deleted temporary profile; no CSS zoom or viewport emulation. Native screenshots use unclipped CDP surface capture in physical pixels.','checks':[],'page_errors':[],'failed_resources':[]}
    try:
        with tempfile.TemporaryDirectory(prefix='tekora-w5-zoom-') as profile,sync_playwright() as p:
            context=p.chromium.launch_persistent_context(profile,executable_path=args.executable,headless=True,no_viewport=True,record_video_dir=str(args.output/'video-temp'),record_video_size={'width':1440,'height':1000},args=['--window-size=1440,1000','--disable-dev-shm-usage'])
            settings=context.pages[0];settings.goto('chrome://settings/appearance');settings.wait_for_timeout(300);assert settings.evaluate('typeof chrome.settingsPrivate?.setDefaultZoom')=='function'
            page=context.new_page();surface=context.new_cdp_session(page)
            def capture(name):
                data=base64.b64decode(surface.send('Page.captureScreenshot',{'format':'png','fromSurface':True,'captureBeyondViewport':False})['data'])
                assert struct.unpack('>II',data[16:24])[0]==1440
                (args.output/name).write_bytes(data)
            page.on('pageerror',lambda e:result['page_errors'].append(str(e)));page.on('requestfailed',lambda r:result['failed_resources'].append(r.url))
            for zoom in (1,2,1):
                settings.evaluate('(z)=>new Promise(resolve=>chrome.settingsPrivate.setDefaultZoom(z,resolve))',zoom)
                for name in names:
                    response=page.goto(args.base_url+pages[name]['path'],wait_until='networkidle');assert response.header_value('x-tekora-preview')=='synthetic-static-memory-export';page.evaluate('document.fonts.ready');page.wait_for_timeout(720)
                    metrics=page.evaluate("""()=>({outer:outerWidth,css:innerWidth,dpr:devicePixelRatio,scroll:document.documentElement.scrollWidth,controls_inside:[...document.querySelectorAll('main input:not([type=hidden]),main textarea,main select,main button,main .account-button')].filter(n=>n.getClientRects().length).every(n=>{const r=n.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1})})""")
                    assert metrics['outer']==1440 and metrics['css']==1440/zoom and metrics['dpr']==zoom and metrics['scroll']<=metrics['css']+1 and metrics['controls_inside'],(zoom,name,metrics)
                    if zoom==2:
                        capture(f'{name}-zoom-200.png')
                        target=page.locator('main textarea,main input:not([type=hidden]),main select,main .account-button,main a').first
                        if target.count():
                            target.focus();target.scroll_into_view_if_needed();page.wait_for_timeout(500);assert target.evaluate('e=>{const r=e.getBoundingClientRect();return getComputedStyle(e).outlineStyle!=="none"&&r.top>=0&&r.bottom<=innerHeight&&r.left>=0&&r.right<=innerWidth}');capture(f'{name}-zoom-200-focus.png')
                        else:
                            assert name=='roles'  # Read-only catalog intentionally has no main controls.
                    result['checks'].append({'zoom_percent':zoom*100,'page':name,**metrics})
            video=page.video;page.close();video.save_as(str(args.output/'identity-native-zoom.webm'));context.close()
        assert not result['page_errors'] and not result['failed_resources'];print(json.dumps({'checks':len(result['checks']),'native_zoom_200':True}))
    finally:(args.output/'native-zoom.json').write_text(json.dumps(result,indent=2)+'\n')


if __name__=='__main__':main()
