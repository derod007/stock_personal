"""Private report and chronologically selected charts; no performance-based selection."""
import json,sys,statistics,collections,hashlib,html,io
from pathlib import Path
from datetime import datetime,timezone,timedelta
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

def day(t):return datetime.fromtimestamp(t,timezone(timedelta(hours=9))).date().isoformat()
def main():
    result_path,bar_dir,out=map(Path,sys.argv[1:4]);out.mkdir(exist_ok=True,parents=True)
    data=json.loads(result_path.read_text());rr=sorted(data['records'],key=lambda r:(r['signal']['anchor'],r['signal']['symbol']))
    closed=[r for r in rr if r['result']['status']=='closed'];v=[r['result']['net_return_pct'] for r in closed]
    summary={'selected':len(rr),'statuses':dict(collections.Counter(r['result']['status'] for r in rr)),
        'closed_mean_pct':statistics.mean(v) if v else None,'closed_median_pct':statistics.median(v) if v else None,
        'win_pct':100*sum(x>0 for x in v)/len(v) if v else None,
        'exits':dict(collections.Counter(r['result']['first_exit'] for r in closed)),
        'entry_risk_pct':[100*(r['signal']['entry']-r['signal']['stop'])/r['signal']['entry'] for r in rr],
        'rejections':{'quality':sum(bool(c['event']['quality_reasons']) for c in data['cases'] if c['exclusion']=='signal_ineligible'),
        'target_already_touched':sum(c['event']['target_already_touched'] for c in data['cases'] if c['exclusion']=='signal_ineligible')}}
    (out/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
    parts=['<!doctype html><meta charset="utf-8"><title>저점 파동 판독 근거</title><style>body{font:16px sans-serif;max-width:1100px;margin:32px auto;padding:16px;color:#182536}svg{width:100%;height:auto}section{border-top:1px solid #ccc;padding:20px 0}table{border-collapse:collapse}td,th{padding:8px;border:1px solid #ddd}</style>',
        '<h1>저점 파동 판독 · 실제 신호 12건</h1><p>날짜·종목 순서로 선택했습니다. L/H는 피벗 발생일이며 빈 원은 2봉 뒤 확인일입니다. 검은 세로선은 진입 판단일, 오른쪽 회색 영역은 이후 경로입니다. 원문 정성 설명의 구현 가정이며 실제 추천 목록이 아닙니다.</p>']
    figsheet,axes=plt.subplots(4,3,figsize=(18,16));
    for n,r in enumerate(rr[:12]):
        sig=r['signal'];s=r['event']['setup'];bars=json.loads((bar_dir/(sig['symbol']+'.json')).read_text())['rows']
        bars=[b for b in bars if day(s['L1']['pivot_at'])<=b['day']<=sig['end'] and b['close']>0];ds=[b['day'] for b in bars];x=list(range(len(bars)));ix=ds.index(sig['anchor'])
        fig,ax=plt.subplots(figsize=(11,4.3))
        for a in [ax,axes.flat[n]]:
            a.plot(x,[b['close'] for b in bars],color='#243d61',lw=1.4)
            a.fill_between(x,[b['low'] for b in bars],[b['high'] for b in bars],color='#c1d0df',alpha=.45)
            a.axvspan(ix,len(bars)-1,color='#dddddd',alpha=.35);a.axvline(ix,color='black',ls='--')
            for k,col in [('trigger','#bd8400'),('target','#258451'),('stop','#b33838')]:a.axhline(s[k],color=col,ls=':',label=k)
            for k in ['L1','H1','L2','H2','L3','H3','retry']:
                p=s[k];pi=ds.index(day(p['pivot_at']));ci=ds.index(day(p['confirmed_at']));a.scatter(pi,p['price'],s=20,color='#754a9d');a.scatter(ci,p['price'],s=20,facecolors='none',edgecolors='#754a9d');a.annotate(k,(pi,p['price']),xytext=(0,8),textcoords='offset points',fontsize=8)
            a.set_title(f"{sig['symbol']} | {sig['anchor']} | {r['result']['status']} {r['result'].get('net_return_pct','')}%",fontsize=10)
            ticks=sorted(set([0,ix,len(bars)-1]));a.set_xticks(ticks,[ds[i] for i in ticks],fontsize=8);a.grid(alpha=.15)
        ax.legend(loc='best',fontsize=8);fig.tight_layout();buf=io.StringIO();fig.savefig(buf,format='svg');plt.close(fig)
        svg=buf.getvalue();svg=svg[svg.index('<svg'):];parts+=['<section><h2>'+html.escape(sig['symbol']+' / '+sig['anchor'])+'</h2>',svg,'<p>'+html.escape(f"지정가 {sig['entry']:,.0f}, 구조 손절 {sig['stop']:,.0f}, 이전 파동 목표 {sig['target']:,.0f}. 결과 {r['result']['status']} / 순익 {r['result'].get('net_return_pct','미체결')}%")+'</p><table><tr><th>파동</th><th>발생일</th><th>확인일</th><th>가격</th></tr>']
        for k in ['L1','H1','L2','H2','L3','H3','retry']:
            p=s[k];parts.append(f'<tr><td>{k}</td><td>{day(p["pivot_at"])}</td><td>{day(p["confirmed_at"])}</td><td>{p["price"]:,.0f}</td></tr>')
        parts+=['</table></section>']
    figsheet.tight_layout();figsheet.savefig(out/'contact-sheet.png',dpi=120);plt.close(figsheet)
    (out/'charts.html').write_text('\n'.join(parts));print(json.dumps(summary,ensure_ascii=False))

if __name__=='__main__':main()
