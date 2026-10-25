"""Posthoc opposing-outcome illustrations; never used to select rules."""
import argparse,base64,collections,gzip,html,json
from pathlib import Path
import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
from matplotlib.patches import Rectangle

def shape(hist):
 p=hist[:,3]/hist[-1,3];v=hist[:,4];pn=np.linalg.norm(p);vn=np.linalg.norm(v)
 return np.r_[p/pn,v/vn] if pn>0 and vn>0 else None

def candles(ax,bs,scale):
 for x,b in zip(range(-20,21),bs):
  o,h,l,c=b[:4]/scale*100;color='#db5459' if c>=o else '#20977c'
  ax.plot([x,x],[l,h],color=color,lw=.8);ax.add_patch(Rectangle((x-.32,min(o,c)),.64,max(abs(c-o),.035),facecolor=color,edgecolor=color,lw=.5))
 ax.axvline(.5,color='#334155',ls='--');ax.axvspan(.5,20.7,color='#dbeafe',alpha=.25);ax.grid(alpha=.15);ax.set_xlim(-21,21);ax.set_ylabel('D close = 100')

def main():
 ap=argparse.ArgumentParser()
 for n in ['results','prices']:ap.add_argument('--'+n,type=Path,required=True)
 a=ap.parse_args();sel=json.loads((a.results/'selection.json').read_text());cuts=json.loads((a.results/'cuts.json').read_text());j=cuts['features'].index(sel['feature']);groups=collections.defaultdict(list)
 for l in gzip.open(a.results/'observations.jsonl.gz','rt'):
  r=json.loads(l)
  if r['bins'][j]==sel['bin']:groups[r['segment']].append(r)
 folder=a.results/'charts';folder.mkdir(exist_ok=True);examples=[];sections=[]
 for seg,rows in groups.items():
  z=np.load(a.prices/(seg+'.npz'));m=z['matrix'];si={str(v):i for i,v in enumerate(z['symbols'])};vectors=np.array([shape(m[si[r['symbol']],r['i']-20:r['i']+1]) for r in rows]);center=np.median(vectors,axis=0)
  hit=[k for k,r in enumerate(rows) if r['hit15']==100];non=[k for k,r in enumerate(rows) if r['hit15']==0]
  if not hit or not non:continue
  hi=min(hit,key=lambda k:float(np.sum((vectors[k]-center)**2)));lo=min(non,key=lambda k:float(np.sum((vectors[k]-vectors[hi])**2)))
  fig,ax=plt.subplots(2,2,figsize=(15,7),gridspec_kw={'height_ratios':[3,1]},sharex=True)
  for col,k in enumerate([hi,lo]):
   r=rows[k];i=r['i'];bs=m[si[r['symbol']],i-20:i+21];entry=bs[21,0];scale=bs[20,3];candles(ax[0,col],bs,scale)
   ax[0,col].hlines(entry/scale*115,1,20,color='#6d28d9',linestyle=':',label='Entry open +15%');ax[0,col].scatter([1],[entry/scale*100],color='#111827',s=25,zorder=5)
   ax[0,col].set_title(f"{seg} | {r['symbol']} | D={r['date']}\n{'HIT +15%' if col==0 else 'NO HIT'}; 20-close net {r['net20']:+.2f}%")
   ax[0,col].legend(fontsize=8);ax[1,col].bar(range(-20,21),bs[:,4]/np.mean(bs[:21,4]),color='#64748b');ax[1,col].axvline(.5,color='#334155',ls='--');ax[1,col].set_xlabel('Session offset from D (future shaded above)');ax[1,col].set_ylabel('Volume / past mean')
   examples.append(dict(segment=seg,role='hit' if col==0 else 'nonhit',symbol=r['symbol'],date=r['date'],net20=r['net20'],hit15=r['hit15'],first_hit=r['first_hit'],features=r['features'],past_only_distance=float(np.sum((vectors[k]-vectors[hi])**2))))
  fig.suptitle('Similar past price/volume shapes, different future outcomes | illustration only',fontsize=12);fig.tight_layout();name=seg+'-pair.png';fig.savefig(folder/name,dpi=120);plt.close(fig)
  encoded=base64.b64encode((folder/name).read_bytes()).decode();sections.append(f'<h2>{html.escape(seg)}</h2><img alt="Past and future comparison" src="data:image/png;base64,{encoded}">')
 (a.results/'examples.json').write_text(json.dumps(examples,indent=2))
 doc='<!doctype html><meta charset="utf-8"><title>D-20 chart comparisons</title><style>body{font-family:system-ui;max-width:1500px;margin:30px auto;padding:20px;color:#172033}img{width:100%;height:auto}p{line-height:1.7}</style><h1>D−20~D 차트와 이후 경로 비교</h1><p>선택된 특징 구간에서 실제 +15% 도달 사례 하나와, 그 사례의 과거 가격·거래량 모양이 가장 가까운 미도달 사례를 기간별로 골랐습니다. 점선 왼쪽만 유사도에 사용했습니다. 오른쪽은 이후 결과입니다. 결과 라벨을 보고 고른 설명용 사례이며 전체 성과를 대표하거나 진입 근거가 되는 사례가 아닙니다. 보라색 선은 다음 시가 대비 +15%, 검은 점은 다음 날 시가입니다. HIT는 20봉 이내 고가 도달이며 최종 순익과 다릅니다.</p>'+''.join(sections)
 (a.results/'chart-comparison.html').write_text(doc)
 print(json.dumps(examples,indent=2))
if __name__=='__main__':main()
