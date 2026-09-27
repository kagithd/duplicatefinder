const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const compiler = require('vue-template-compiler')
const babel = require('@babel/core')
function component(axios) {
 const sfc = compiler.parseComponent(fs.readFileSync('src/components/ReviewPlan.vue', 'utf8'))
 const code = babel.transformSync(sfc.script.content, {babelrc:false, configFile:false, plugins:['@babel/plugin-transform-modules-commonjs']}).code
 const mod = {exports:{}}
 new Function('require','module','exports',code)(n => n === '@nextcloud/axios' ? axios : n === '@nextcloud/router' ? {generateUrl:x=>x} : (n==='./ReviewPreview.vue'||n==='./ReviewShares.vue')?{render:h=>h('div')}:require(n),mod,mod.exports)
 const c = mod.exports.default
 const vm = {...c.data(), t:(_,s)=>s, $set:(o,k,v)=>{o[k]=v}, hash:'a'.repeat(64)}
 for(const [k,v] of Object.entries(c.methods)) vm[k]=v.bind(vm)
 return vm
}
test('captures explicit references across pages and preserves metadata and retry identity', async()=>{
 const calls=[]
 const vm=component({post:async(u,p)=>{calls.push(JSON.parse(JSON.stringify(p.payload)));throw {response:{status:409}}}})
 const m={id:1,indexOwner:'alice',indexPath:'/alice/files/a',etag:'old'}
 vm.choose(m,'keep',{entry:{id:7}});m.etag='new'
 vm.choose({id:2,indexOwner:'bob',indexPath:'/bob/files/b'},'remove',null)
 await vm.save();await vm.save()
 assert.equal(calls[0].members[0].expected.etag,'old')
 assert.deepEqual(calls[0].members[0].evidenceIds,[7])
 assert.equal(calls[0].members[0].manualAssessment.status,'not_assessed')
 assert.equal(calls[0].idempotencyKey,calls[1].idempotencyKey)
 assert.match(vm.error,/changed|conflict/i)
 assert.equal(vm.decisions.length,2)
})
test('bound selection and revision edits keep expected predecessor',async()=>{
 let payload
 const vm=component({post:async(u,p)=>{payload=p.payload;return {data:{planId:4,revision:3,members:payload.members,hash:payload.hash}}}})
 for(let i=1;i<=101;i++)vm.choose({id:i},'exclude',null)
 assert.equal(vm.decisions.length,100)
 vm.record={planId:4,revision:2,hash:vm.hash,members:[{appRef:1,action:'keep',expected:{id:1},evidenceIds:[],manualAssessment:{status:'not_assessed',note:''},reason:''}],note:''}
 vm.editRevision();await vm.save()
 assert.equal(payload.expectedPredecessor,2)
 assert.deepEqual(payload.indexActions,[])
})
const { JSDOM } = require('jsdom')
const dom = new JSDOM('<!doctype html><html><body></body></html>')
global.window=dom.window;global.document=dom.window.document;global.navigator=dom.window.navigator
const Vue=require('vue/dist/vue.common.js')
Vue.config.productionTip=false;Vue.config.devtools=false
async function tick(){await Promise.resolve();await Vue.nextTick();await Promise.resolve();await Vue.nextTick()}
test('renders escaped selected owners and paths and reviews an exact immutable revision',async()=>{
 const sfc=compiler.parseComponent(fs.readFileSync('src/components/ReviewPlan.vue','utf8'))
 const code=babel.transformSync(sfc.script.content,{babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code
 const mod={exports:{}}
 const calls=[]
 const record={planId:'x',revision:2,creator:'reviewer',createdAt:1,hash:'a'.repeat(64),state:'decision_draft',executable:false,note:'saved',indexActions:[],members:[
 {appRef:41,action:'keep',observed:{owner:'owner-a',indexOwner:'recipient-b',indexPath:'/recipient-b/files/<img src=x>',path:'/recipient-b/files/shared.png',nodeId:12,storageId:'home::owner-a'},reason:'Best copy',manualAssessment:{status:'content_visible',note:'Blue frame',source:{previewId:5,evidenceId:7,scope:'original_first_frame_scaled'}},evidence:[{id:7,createdAt:1,record:{report:{status:'passed',scope:'original_all_exposed_frames',frames_decoded:2,reason:'decoded',checker:{id:'image',version:'1',ruleVersion:'1'}}}}]},
 {appRef:42,action:'remove',observed:{owner:'owner-b',indexOwner:'owner-b',indexPath:'/owner-b/files/copy.png'},manualAssessment:{status:'not_assessed',note:''},evidence:[]}
 ]}
 new Function('require','module','exports',code)(n=>n==='@nextcloud/axios'?{get:async url=>{calls.push(url);return {data:record}}}:n==='@nextcloud/router'?{generateUrl:x=>x}:(n==='./ReviewPreview.vue'||n==='./ReviewShares.vue')?{render:h=>h('div')}:require(n),mod,mod.exports)
 const c=mod.exports.default
 Object.assign(c,compiler.compileToFunctions(sfc.template.content))
 const vm=new Vue({...c,propsData:{hash:'a'.repeat(64)},methods:{...c.methods,t:(_,s)=>s}}).$mount()
 document.body.append(vm.$el)
 vm.choose({id:1,indexOwner:'<img src=x>',indexPath:'/alice/files/<script>secret</script>'},'keep',null)
 await tick()
 assert.equal(vm.$el.querySelector('img'),null)
 assert.match(vm.$el.textContent,/<script>secret<\/script>/)
 await vm.openRevision('x',2);await tick()
 assert.equal(calls[0],'/apps/duplicatefinder/api/review/plans/x/revisions/2')
 assert.match(vm.$el.querySelector('pre').textContent,/"executable": false/)
 const summary=vm.$el.querySelector('[data-saved-summary]')
 assert.ok(summary, 'saved proposal must be readable without raw JSON')
 assert.match(summary.textContent,/Keep: 1/)
 assert.match(summary.textContent,/Propose removal: 1/)
 assert.match(summary.textContent,/owner-a/)
 assert.match(summary.textContent,/recipient-b/)
 assert.match(summary.textContent,/<img src=x>/)
 assert.equal(summary.querySelector('img'),null)
 assert.match(summary.textContent,/Blue frame/)
 assert.match(summary.textContent,/All exposed frames decoded/)
 assert.match(summary.textContent,/No technical finding selected/)
 assert.match(summary.textContent,/Sharing consequences have not been fully determined/)
 assert.equal(summary.querySelectorAll('[data-saved-member]').length,2)
 assert.equal(vm.$el.querySelector('details').hasAttribute('open'),false)
 assert.equal(summary.querySelectorAll('button,select,input,textarea').length,0)
 assert.equal(vm.decisions.length,1)
 vm.saved=true;await tick()
 const note=vm.$el.querySelector('textarea');note.value='changed';note.dispatchEvent(new window.Event('input'));await tick()
 assert.equal(vm.saved,false)
 vm.saved=true;await tick()
 const reason=vm.$el.querySelector('li input');reason.value='changed reason';reason.dispatchEvent(new window.Event('input'));await tick()
 assert.equal(vm.saved,false)
 vm.$destroy();vm.$el.remove()
})
test('editing a stored revision preserves historical finding references and protects unsaved changes',()=>{
 const vm=component({})
 vm.record={planId:4,revision:2,hash:vm.hash,note:'',members:[{appRef:1,action:'keep',observed:{id:1},evidence:[{id:7},{id:8}],manualAssessment:{status:'not_assessed',note:''},reason:''}]}
 vm.editRevision()
 assert.deepEqual(vm.decisions[0].evidenceIds,[7,8])
 let prevented=false
 const event={preventDefault(){prevented=true}}
 vm.guardUnload(event)
 assert.equal(prevented,true)
 assert.equal(event.returnValue,'')
})
test('rejects oversized UTF8 notes and reasons before submitting and preserves content',async()=>{
 let calls=0
 const vm=component({post:async()=>{calls++}})
 vm.choose({id:1},'keep',null)
 vm.decisions[0].reason='ä'.repeat(1025)
 await vm.save();assert.equal(calls,0);assert.match(vm.error,/UTF-8/);assert.equal(vm.decisions[0].reason.length,1025)
 vm.decisions[0].reason='';vm.note='ä'.repeat(2049)
 await vm.save();assert.equal(calls,0);assert.match(vm.error,/UTF-8/)
})

test('explicit assessments retain source and note through saving and reject another reference',async()=>{
 let payload
 const vm=component({post:async(u,p)=>{payload=p.payload;return {data:{revision:1}}}})
 vm.choose({id:9},'keep',{entry:{id:7}})
 const d=vm.decisions[0];const source={kind:'original_preview',evidenceId:7,previewId:1,sha256:'a'.repeat(64),scope:'original_first_frame_scaled'}
 assert.equal(typeof vm.assess,'function')
 vm.assess(d,{appRef:10,status:'content_visible',source});assert.equal(d.manualAssessment.status,'not_assessed')
 vm.assess(d,{appRef:9,status:'content_visible',source});d.manualAssessment.note='Visible first frame'
 source.sha256='b'.repeat(64)
 await vm.save();assert.equal(payload.members[0].manualAssessment.source.sha256,'a'.repeat(64))
 assert.equal(payload.members[0].manualAssessment.note,'Visible first frame')
 vm.clearAssessment(d);assert.deepEqual(d.manualAssessment,{status:'not_assessed',note:''});assert.equal(vm.saved,false)
})

test('changing the judgement of the same artifact preserves the written note',()=>{
 const vm=component({});vm.choose({id:9},'keep',{entry:{id:7}})
 const d=vm.decisions[0];const source={kind:'original_preview',evidenceId:7,previewId:1,sha256:'a'.repeat(64),scope:'original_first_frame_scaled'}
 vm.assess(d,{appRef:9,status:'content_visible',source});d.manualAssessment.note='Keep this explanation'
 vm.assess(d,{appRef:9,status:'problem',source})
 assert.equal(d.manualAssessment.note,'Keep this explanation')
 assert.equal(d.manualAssessment.status,'problem')
})

test('explicit sharing selection is bounded, copied, replaceable and preserved for revision edits', async()=>{
 let payload;const vm=component({post:async(u,p)=>{payload=p.payload;throw {response:{status:409}}}})
 const observed={id:7,indexOwner:'alice',indexPath:'/alice/files/a',etag:'v1'}
 vm.choose(observed,'keep',null);const d=vm.decisions[0]
 const event={appRef:7,query:{depth:1,type:0,offset:0},expected:{appRef:7,observed:{...observed},observedAt:10,items:[{recipient:'bob'}]}}
 vm.selectSharing(d,event);event.expected.items[0].recipient='changed'
 assert.equal(d.sharePages[0].expected.items[0].recipient,'bob')
 vm.selectSharing(d,{...event,expected:{...event.expected,observed:{...observed,etag:'v2'}}});assert.equal(d.sharePages.length,1)
 await vm.save();assert.equal(payload.members[0].sharePages[0].expected.items[0].recipient,'bob');assert.equal(d.sharePages.length,1)
 for(let i=1;i<20;i++)vm.selectSharing(d,{...event,query:{depth:1,type:0,offset:i*25}})
 assert.equal(d.sharePages.length,20)
 vm.selectSharing(d,{...event,query:{depth:2,type:0,offset:0}});assert.equal(d.sharePages.length,20)
 vm.selectSharing(d,event);assert.equal(d.sharePages.length,20);assert.equal(d.sharePages[0].expected.items[0].recipient,'changed')
 vm.removeSharing(d,d.sharePages[0]);assert.equal(d.sharePages.length,19)
 vm.record={planId:'x',revision:1,hash:vm.hash,note:'',members:[{appRef:7,action:'keep',observed,evidence:[],manualAssessment:{status:'not_assessed',note:''},sharing:{pages:[{query:event.query,page:event.expected}]}}]}
 vm.editRevision();assert.deepEqual(vm.decisions[0].sharePages,[{query:event.query,expected:event.expected}])
})
