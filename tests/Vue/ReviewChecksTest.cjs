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
 for(const [status,label] of [['pending',/Not checked/],['passed',/All exposed frames decoded/],['corrupt',/Decoding failed/],['unsupported',/Format not supported/],['inaccessible',/Original could not be read/],['limit',/resource limit/],['stale',/Original changed/],['error',/could not be completed/]]){
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
