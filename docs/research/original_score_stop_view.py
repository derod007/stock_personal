import json,datetime as dt
from pathlib import Path
from original_score_stop_review import dayof

def build(root):
 rows=json.loads((root/'trades.json').read_text());summary=json.loads((root/'summary.json').read_text());cal=json.loads((root/'calendars.json').read_text())
 for r in rows:
  bars=json.loads((root/'prepared'/r['segment']/(r['symbol']+'.json')).read_text());by={dayof(b.get('available_at') or b['time']):b for b in bars}
  def pack(day):
   b=by.get(day,{});return [day]+[b.get(k) for k in ['open','high','low','close','volume']]
  r['pre']=[pack(d) for d in sorted(by) if d<=r['signal_day']][-60:]
  start=r['original']['entry_day'];r['post']=[pack(d) for d in cal[r['segment']] if d>=start][:20]
 template=Path(__file__).with_suffix('.html').read_text();(root/'original-score-trades.html').write_text(template.replace('__DATA__',json.dumps(dict(rows=rows,summary=summary),ensure_ascii=False,separators=(',',':')).replace('</','<\\/')))
if __name__=='__main__':
 import sys;build(Path(sys.argv[1]))
