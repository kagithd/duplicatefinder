const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const compiler = require('vue-template-compiler')
const babel = require('@babel/core')
const { JSDOM } = require('jsdom')
const dom = new JSDOM('<html><body></body></html>')
global.window = dom.window; global.document = dom.window.document; global.navigator = dom.window.navigator
const Vue = require('vue/dist/vue.common.js')
Vue.config.productionTip = false; Vue.config.devtools = false
const tick = async () => { await Promise.resolve(); await Vue.nextTick(); await Promise.resolve(); await Vue.nextTick() }
function mount(get, props = { appRef: 9, evidenceId: 7 }) {
 const sfc = compiler.parseComponent(fs.readFileSync('src/components/ReviewPreview.vue', 'utf8'))
 const mod = { exports: {} }
 const code = babel.transformSync(sfc.script.content, {babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code
 new Function('require','module','exports',code)(n => n === '@nextcloud/axios' ? {get} : n === '@nextcloud/router' ? {generateUrl:x=>x} : require(n),mod,mod.exports)
 const c = mod.exports.default
 Object.assign(c,compiler.compileToFunctions(sfc.template.content))
 const vm = new Vue({...c,propsData:props,methods:{...c.methods,t:(_,s)=>s}}).$mount()
 document.body.append(vm.$el); return vm
}
const jpeg = Buffer.from([255,216,255,224,1,2,255,217]).toString('base64')
const artifact = () => ({id:1,appRef:9,evidenceId:7,createdAt:1700000000,record:{schemaVersion:1,boundSnapshot:{},descriptor:{status:'available',scope:'original_first_frame_scaled',mime:'image/jpeg',frameIndex:0,width:100,height:50,imageBase64:jpeg},sha256:'a'.repeat(64)},nativeFreshness:'not_rechecked',validityScope:'original_first_frame_scaled',visualAssessment:'not_provided'})
function dispose(vm) { vm.$destroy(); vm.$el.remove() }
test('requests a bound historical preview only on explicit click and preserves its aspect ratio', async () => {
 const calls=[]; const vm=mount(async url=>{calls.push(url);return {data:artifact()}})
 await tick(); assert.equal(calls.length,0); assert.equal(vm.$el.querySelector('img'),null)
 vm.$el.querySelector('button').click(); await tick()
 assert.deepEqual(calls,['/apps/duplicatefinder/api/review/members/9/evidence/7/preview'])
 const img=vm.$el.querySelector('img');assert.ok(img);assert.equal(img.getAttribute('width'),'100');assert.equal(img.getAttribute('height'),'50')
 assert.match(vm.$el.textContent,/Historical|Historische/);assert.match(vm.$el.textContent,/first frame|erste Frame/);assert.match(vm.$el.textContent,/not been rechecked|nicht erneut/);assert.match(vm.$el.textContent,/not been assessed|nicht bewertet/)
 dispose(vm)
})
test('rejects foreign bindings, MIME, malformed base64, non-JPEG, and oversized descriptors',async()=>{
 const changes=[d=>d.appRef=10,d=>d.evidenceId=8,d=>d.record.descriptor.mime='image/svg+xml',d=>d.record.descriptor.imageBase64='!!!!',d=>d.record.descriptor.imageBase64=Buffer.from('not jpeg').toString('base64'),d=>d.record.descriptor.width=513,d=>d.record.descriptor.imageBase64=Buffer.alloc(65537,255).toString('base64'),d=>d.visualAssessment='approved']
 for(const change of changes){const d=artifact();change(d);const vm=mount(async()=>({data:d}));vm.$el.querySelector('button').click();await tick();assert.equal(vm.$el.querySelector('img'),null);assert.ok(vm.$el.querySelector('[role="alert"]'));dispose(vm)}
})
test('clears previews on binding changes and ignores late responses and unmount',async()=>{
 let resolve;const vm=mount(()=>new Promise(r=>{resolve=r}));vm.$el.querySelector('button').click();await tick();vm.evidenceId=8;await tick();resolve({data:artifact()});await tick();assert.equal(vm.$el.querySelector('img'),null);assert.equal(vm.loading,false)
 vm.$el.querySelector('button').click();await tick();dispose(vm);resolve({data:artifact()});await tick();assert.equal(vm.source,'')
})
test('distinguishes missing previews from retryable errors',async()=>{
 for(const status of [404,500]){let calls=0;const vm=mount(async()=>{if(++calls===1)throw {response:{status}};return {data:artifact()}});vm.$el.querySelector('button').click();await tick();assert.match(vm.$el.textContent,status===404?/unavailable|verfügbar/:/could not|konnte/);vm.$el.querySelector('button').click();await tick();assert.ok(vm.$el.querySelector('img'));dispose(vm)}
})

test('requires successful image load and explicit human action before emitting a bound assessment', async()=>{
 const vm=mount(async()=>({data:artifact()}),{appRef:9,evidenceId:7,allowAssessment:true});const events=[]
 vm.$on('assessed',value=>events.push(value))
 await vm.load();await tick();assert.equal(events.length,0)
 const button=vm.$el.querySelector('[data-assess-visible]');assert.ok(button);assert.equal(button.disabled,true)
 Object.defineProperty(vm.$el.querySelector('img'),'naturalWidth',{value:100});vm.$el.querySelector('img').dispatchEvent(new window.Event('load'));await tick()
 assert.equal(button.disabled,false);assert.equal(events.length,0)
 button.click();await tick()
 assert.deepEqual(events,[{appRef:9,status:'content_visible',source:{kind:'original_preview',evidenceId:7,previewId:1,sha256:'a'.repeat(64),scope:'original_first_frame_scaled'}}])
 vm.$el.querySelector('img').dispatchEvent(new window.Event('error'));await tick()
 assert.equal(vm.$el.querySelector('[data-assess-visible]'),null)
 dispose(vm)
})

test('preview zoom changes displayed size only and resets on binding change',async()=>{
 const vm=mount(async()=>({data:artifact()}));await vm.load();await tick();
 const zoom=vm.$el.querySelector('[data-preview-zoom]');assert.ok(zoom);
 zoom.value='2';zoom.dispatchEvent(new window.Event('change'));await tick();
 const img=vm.$el.querySelector('img');assert.equal(img.style.width,'200px');assert.equal(img.style.height,'100px');
 assert.match(vm.$el.textContent,/100 × 50/);assert.match(vm.$el.textContent,/does not add detail/);
 vm.evidenceId=8;await tick();assert.equal(vm.zoom,'fit');assert.equal(vm.source,'');dispose(vm);
});
