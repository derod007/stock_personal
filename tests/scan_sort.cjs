'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('index.php', 'utf8');
const inline = html.split('<script>').at(-1).split('</script>')[0];
// Reproduce blocked storage for both preference reads and writes. Other UI must still initialize.
for (const search of ['', '?mode=simple']) {
  let reachedFilters = false;
  const doc = {body:{classList:{add(){}},setAttribute(){}},querySelector(){return null;},querySelectorAll(){return [];},
    getElementById(id){if(id==='sector-filters')reachedFilters=true;return null;},addEventListener(){}};
  vm.runInNewContext(inline,{document:doc,URLSearchParams,location:{search},localStorage:{getItem(){throw Error('SecurityError');},setItem(){throw Error('QuotaExceededError');}}});
  assert.equal(reachedFilters,true);
}
function el(attrs) {
  const classes = new Set();return {attrs,classes,getAttribute(k){return this.attrs[k]??null;},setAttribute(k,v){this.attrs[k]=v;},
    classList:{add(c){classes.add(c);},remove(c){classes.delete(c);}},closest(){return this;}};
}
const low=el({'data-score':'24','data-entry-order':'0','data-orig':'0'});
const high=el({'data-score':'90','data-entry-order':'1','data-orig':'1'});
const tied=el({'data-score':'90','data-entry-order':'2','data-orig':'2'});
const missing=el({'data-score':'-1','data-entry-order':'3','data-orig':'3'});
high.classes.add('is-hidden-sector');
const body={rows:[low,high,tied,missing],querySelectorAll(){return this.rows;},appendChild(row){this.rows=this.rows.filter(r=>r!==row);this.rows.push(row);}};
const score=el({'data-sort':'score'}),entry=el({'data-sort':'entry'}),status={textContent:''};
const bar={querySelectorAll(){return [score,entry];},addEventListener(_,fn){this.click=fn;}};
vm.runInNewContext(fs.readFileSync('assets/scan-sort.js','utf8'),{document:{getElementById(id){return id==='scan-sort'?bar:status;},querySelector(){return body;}}});
bar.click({target:score});assert.deepEqual(body.rows,[high,tied,low,missing]);assert.equal(score.attrs['aria-pressed'],'true');assert.equal(entry.attrs['aria-pressed'],'false');
assert.ok(status.textContent.includes('점수 높은 순'));assert.ok(high.classes.has('is-hidden-sector'));
bar.click({target:entry});assert.deepEqual(body.rows,[low,high,tied,missing]);assert.equal(entry.attrs['aria-pressed'],'true');
bar.click({target:entry});assert.ok(status.textContent.includes('순서가 같습니다'));
assert.ok(html.includes('src="assets/scan-sort.js?v=1"'));
console.log('SCAN_SORT_PASS blocked storage, click reorder, stable ties, missing score, entry restoration, filter preservation, same-order feedback');
