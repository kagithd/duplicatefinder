const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const path = require('node:path')
const { JSDOM } = require('jsdom')
const dom = new JSDOM('<!doctype html><html><body></body></html>')
global.window = dom.window
global.document = dom.window.document
global.navigator = dom.window.navigator
const Vue = require('vue/dist/vue.common.js')
Vue.config.productionTip = false
Vue.config.devtools = false
const compiler = require('vue-template-compiler')
const babel = require('@babel/core')
const tick = async () => { await Promise.resolve(); await Vue.nextTick(); await Promise.resolve(); await Vue.nextTick() }
const hashA = 'a'.repeat(64)
const hashB = 'b'.repeat(64)

function mount(get, post = async () => { throw new Error("unexpected mutation") }) {
    const file = path.join(__dirname, '../../src/Review.vue')
    const sfc = compiler.parseComponent(fs.readFileSync(file, 'utf8'))
    const code = babel.transformSync(sfc.script.content, { babelrc: false, configFile: false, plugins: ['@babel/plugin-transform-modules-commonjs'] }).code
    const module = { exports: {} }
    const fakeRequire = name => {
        if (name === './components/ReviewComparison.vue' || name === './components/ReviewMissingFindings.vue' || name === './components/ReviewEvidenceSearch.vue' || name === './ReviewShares.vue' || name === './components/ReviewShares.vue' || name === './components/ReviewChecks.vue' || name === './components/ReviewPlan.vue' || name === './components/ReviewPreview.vue' || name === './ReviewPreview.vue') {
            const child = compiler.parseComponent(fs.readFileSync(path.join(__dirname, '../../src', name.startsWith('./components/') ? name : './components/' + name.slice(2)), 'utf8'))
            const childCode = babel.transformSync(child.script.content, { babelrc: false, configFile: false, plugins: ['@babel/plugin-transform-modules-commonjs'] }).code
            const childMod = { exports: {} }
            new Function('require', 'module', 'exports', childCode)(fakeRequire, childMod, childMod.exports)
            Object.assign(childMod.exports.default, compiler.compileToFunctions(child.template.content))
            childMod.exports.default.methods.t = (_, text) => text
            return childMod.exports
        }
        if (name === '@nextcloud/axios') return { get, post }
        if (name === '@nextcloud/router') return { generateUrl: value => value }
        return require(name)
    }
    new Function('require', 'module', 'exports', code)(fakeRequire, module, module.exports)
    const component = module.exports.default
    Object.assign(component, compiler.compileToFunctions(sfc.template.content))
    const vm = new Vue({ ...component, methods: { ...component.methods, t: (_, text) => text } }).$mount()
    document.body.append(vm.$el)
    return vm
}
function dispose(vm) { vm.$destroy(); vm.$el.remove() }


const member = (id = 1) => ({ id, indexOwner: 'alice', indexPath: '/alice/files/photo.png', owner: 'alice', path: '/alice/files/photo.png', nodeId: id, storageId: 'home::alice', etag: 'old', size: 42, mtime: 1700000000, revisionToken: 'revision-old', availability: 'available' })
const job = (state = 'queued') => ({ jobId: 'a'.repeat(32), state, creator: 'reviewer', createdAt: 1700000000, updatedAt: 1700000001, preview: true, items: [{ appRef: 1, snapshot: { ...member(), appRef: 1 }, status: state === 'completed' ? 'passed' : 'pending', evidenceId: state === 'completed' ? 7 : null }], completedCount: state === 'completed' ? 1 : 0, total: 1, limitations: ['native_revision_not_rechecked'] })
const baseGet = async url => ({ data: url.endsWith('/members') ? { items: [member()], nextCursor: 1 } : { items: [{ hash: hashA, referenceCount: 2 }, { hash: hashB, referenceCount: 2 }], nextCursor: hashA } })

test('explicit check selection keeps the original snapshot across pages and caps references at twenty', async () => {
 const vm = mount(baseGet); await tick(); vm.selectGroup(hashA); await tick()
 const button = vm.$el.querySelector('[data-check-add]'); assert.ok(button, 'each reference must offer explicit check selection')
 button.click(); await tick(); const checks = vm.$refs.checks
 vm.members[0].etag = 'changed'; await vm.loadMembers(1); await vm.selectGroup(hashB); await tick()
 checks.choose({ ...member(), etag: 'new' })
 assert.equal(checks.selection.length, 1); assert.equal(checks.selection[0].expected.etag, 'old')
 for(let id=2;id<=21;id++) checks.choose(member(id))
 assert.equal(checks.selection.length,20); assert.match(checks.error,/20/)
 checks.remove(1); assert.equal(checks.selection.length,19); checks.discard(); assert.equal(checks.selection.length,0)
 dispose(vm)
})

test('failed queue retains the full draft and retry key; submit in flight cannot duplicate or alter it',async()=>{
 let resolvePost; const sent=[]
 const vm=mount(baseGet, async(url,body)=>{sent.push({url,body:JSON.parse(JSON.stringify(body))}); if(sent.length===1)throw Error('offline'); return new Promise(resolve=>{resolvePost=resolve})})
 await tick(); vm.selectGroup(hashA); await tick()
 assert.ok(vm.$refs.checks,'checks component is mounted'); const checks=vm.$refs.checks; checks.choose(vm.members[0]); await checks.submit()
 assert.equal(checks.selection.length,1); assert.ok(checks.error)
 const pending=checks.submit(); await tick(); checks.submit(); checks.discard(); checks.choose(member(2)); checks.remove(1)
 assert.equal(checks.selection.length,1); assert.equal(sent.length,2); assert.equal(checks.$el.querySelector('[data-check-submit]').disabled,true)
 assert.equal(sent[0].body.payload.idempotencyKey,sent[1].body.payload.idempotencyKey)
 assert.deepEqual(sent[1].body.payload.members,[{appRef:1,expected:member()}]); assert.equal(sent[1].body.payload.preview,true)
 assert.equal(sent[1].url,'/apps/duplicatefinder/api/review/checks')
 resolvePost({data:job()}); await pending; await tick()
 assert.equal(checks.record.state,'queued'); assert.match(checks.$el.textContent,/separate worker/); assert.equal(checks.selection.length,0)
 dispose(vm)
})

test('job list, refresh, cancellation and finding load are explicit and preserve plan state',async()=>{
 const calls=[]; const posts=[]
 const vm=mount(async(url,options)=>{
 calls.push([url,options])
 if(url.endsWith('/checks'))return {data:{items:[job()],nextCursor:25}}
 if(url.endsWith('/checks/'+ 'a'.repeat(32)))return {data:job('completed')}
 if(url.endsWith('/evidence'))return {data:{items:[],nextCursor:null}}
 return baseGet(url)
 },async(url,body)=>{posts.push([url,body]);return {data:job('cancelled')}})
 await tick(); assert.equal(calls.length,1); vm.selectGroup(hashA); await tick()
 assert.ok(vm.$refs.checks,'checks component is mounted'); const checks=vm.$refs.checks
 vm.$refs.plan.choose(vm.members[0],'keep'); const before=JSON.stringify(vm.$refs.plan.decisions)
 await checks.loadJobs(0); assert.equal(calls.at(-1)[1].params.limit,25)
 checks.record=job(); await checks.cancel(); assert.equal(posts[0][0],'/apps/duplicatefinder/api/review/checks/'+ 'a'.repeat(32)+'/cancel'); assert.equal(posts[0][1],undefined)
 await checks.openJob('a'.repeat(32)); await tick()
 assert.match(checks.$el.textContent,/Visual content has not been assessed/)
 checks.$el.querySelector('[data-check-evidence]').click(); await tick()
 assert.equal(calls.at(-1)[0],'/apps/duplicatefinder/api/review/members/1/evidence'); assert.equal(JSON.stringify(vm.$refs.plan.decisions),before)
 checks.record=job('interrupted'); await tick(); assert.match(checks.$el.textContent,/not retried automatically/)
 dispose(vm)
})

test('changed preview option uses a new retry identity and conflicts never replace captured revisions',async()=>{
 const sent=[];const vm=mount(baseGet,async(url,body)=>{sent.push(JSON.parse(JSON.stringify(body.payload)));throw {response:{status:409}}})
 await tick();vm.selectGroup(hashA);await tick();const checks=vm.$refs.checks;checks.choose(vm.members[0])
 await checks.submit();checks.preview=false;await checks.submit()
 assert.notEqual(sent[0].idempotencyKey,sent[1].idempotencyKey);assert.equal(sent[1].preview,false)
 assert.equal(checks.selection[0].expected.etag,'old');assert.match(checks.error,/selected revision changed/)
 dispose(vm)
})

test('job status outcomes stay distinct, escape reference paths and do not trigger background requests',async()=>{
 let calls=0;const vm=mount(async url=>{calls++;return baseGet(url)})
 await tick();const checks=vm.$refs.checks;const before=calls
 for(const [status,label] of [['pending',/No confirmed job result/],['passed',/All exposed frames decoded/],['corrupt',/Decoding failed/],['unsupported',/Format not supported/],['inaccessible',/Original could not be read/],['limit',/resource limit/],['stale',/Original changed/],['error',/could not be completed/]]){
  checks.record={...job('completed'),items:[{appRef:1,snapshot:{...member(),indexPath:'/alice/files/<img src=x onerror=alert(1)>'},status}]};await tick()
  assert.match(checks.$el.querySelector('article ol').textContent,label);assert.equal(checks.$el.querySelector('img'),null)
 }
 assert.equal(calls,before);dispose(vm)
})

test('failed job requests leave draft and last known job intact and allow explicit retry',async()=>{
 let failure=true
 const vm=mount(async url=>{if(url.includes('/checks')){if(failure)throw Error('offline');return url.endsWith('/checks')?{data:{items:[job()],nextCursor:null}}:{data:job('running')}};return baseGet(url)},async()=>{throw Error('offline')})
 await tick();const checks=vm.$refs.checks;checks.choose(member());checks.record=job()
 await checks.loadJobs(0);assert.ok(checks.error);assert.equal(checks.selection.length,1)
 await checks.openJob(job().jobId);assert.equal(checks.record.state,'queued');assert.ok(checks.error)
 await checks.cancel();assert.match(checks.error,/could not be confirmed/);assert.equal(checks.record.state,'queued')
 failure=false;await checks.loadJobs(0);assert.equal(checks.error,'');assert.equal(checks.jobs.length,1)
 await checks.openJob(job().jobId);assert.equal(checks.record.state,'running');assert.equal(checks.selection[0].expected.etag,'old')
 dispose(vm)
})

test('native detail selection is explicit, bounded and preserved in the submitted request',async()=>{
 const sent=[];const vm=mount(baseGet,async(url,body)=>{sent.push(body.payload);return {data:job()}})
 await tick();vm.selectGroup(hashA);await tick();const checks=vm.$refs.checks;checks.choose(vm.members[0])
 checks.toggleDetail(checks.selection[0],true);await tick()
 assert.ok(checks.$el.querySelector('[data-detail-frame]'))
 checks.selection[0].detail={frameIndex:1,x:4,y:5,width:16,height:16}
 await checks.submit()
 assert.deepEqual(sent[0].members[0].detail,{frameIndex:1,x:4,y:5,width:16,height:16})
 checks.choose(member());checks.toggleDetail(checks.selection[0],true);checks.selection[0].detail.width=513
 await checks.submit();assert.equal(sent.length,1);assert.ok(checks.error);dispose(vm)
})
test('detail result is explicitly loaded with exact job and artifact binding; other frames are rejected',async()=>{
 const item={appRef:1,snapshot:member(),status:'passed',evidenceId:7,detailId:31,detailStatus:'available',detail:{frameIndex:1,x:4,y:5,width:1,height:1}}
 const bytes=Buffer.alloc(33);Buffer.from([137,80,78,71,13,10,26,10]).copy(bytes);bytes.writeUInt32BE(13,8);bytes.write('IHDR',12);bytes.writeUInt32BE(1,16);bytes.writeUInt32BE(1,20);bytes[24]=8;bytes[25]=2
 const artifact={id:31,appRef:1,evidenceId:7,nativeFreshness:'not_rechecked',validityScope:'original_selected_frame_region',visualAssessment:'not_provided',record:{schemaVersion:1,sha256:'a'.repeat(64),descriptor:{status:'available',scope:'original_selected_frame_region',mime:'image/png',frameIndex:1,sourceWidth:1000,sourceHeight:800,region:{x:4,y:5,width:1,height:1},width:1,height:1,rasterMode:'RGB',imageBase64:bytes.toString('base64')}}}
 let requests=0
 const vm=mount(async(url)=>{if(url.includes('/details/')){requests++;return {data:artifact}}return baseGet(url)})
 await tick();const checks=vm.$refs.checks;checks.record={...job('completed'),items:[item]};await tick()
 assert.equal(requests,0);const load=checks.$el.querySelector('[data-detail-load]');assert.ok(load);load.click();await tick()
 assert.equal(requests,1);assert.ok(checks.$el.querySelector('[data-detail-image]'));assert.match(checks.$el.textContent,/1000/)
 const assessment=checks.$el.querySelector('[data-detail-assess-visible]')
 assert.ok(assessment);assert.equal(assessment.disabled,true)
 checks.$el.querySelector('[data-detail-image]').dispatchEvent(new window.Event('load'));await tick()
 assert.equal(assessment.disabled,false)
 let received;checks.$on('assessed-detail',value=>{received=value})
 assessment.click();await tick()
 assert.equal(received.source.kind,'original_detail');assert.equal(received.source.detailId,31)
 assert.equal(received.source.frameIndex,1);assert.deepEqual(received.source.region,{x:4,y:5,width:1,height:1})
 const second={...item,appRef:2,detailId:32};checks.record.items.push(second)
 checks.$set(checks.detailImages,checks.detailKey(second),{...checks.detailImages[checks.detailKey(item)]})
 checks.setDetailNotice(received,'Assessment copied only here');await tick()
 assert.equal(checks.$el.textContent.split('Assessment copied only here').length-1,1)

 checks.detailFailed(item);await tick();received=null;checks.assessDetail(item,'content_visible');assert.equal(received,null)

 artifact.record.descriptor.frameIndex=0;await checks.loadDetail(item);await tick()
 assert.equal(checks.detailImages[checks.detailKey(item)],undefined);assert.equal(checks.$el.querySelectorAll('[data-detail-image]').length,1);assert.ok(checks.error);dispose(vm)
})

test('content choice submits no decoder artifacts and retains image settings in the draft', async()=>{
 const sent=[];const vm=mount(baseGet,async(url,body)=>{sent.push(body.payload);throw Error('offline')});await tick()
 const c=vm.$refs.checks;c.choose(member());c.toggleDetail(c.selection[0],true);await tick()
 const select=c.$el.querySelector('[data-check-kind]');assert.ok(select)
 select.value='content';select.dispatchEvent(new window.Event('change',{bubbles:true}));await tick()
 await c.submit();assert.equal(sent[0].kind,'content');assert.equal(sent[0].preview,false)
 assert.equal(sent[0].members[0].detail,undefined);assert.ok(c.selection[0].detail)
 assert.equal(c.$el.querySelector('[data-detail-frame]'),null)
 dispose(vm)
})
test('content finding load binds the exact historical ID and displays hash without decode approval',async()=>{
 const saved=job('completed');saved.kind='content';saved.items[0]={appRef:1,snapshot:saved.items[0].snapshot,status:'read',contentEvidenceId:23}
 const finding={id:23,appRef:1,createdAt:1700000000,usability:'unverifiable',actionEligible:false,record:{report:{status:'read',algorithm:'sha256',digest:'b'.repeat(64),formatStatus:'not_checked',actionEligible:false,before:saved.items[0].snapshot,after:saved.items[0].snapshot}}}
 const calls=[];const vm=mount(async(url,options)=>{if(url.includes('/content-evidence')){calls.push(options);return{data:{items:[finding],nextCursor:null}}}return baseGet(url)});await tick()
 const c=vm.$refs.checks;c.record=saved;await tick()
 const button=c.$el.querySelector('[data-content-load]');assert.ok(button);button.click();await tick()
 assert.deepEqual(calls[0].params,{cursor:24,pageSize:1})
 assert.match(c.$el.textContent,/b{64}/);assert.match(c.$el.textContent,/File format was not checked/)
 finding.id=22;await c.loadContent(c.record.items[0]);await tick()
 assert.ok(c.error);assert.doesNotMatch(c.$el.textContent,/b{64}/)
 dispose(vm)
})

test('interrupted content retry requires history inspection and queues an explicit new selection',async()=>{
 const current={...member(),owner:'alice',path:'/alice/files/a',storageId:'home::alice',nodeId:11,etag:'v1',size:4,mtime:10}
 const snapshot={...current,appRef:1};delete snapshot.id
 const old={...job('interrupted'),kind:'content',preview:false,items:[{appRef:1,snapshot,status:'pending'}]}
 const calls=[];const vm=mount(async(url,options)=>{
  calls.push([url,options]);if(url.includes('/content-evidence'))return {data:{items:[{id:23,appRef:1,createdAt:1,record:{report:{status:'read',digest:'b'.repeat(64)}}}],nextCursor:null}}
  if(url.endsWith('/references/1'))return {data:current};return baseGet(url)
 });await tick();const c=vm.$refs.checks;c.record=old;await tick();const item=c.record.items[0]
 assert.ok(c.$el.querySelector('[data-retry-history]'))
 await c.prepareRetry(item);assert.equal(c.selection.length,0)
 await c.loadRetryHistory(item,0);await tick()
 assert.match(c.$el.textContent,/b{64}/);assert.match(c.$el.textContent,/Pending does not mean/)
 await c.prepareRetry(item);assert.equal(c.selection.length,1);assert.equal(c.kind,'content')
 assert.equal(c.selection[0].expected.etag,'v1');assert.equal(c.record.state,'interrupted');assert.equal(c.record.items[0].status,'pending')
 assert.deepEqual(calls.find(([url])=>url.includes('/content-evidence'))[1].params,{cursor:0,pageSize:25})
 dispose(vm)
})

test('retry rejects changed metadata and cannot overwrite a different check selection',async()=>{
 const current={...member(),owner:'alice',path:'/alice/files/a',storageId:'home::alice',nodeId:11,etag:'v2',size:4,mtime:10}
 const snapshot={...current,appRef:1,etag:'v1'};delete snapshot.id
 const vm=mount(async(url)=>url.includes('/content-evidence')?{data:{items:[],nextCursor:null}}:url.endsWith('/references/1')?{data:current}:baseGet(url))
 await tick();const c=vm.$refs.checks;c.record={...job('interrupted'),kind:'content',preview:false,items:[{appRef:1,snapshot,status:'pending'}]};await tick()
 const item=c.record.items[0];await c.loadRetryHistory(item,0);await c.prepareRetry(item)
 assert.equal(c.selection.length,0);assert.match(c.error,/changed/)
 current.etag='v1';c.choose({...current,id:2});c.kind='image'
 await c.prepareRetry(item);assert.equal(c.selection.length,1);assert.equal(c.selection[0].appRef,2);assert.equal(c.kind,'image')
 dispose(vm)
})

test('malformed history never enables retry or leaves a broken render record',async()=>{
 const vm=mount(async(url)=>url.includes('/content-evidence')?{data:{items:[{id:23,appRef:1,createdAt:1}],nextCursor:null}}:baseGet(url))
 await tick();const c=vm.$refs.checks;c.record={...job('interrupted'),kind:'content'};await tick()
 await c.loadRetryHistory(c.record.items[0],0)
 assert.equal(c.retryHistory[1],undefined);assert.match(c.error,/could not be loaded/)
 dispose(vm)
})

test('retry history rejects malformed rendered fields for image and content jobs',async()=>{
 for(const kind of ['image','content']) {
  const valid={id:23,appRef:1,createdAt:1,record:{report:{status:'read'}}}
  const invalid=[null,{...valid,createdAt:'yesterday'},{...valid,record:[]},{...valid,record:{report:[]}},{...valid,record:{report:{status:{}}}}]
  if(kind==='content')invalid.push({...valid,record:{report:{status:'read',digest:'invalid'}}})
  let entries=[valid]
  const vm=mount(async(url)=>url.endsWith('/evidence')||url.endsWith('/content-evidence')?{data:{items:entries,nextCursor:null}}:baseGet(url))
  await tick();const c=vm.$refs.checks;c.record={...job('interrupted'),kind};await tick()
  await c.loadRetryHistory(c.record.items[0],0);await tick();assert.ok(c.retryHistory[1])
  for(const entry of invalid){
   entries=[entry];await c.loadRetryHistory(c.record.items[0],0);await tick()
   assert.equal(c.retryHistory[1],undefined);assert.match(c.error,/could not be loaded/)
   assert.equal(c.$el.querySelector('[data-prepare-retry]').disabled,true)
   await c.prepareRetry(c.record.items[0]);assert.equal(c.selection.length,0)
  }
  dispose(vm)
 }
})
