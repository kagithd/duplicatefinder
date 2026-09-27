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
        if (name === './components/ReviewEvidenceSearch.vue' || name === './ReviewShares.vue' || name === './components/ReviewShares.vue' || name === './components/ReviewChecks.vue' || name === './components/ReviewPlan.vue' || name === './components/ReviewPreview.vue' || name === './ReviewPreview.vue') {
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

 test('renders bounded groups and escaped complete reference paths only after selection', async () => {
    const requests = []
    const vm = mount(async (url, options) => {
        requests.push([url, options])
        if (!url.endsWith('/members')) return { data: { items: [{ hash: hashA, referenceCount: 2 }], nextCursor: hashA } }
        return { data: { items: [{ id: 9, indexOwner: 'alice', indexPath: '/alice/files/<img src=x onerror=alert(1)>/file', availability: 'unavailable', integrity: 'not_checked' }], nextCursor: null } }
    })
    await tick()
    assert.equal(requests.length, 1)
    vm.$el.querySelector('[data-group]').click()
    await tick()
    assert.equal(requests.length, 2)
    assert.match(vm.$el.textContent, /alice/)
    assert.match(vm.$el.textContent, /<img src=x onerror=alert\(1\)>/)
    assert.equal(vm.$el.querySelector('img'), null)
    assert.match(vm.$el.textContent, /Not checked/)
    assert.equal(requests[1][1].params.limit, 50)
    dispose(vm)
})

test('ignores an old member response after selecting a different group', async () => {
    let resolveOld
    const vm = mount(async url => {
        if (!url.endsWith('/members')) return { data: { items: [{ hash: hashA, referenceCount: 2 }, { hash: hashB, referenceCount: 2 }], nextCursor: null } }
        if (url.includes(hashA)) return new Promise(resolve => { resolveOld = resolve })
        return { data: { items: [{ id: 2, indexOwner: 'current', indexPath: '/current/files/b', availability: 'unavailable' }], nextCursor: null } }
    })
    await tick()
    vm.$el.querySelectorAll('[data-group]')[0].click()
    await tick()
    vm.$el.querySelectorAll('[data-group]')[1].click()
    await tick()
    resolveOld({ data: { items: [{ id: 1, indexOwner: 'obsolete', indexPath: '/obsolete/files/a' }], nextCursor: null } })
    await tick()
    assert.match(vm.$el.textContent, /current/)
    assert.doesNotMatch(vm.$el.textContent, /obsolete/)
    dispose(vm)
})

test('next group page replaces the previous page and clears selected members', async () => {
    const vm = mount(async (url, options) => {
        if (url.endsWith('/members')) return { data: { items: [{ id: 1, indexOwner: 'alice', indexPath: '/alice/files/old' }], nextCursor: null } }
        return { data: options.params.cursor ? { items: [{ hash: hashB, referenceCount: 2 }], nextCursor: null } : { items: [{ hash: hashA, referenceCount: 2 }], nextCursor: hashA } }
    })
    await tick()
    vm.$el.querySelector('[data-group]').click()
    await tick()
    vm.$el.querySelector('[data-next-groups]').click()
    await tick()
    assert.equal(vm.$el.querySelectorAll('[data-group]').length, 1)
    assert.match(vm.$el.textContent, new RegExp(hashB))
    assert.doesNotMatch(vm.$el.textContent, /\/alice\/files\/old/)
    dispose(vm)
})

test('shows a retryable failure and distinguishes an empty successful page', async () => {
    let calls = 0
    const vm = mount(async () => {
        if (++calls === 1) throw new Error('unavailable')
        return { data: { items: [], nextCursor: null } }
    })
    await tick()
    assert.ok(vm.$el.querySelector('[role="alert"]'))
    vm.$el.querySelector('[data-retry-groups]').click()
    await tick()
    assert.equal(vm.$el.querySelector('[role="alert"]'), null)
    assert.match(vm.$el.textContent, /No indexed groups/)
    dispose(vm)
})

test('member failure can be retried and later pages replace earlier references', async () => {
    let membersCalls = 0
    const vm = mount(async (url, options) => {
        if (!url.endsWith('/members')) return { data: { items: [{ hash: hashA, referenceCount: 100 }], nextCursor: null } }
        if (++membersCalls === 1) throw new Error('metadata timeout')
        return { data: options.params.cursor ? { items: [{ id: 51, indexOwner: 'second', indexPath: '/second/files/b' }], nextCursor: null } : { items: [{ id: 50, indexOwner: 'first', indexPath: '/first/files/a' }], nextCursor: 50 } }
    })
    await tick()
    vm.$el.querySelector('[data-group]').click()
    await tick()
    assert.ok(vm.$el.querySelector('[data-retry-members]'))
    vm.$el.querySelector('[data-retry-members]').click()
    await tick()
    assert.match(vm.$el.textContent, /\/first\/files\/a/)
    vm.$el.querySelector('[data-next-members]').click()
    await tick()
    assert.doesNotMatch(vm.$el.textContent, /\/first\/files\/a/)
    assert.match(vm.$el.textContent, /\/second\/files\/b/)
    assert.equal(vm.$el.querySelector('[data-next-members]').disabled, true)
    dispose(vm)
})

test('loading state prevents page requests and delayed responses cannot repopulate cleared members', async () => {
    let resolveMembers
    const vm = mount(async (url, options) => {
        if (url.endsWith('/members')) return new Promise(resolve => { resolveMembers = resolve })
        return { data: options.params.cursor ? { items: [], nextCursor: null } : { items: [{ hash: hashA, referenceCount: 2 }], nextCursor: hashA } }
    })
    await tick()
    vm.$el.querySelector('[data-group]').click()
    await tick()
    assert.equal(vm.$el.querySelector('[data-next-members]').disabled, true)
    assert.match(vm.$el.querySelector('[role="status"]').textContent, /Loading references/)
    vm.$el.querySelector('[data-next-groups]').click()
    await tick()
    resolveMembers({ data: { items: [{ id: 7, indexOwner: 'late', indexPath: '/late/files/a' }], nextCursor: null } })
    await tick()
    assert.doesNotMatch(vm.$el.textContent, /\/late\/files\/a/)
    assert.equal(vm.$el.querySelector('[data-next-members]'), null)
    dispose(vm)
})

const evidenceEntry = (status = 'passed', usability = 'unverifiable') => ({
    id: 7, appRef: 9, createdAt: 1700000000,
    record: { report: { status, format: 'PNG', decoder: { name: 'Pillow', version: '10.2.0' }, frames_decoded: 3, scope: 'original_all_exposed_frames', content_judgment: 'not_assessed' } },
    usability, reason: 'native_revision_not_rechecked',
    nativeFreshness: 'not_rechecked', validityScope: 'nextcloud_metadata_only',
})
function responseForReview(url, evidence) {
    if (url.endsWith('/evidence')) return evidence()
    if (url.endsWith('/members')) return Promise.resolve({ data: { items: [{ id: 9, indexOwner: 'alice', indexPath: '/alice/files/photo.png' }], nextCursor: null } })
    return Promise.resolve({ data: { items: [{ hash: hashA, referenceCount: 2 }], nextCursor: hashA } })
}

test('loads only requested latest historical evidence without claiming current integrity', async () => {
    const calls = []
    const vm = mount((url, options) => {
        calls.push([url, options])
        return responseForReview(url, () => Promise.resolve({ data: { items: [evidenceEntry()], nextCursor: null } }))
    })
    await tick()
    vm.$el.querySelector('[data-group]').click()
    await tick()
    assert.equal(calls.length, 2)
    vm.$el.querySelector('[data-load-evidence]').click()
    await tick()
    assert.equal(calls.length, 3)
    assert.equal(calls[2][0], '/apps/duplicatefinder/api/review/members/9/evidence')
    assert.equal(calls[2][1].params.limit, 1)
    const panel = vm.$el.querySelector('[data-evidence]')
    assert.match(panel.textContent, /Historical finding/)
    assert.match(panel.textContent, /All exposed frames decoded/)
    assert.match(panel.textContent, /Original revision has not been rechecked/)
    assert.match(panel.textContent, /3/)
    assert.match(panel.textContent, /10.2.0/)
    assert.match(panel.textContent, /Visual content has not been assessed/)
    dispose(vm)
})

test('does not reuse late evidence after changing the group page', async () => {
    let resolveEvidence
    const vm = mount(url => responseForReview(url, () => new Promise(resolve => { resolveEvidence = resolve })))
    await tick()
    vm.$el.querySelector('[data-group]').click()
    await tick()
    vm.$el.querySelector('[data-load-evidence]').click()
    await tick()
    vm.$el.querySelector('[data-next-groups]').click()
    await tick()
    resolveEvidence({ data: { items: [evidenceEntry()], nextCursor: null } })
    await tick()
    assert.equal(vm.$el.querySelector('[data-evidence]'), null)
    dispose(vm)
})

test('evidence load failure retries and an empty history stays unchecked', async () => {
    let count = 0
    const vm = mount(url => responseForReview(url, async () => {
        if (++count === 1) throw new Error('offline')
        return { data: { items: [], nextCursor: null } }
    }))
    await tick()
    vm.$el.querySelector('[data-group]').click()
    await tick()
    vm.$el.querySelector('[data-load-evidence]').click()
    await tick()
    assert.match(vm.$el.querySelector('[data-evidence]').textContent, /Finding could not be loaded/)
    vm.$el.querySelector('[data-load-evidence]').click()
    await tick()
    assert.match(vm.$el.querySelector('[data-evidence]').textContent, /No saved finding/)
    assert.doesNotMatch(vm.$el.querySelector('[data-evidence]').textContent, /All exposed frames decoded/)
    dispose(vm)
})

test('displays damaged, unsupported and stale findings without changing their meaning', async () => {
    for (const [status, usability, expected] of [
        ['corrupt', 'unverifiable', /Decoding failed/],
        ['unsupported', 'unverifiable', /Format not supported/],
        ['limit', 'unverifiable', /Check stopped at a resource limit/],
        ['passed', 'stale', /File metadata changed since this check/],
        ['passed', 'checker_outdated', /Checker or decoder version changed/],
    ]) {
        const vm = mount(url => responseForReview(url, () => Promise.resolve({ data: { items: [evidenceEntry(status, usability)], nextCursor: null } })))
        await tick()
        vm.$el.querySelector('[data-group]').click()
        await tick()
        vm.$el.querySelector('[data-load-evidence]').click()
        await tick()
        assert.match(vm.$el.querySelector('[data-evidence]').textContent, expected)
        dispose(vm)
    }
})

test('explicit plan selections survive member pages and group changes without expanding the selection', async () => {
    const submissions = []
    const vm = mount(async (url, options) => {
        if (url.endsWith('/members')) return { data: { items: options.params.cursor ? [{ id: 2, indexOwner: 'bob', indexPath: '/bob/files/b', etag: 'second' }] : [{ id: 1, indexOwner: 'alice', indexPath: '/alice/files/a', etag: 'first' }], nextCursor: options.params.cursor ? null : 1 } }
        return { data: { items: [{ hash: hashA, referenceCount: 3 }, { hash: hashB, referenceCount: 2 }], nextCursor: null } }
    }, async (url, body) => { submissions.push(body.payload); throw new Error('network interrupted') })
    await tick()
    vm.$el.querySelector('[data-group]').click(); await tick()
    vm.$el.querySelector('[data-plan-keep]').click(); await tick()
    vm.$el.querySelector('[data-next-members]').click(); await tick()
    vm.$el.querySelector('[data-plan-remove]').click(); await tick()
    const plan = vm.$refs.plan
    assert.deepEqual(plan.decisions.map(d => d.appRef), [1, 2])
    vm.$el.querySelectorAll('[data-group]')[1].click(); await tick()
    vm.$el.querySelector('[data-plan-keep]').click(); await tick()
    assert.equal(plan.draftHash, hashA)
    assert.equal(plan.decisions.length, 2)
    assert.match(plan.error, /another group/)
    await plan.save(); await plan.save()
    assert.equal(submissions[0].idempotencyKey, submissions[1].idempotencyKey)
    assert.equal(submissions[0].members[0].expected.etag, 'first')
    plan.note = 'new note'; await plan.save()
    assert.notEqual(submissions[1].idempotencyKey, submissions[2].idempotencyKey)
    dispose(vm)
})

test('preview control appears only after explicitly loading a passed finding and fetches its exact bound artifact', async () => {
    const calls = []
    const vm = mount(async url => {
        calls.push(url)
        if (url.endsWith('/preview')) return { data: { id: 1, appRef: 9, evidenceId: 7, createdAt: 1700000000, record: { schemaVersion: 1, sha256: 'a'.repeat(64), descriptor: { status: 'available', scope: 'original_first_frame_scaled', mime: 'image/jpeg', frameIndex: 0, width: 100, height: 50, imageBase64: Buffer.from([255,216,255,224,1,2,255,217]).toString('base64') } }, nativeFreshness: 'not_rechecked', validityScope: 'original_first_frame_scaled', visualAssessment: 'not_provided' } }
        return responseForReview(url, () => Promise.resolve({ data: { items: [evidenceEntry()], nextCursor: null } }))
    })
    await tick(); vm.$el.querySelector('[data-group]').click(); await tick()
    assert.equal(vm.$el.querySelector('[data-load-preview]'), null)
    vm.$el.querySelector('[data-load-evidence]').click(); await tick()
    assert.ok(vm.$el.querySelector('[data-load-preview]'))
    assert.equal(calls.length, 3)
    vm.$el.querySelector('[data-plan-keep]').click(); await tick()
    const decisions = JSON.stringify(vm.$refs.plan.decisions)
    vm.$el.querySelector('[data-load-preview]').click(); await tick()
    assert.equal(calls[3], '/apps/duplicatefinder/api/review/members/9/evidence/7/preview')
    assert.ok(vm.$el.querySelector('img'))
    assert.equal(JSON.stringify(vm.$refs.plan.decisions), decisions)
    vm.$el.querySelector('[data-load-evidence]').click(); await tick()
    assert.equal(vm.$el.querySelector('img'), null)
    dispose(vm)
})

test('failed technical findings never expose a preview control', async () => {
    for (const status of ['corrupt', 'unsupported', 'limit', 'stale', 'error']) {
        const vm = mount(url => responseForReview(url, () => Promise.resolve({ data: { items: [evidenceEntry(status)], nextCursor: null } })))
        await tick(); vm.$el.querySelector('[data-group]').click(); await tick()
        vm.$el.querySelector('[data-load-evidence]').click(); await tick()
        assert.equal(vm.$el.querySelector('[data-load-preview]'), null)
        dispose(vm)
    }
})

test('a pending preview cannot return after its parent changes the group page', async () => {
    let resolvePreview
    const vm = mount(url => {
        if (url.endsWith('/preview')) return new Promise(resolve => { resolvePreview = resolve })
        return responseForReview(url, () => Promise.resolve({ data: { items: [evidenceEntry()], nextCursor: null } }))
    })
    await tick(); vm.$el.querySelector('[data-group]').click(); await tick()
    vm.$el.querySelector('[data-load-evidence]').click(); await tick()
    vm.$el.querySelector('[data-load-preview]').click(); await tick()
    vm.$el.querySelector('[data-next-groups]').click(); await tick()
    resolvePreview({ data: { appRef: 9, evidenceId: 7 } }); await tick()
    assert.equal(vm.$el.querySelector('img'), null)
    assert.equal(vm.$el.querySelector('[data-load-preview]'), null)
    dispose(vm)
})

test('plan preview requires an explicit loaded-image assessment and persists its exact source',async()=>{
 let submitted
 const vm=mount(async url=>{
  if(url.endsWith('/preview'))return {data:{id:1,appRef:9,evidenceId:7,createdAt:1700000000,record:{schemaVersion:1,sha256:'a'.repeat(64),descriptor:{status:'available',scope:'original_first_frame_scaled',mime:'image/jpeg',frameIndex:0,width:100,height:50,imageBase64:Buffer.from([255,216,255,224,1,2,255,217]).toString('base64')}},nativeFreshness:'not_rechecked',validityScope:'original_first_frame_scaled',visualAssessment:'not_provided'}}
  return responseForReview(url,()=>Promise.resolve({data:{items:[evidenceEntry()],nextCursor:null}}))
 },async(url,body)=>{submitted=body.payload;return {data:{...body.payload,planId:'x',revision:1,creator:'reviewer',executable:false}}})
 await tick();vm.$el.querySelector('[data-group]').click();await tick()
 vm.$el.querySelector('[data-load-evidence]').click();await tick()
 vm.$el.querySelector('[data-plan-keep]').click();await tick()
 const plan=vm.$refs.plan;plan.$el.querySelector('[data-load-preview]').click();await tick()
 assert.equal(plan.decisions[0].manualAssessment.status,'not_assessed')
 const img=plan.$el.querySelector('img');Object.defineProperty(img,'naturalWidth',{value:100});img.dispatchEvent(new window.Event('load'));await tick()
 plan.$el.querySelector('[data-assess-visible]').click();await tick()
 assert.equal(plan.decisions[0].manualAssessment.status,'content_visible')
 assert.doesNotMatch(plan.$el.textContent,/Visual content has not been assessed/)
 const note=plan.$el.querySelector('li textarea');note.value='Subject visible';note.dispatchEvent(new window.Event('input'));await tick()
 await plan.save();assert.equal(submitted.members[0].manualAssessment.note,'Subject visible')
 assert.equal(submitted.members[0].manualAssessment.source.previewId,1)
 assert.equal(submitted.members[0].manualAssessment.source.evidenceId,7)
 assert.equal(submitted.members[0].manualAssessment.source.scope,'original_first_frame_scaled')
 dispose(vm)
})
test('saved sharing review displays historical rights and distinguishes unknown recipient identity', async () => {
 const vm = mount(async () => ({data:{items:[],nextCursor:null}}))
 await tick()
 vm.$refs.plan.record = {planId:'synthetic',revision:1,creator:'reviewer',createdAt:1700000000,hash:hashA,members:[{appRef:9,action:'keep',sharing:{pages:[{query:{depth:1,type:0,offset:0},page:{anchor:{path:'/alice/files/shared'},observedAt:1700000000,items:[{id:1,recipient:'bob',recipientPath:'/bob/files/<shared>/photo.png',permissions:1,effectivePermissions:1,deletable:false,pathStatus:'observed',expiration:null,status:0},{id:2,recipient:'unknown',recipientPath:null,permissions:31,effectivePermissions:null,deletable:null,pathStatus:'unverifiable',expiration:null,status:0}]}}]}}]}
 await tick()
 const saved = vm.$el.querySelector('[data-saved-sharing]')
 assert.match(saved.textContent,/\/bob\/files\/<shared>\/photo.png/)
 assert.equal(saved.querySelector('shared'),null)
 assert.match(saved.textContent,/Not allowed at observation time/)
 assert.match(saved.textContent,/Recipient identity verified at observation time/)
 assert.match(saved.textContent,/Recipient path could not be verified/)
 assert.match(saved.textContent,/Not determined/)
 assert.match(saved.textContent,/Recorded share status/)
 assert.match(vm.$el.querySelector('[data-saved-summary]').textContent,/unobserved sharing consequences remain unknown/)
 dispose(vm)
})
test('scope filters apply explicitly, reset cursor, preserve drafts and ignore late group responses', async () => {
 const calls=[]; let release
 const vm=mount(async(url,options)=>{
  calls.push({...options.params})
  if(options.params.owner==='alice') return new Promise(resolve=>{release=resolve})
  return {data:{items:[{hash:hashA,referenceCount:2}],nextCursor:hashA}}
 })
 await tick()
 vm.$refs.plan.choose({id:7,indexOwner:'alice',indexPath:'/alice/files/a'},'keep',null)
 const draft=JSON.stringify(vm.$refs.plan.decisions)
 const owner=vm.$el.querySelector('[data-filter-owner]'); assert.ok(owner)
 owner.value='alice';owner.dispatchEvent(new window.Event('input'))
 const folder=vm.$el.querySelector('[data-filter-folder]');folder.value='/alice/files/photos';folder.dispatchEvent(new window.Event('input'));await tick()
 assert.equal(calls.length,1)
 vm.$el.querySelector('[data-apply-filters]').click();await tick()
 assert.deepEqual(calls[1],{cursor:'',limit:25,owner:'alice',folder:'/alice/files/photos'})
 vm.$el.querySelector('[data-reset-filters]').click();await tick()
 assert.equal(calls[2].owner,'');assert.equal(calls[2].folder,'')
 release({data:{items:[{hash:hashB,referenceCount:2}],nextCursor:null}});await tick()
 assert.equal(vm.groups[0].hash,hashA)
 assert.equal(JSON.stringify(vm.$refs.plan.decisions),draft)
 assert.match(vm.$el.textContent,/Other copies in matching groups remain visible/)
 dispose(vm)
})

test('opening a finding resolves current reference and member page without changing a retained draft', async () => {
    const calls=[];
    const vm=mount(async (url,options)=>{
        calls.push([url,options]);
        if(url.endsWith('/references/77'))return {data:{id:77,candidateHash:hashB,indexPath:'/alice/files/current.png'}};
        if(url.endsWith('/members'))return {data:{items:[{id:77,indexOwner:'alice',indexPath:'/alice/files/current.png',availability:'available',etag:'new'}],nextCursor:null}};
        return {data:{items:[],nextCursor:null}};
    });
    await tick();vm.$refs.plan.note='Retained review note';
    await vm.openEvidenceReference(77);await tick();
    assert.equal(vm.selectedHash,hashB);assert.equal(vm.members[0].etag,'new');
    assert.equal(calls.find(x=>x[0].endsWith('/members'))[1].params.cursor,76);
    assert.equal(vm.$refs.plan.note,'Retained review note');assert.equal(vm.$refs.checks.selection.length,0);
    dispose(vm);
});
test('late finding navigation cannot overwrite a newer manual group selection', async () => {
    let resolve;
    const vm=mount((url)=>url.endsWith('/references/77')?new Promise(r=>resolve=r):Promise.resolve({data:{items:[],nextCursor:null}}));
    await tick();const pending=vm.openEvidenceReference(77);vm.selectGroup(hashA);await tick();
    resolve({data:{id:77,candidateHash:hashB}});await pending;await tick();assert.equal(vm.selectedHash,hashA);dispose(vm);
});
test('finding button opens current data and reports a vanished reference without changing the plan', async () => {
 const vm=mount(async(url)=>{
  if(url.endsWith('/references/77'))return {data:{id:77,candidateHash:hashB}};
  return {data:{items:[],nextCursor:null}};
 });
 await tick();
 const search=vm.$children.find(child=>child.$options.name==='ReviewEvidenceSearch');
 search.page={items:[{id:1,appRef:77,historicalStatus:'corrupt'}],metadataComplete:true,nextCursor:null};
 vm.$refs.plan.note='Keep draft';
 await tick();search.$el.querySelector('[data-open-reference]').click();await tick();
 assert.match(vm.referenceError,/could not be opened/);
 assert.equal(vm.openedReference,null);assert.equal(vm.referenceLoading,false);
 assert.equal(vm.$refs.plan.note,'Keep draft');dispose(vm);
});
test('late member navigation cannot overwrite a newer selection', async () => {
 let release;
 const vm=mount(async(url)=>{
  if(url.endsWith('/references/77'))return {data:{id:77,candidateHash:hashB}};
  if(url.includes(hashB))return new Promise(resolve=>{release=resolve});
  return {data:{items:[],nextCursor:null}};
 });
 await tick();const pending=vm.openEvidenceReference(77);await tick();
 vm.selectGroup(hashA);await tick();
 release({data:{items:[{id:77}],nextCursor:null}});await pending;await tick();
 assert.equal(vm.selectedHash,hashA);assert.equal(vm.members.length,0);
 assert.equal(vm.openedReference,null);assert.equal(vm.referenceError,'');dispose(vm);
});

test('finding origin remains visible while paging the same group and clears on group change', async () => {
 const vm=mount(async(url)=>{
  if(url.endsWith('/references/77'))return {data:{id:77,candidateHash:hashB}};
  return {data:{items:[{id:77}],nextCursor:null}};
 });
 await tick();await vm.openEvidenceReference(77);
 await vm.loadMembers(0);await tick();
 assert.equal(vm.openedReference,77);
 vm.selectGroup(hashA);await tick();assert.equal(vm.openedReference,null);dispose(vm);
});
