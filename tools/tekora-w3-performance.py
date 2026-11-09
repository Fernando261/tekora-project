"""Local before/after marketplace samples; not production or hardware certification."""
import argparse
import json
import shutil
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--baseline-url',required=True);p.add_argument('--base-url',required=True)
    p.add_argument('--fixture',type=Path,required=True);p.add_argument('--output',type=Path,required=True)
    p.add_argument('--executable',default=shutil.which('chromium'));args=p.parse_args()
    for uri in [args.baseline_url,args.base_url]:
        parsed=urlsplit(uri);assert parsed.scheme=='http' and parsed.hostname=='127.0.0.1' and not parsed.path
    fixture=json.loads(args.fixture.read_text());result={'method':'Two fresh contexts per page/width/version, 1200ms observation after network idle. Initial LCP and CLS exclude user input; local static HTTP, software compositor. Extra CPU4 samples are synthetic.','runs':[]}
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=args.executable,args=['--disable-dev-shm-usage']);result['browser']=browser.version
        pages=[('catalog','/servicios'),('service',fixture['service_path']),('providers','/prestadores'),('profile',fixture['profile_path'])]
        for version,origin in [('base-w211',args.baseline_url),('w3',args.base_url)]:
            for width in (375,1440):
                for name,path in pages:
                    for run in range(2):
                        context=browser.new_context(viewport={'width':width,'height':1000});page=context.new_page();errors=[]
                        page.on('pageerror',lambda e:errors.append(str(e)));page.on('requestfailed',lambda r:errors.append(r.url))
                        page.add_init_script('''window.mpLCP=0;window.mpCLS=0;new PerformanceObserver(l=>{for(const e of l.getEntries())window.mpLCP=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.mpCLS+=e.value}).observe({type:'layout-shift',buffered:true});''')
                        response=page.goto(origin+path,wait_until='networkidle');assert response.header_value('x-tekora-preview')=='synthetic-static-memory-export';page.wait_for_timeout(1200)
                        row=page.evaluate('''()=>({lcp_ms:mpLCP,cls:mpCLS,requests:performance.getEntriesByType('resource').length,encoded_resource_bytes:performance.getEntriesByType('resource').reduce((n,e)=>n+e.encodedBodySize,0),no_horizontal:document.documentElement.scrollWidth<=innerWidth+1})''')
                        row.update(version=version,width=width,page=name,run=run+1,errors=errors);result['runs'].append(row);assert not errors and row['no_horizontal'] and row['lcp_ms']>0
                        context.close()
        for name,path in pages:
            context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();session=context.new_cdp_session(page);session.send('Emulation.setCPUThrottlingRate',{'rate':4})
            page.add_init_script('''window.mpLCP=0;window.mpCLS=0;new PerformanceObserver(l=>{for(const e of l.getEntries())mpLCP=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)mpCLS+=e.value}).observe({type:'layout-shift',buffered:true});''')
            page.goto(args.base_url+path,wait_until='networkidle');page.wait_for_timeout(1500);row=page.evaluate('({lcp_ms:mpLCP,cls:mpCLS})');row.update(version='w3',page=name,width=1440,cpu_rate=4);result['runs'].append(row);context.close()
        browser.close()
    args.output.write_text(json.dumps(result,indent=2)+'\n')
    w3=[r for r in result['runs'] if r['version']=='w3'];print(json.dumps({'runs':len(result['runs']),'w3_max_lcp_ms':max(r['lcp_ms'] for r in w3),'w3_max_cls':max(r['cls'] for r in w3)}))


if __name__=='__main__':main()
