"""Before/after laboratory samples over identical memory fixtures, never production INP."""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright


NAMES=['account-client','activation-pending','profile-photo','edit-name','roles','forgot','reset']
INIT="""window.opsLCP=0;window.opsCLS=0;new PerformanceObserver(l=>{for(const e of l.getEntries())opsLCP=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)opsCLS+=e.value}).observe({type:'layout-shift',buffered:true});"""


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--baseline-url',required=True);p.add_argument('--base-url',required=True);p.add_argument('--output',type=Path,required=True);p.add_argument('--executable',default=shutil.which('chromium'));args=p.parse_args()
    for uri in [args.base_url,args.baseline_url]:
        u=urlsplit(uri);assert u.scheme=='http' and u.hostname=='127.0.0.1' and not u.path
    result={'method':'Two fresh contexts per page/width/version, 1200ms observation after network idle; seven families, 375/1440px. Seven extra W5 CPU4 samples. Loopback Linux/software; not field INP.','runs':[],'interaction':[]}
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=args.executable,args=['--disable-dev-shm-usage']);result['browser']=browser.version
        for version,origin in [('base-w4',args.baseline_url),('w5',args.base_url)]:
            for width in (375,1440):
                for name in NAMES:
                    for run in range(2):
                        context=browser.new_context(viewport={'width':width,'height':1000});page=context.new_page();errors=[];page.add_init_script(INIT)
                        page.on('pageerror',lambda e:errors.append(str(e)));page.on('requestfailed',lambda r:errors.append(r.url))
                        response=page.goto(origin+'/preview/w5/'+name,wait_until='networkidle');assert response.header_value('x-tekora-preview')=='synthetic-static-memory-export';page.wait_for_timeout(1200)
                        row=page.evaluate("""()=>({lcp_ms:opsLCP,cls:opsCLS,requests:performance.getEntriesByType('resource').length,encoded_resource_bytes:performance.getEntriesByType('resource').reduce((n,e)=>n+e.encodedBodySize,0),no_horizontal:document.documentElement.scrollWidth<=innerWidth+1})""")
                        row.update(version=version,width=width,page=name,run=run+1,errors=errors);result['runs'].append(row);assert (version=='base-w4' or not errors and row['no_horizontal']) and row['lcp_ms']>0;context.close()
                print(version,width,'complete',flush=True)
                args.output.write_text(json.dumps(result,indent=2)+'\n')
        for name in NAMES:
            context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();session=context.new_cdp_session(page);session.send('Emulation.setCPUThrottlingRate',{'rate':4});page.add_init_script(INIT)
            page.goto(args.base_url+'/preview/w5/'+name,wait_until='networkidle');page.wait_for_timeout(1500);row=page.evaluate('({lcp_ms:opsLCP,cls:opsCLS})');row.update(version='w5',page=name,width=1440,cpu_rate=4);result['runs'].append(row);context.close();args.output.write_text(json.dumps(result,indent=2)+'\n')
        for rate in (1,4):
            context=browser.new_context(viewport={'width':390,'height':1000});page=context.new_page();session=context.new_cdp_session(page);session.send('Emulation.setCPUThrottlingRate',{'rate':rate});page.goto(args.base_url+'/preview/w5/profile-photo',wait_until='networkidle')
            page.evaluate("""()=>{window.opsLatency=[];document.addEventListener('click',e=>{if(e.target.matches('[data-account-navigation] > summary')){const at=e.timeStamp;requestAnimationFrame(()=>requestAnimationFrame(()=>opsLatency.push(performance.now()-at)))}})}""")
            for index in range(5):
                page.locator('[data-account-navigation] > summary').click()
                page.wait_for_function('(count)=>opsLatency.length===count',arg=index+1)
            latency=page.evaluate('opsLatency');assert len(latency)==5
            scroll=page.evaluate("""()=>new Promise(resolve=>{let last=performance.now(),start=last,deltas=[];function frame(now){deltas.push(now-last);last=now;scrollBy(0,18);if(now-start<650)requestAnimationFrame(frame);else resolve({frames:deltas.length,mean_frame_ms:deltas.reduce((a,b)=>a+b,0)/deltas.length,max_frame_ms:Math.max(...deltas),no_horizontal:document.documentElement.scrollWidth<=innerWidth+1})}requestAnimationFrame(frame)})""")
            result['interaction'].append({'cpu_rate':rate,'method':'Actual native mobile-menu summary clicks: event timestamp to second rAF; presentation proxy, not INP. Scroll rAF cadence is diagnostic, not GPU FPS.','click_to_second_raf_ms':latency,'scroll':scroll});context.close()
        browser.close()
    args.output.write_text(json.dumps(result,indent=2)+'\n');w5=[r for r in result['runs'] if r['version']=='w5'];print(json.dumps({'samples':len(result['runs']),'w5_max_cls':max(r['cls'] for r in w5),'w5_max_lcp_ms':max(r['lcp_ms'] for r in w5)}))


if __name__=='__main__':main()
