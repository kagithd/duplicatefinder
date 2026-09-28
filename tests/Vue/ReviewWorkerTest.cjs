const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const path = require('node:path')
const { JSDOM } = require('jsdom')
const dom = new JSDOM('<!doctype html><html><body></body></html>')
global.window = dom.window; global.document = dom.window.document; global.navigator = dom.window.navigator
const Vue = require('vue/dist/vue.common.js'); Vue.config.productionTip = false; Vue.config.devtools = false
const compiler = require('vue-template-compiler'); const babel = require('@babel/core')
const tick = async () => { await Promise.resolve(); await Vue.nextTick(); await Promise.resolve(); await Vue.nextTick() }
const state = (name, id='a'.repeat(32)) => ({ available: true, state: name, runId: name==='idle'?null:id, exitCode: ['finished','stopped'].includes(name)?0:null })
function mount(get, post) {
 const sfc=compiler.parseComponent(fs.readFileSync(path.join(__dirname,'../../src/components/ReviewWorker.vue'),'utf8'))
 const code=babel.transformSync(sfc.script.content,{babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code
 const mod={exports:{}}
 new Function('require','module','exports',code)(name=>name==='@nextcloud/axios'?{get,post}:name==='@nextcloud/router'?{generateUrl:v=>v}:require(name),mod,mod.exports)
 const component=mod.exports.default; Object.assign(component,compiler.compileToFunctions(sfc.template.content))
 const vm=new Vue({...component,methods:{...component.methods,t:(_,s)=>s}}).$mount(); document.body.append(vm.$el); return vm
}
function dispose(vm){vm.$destroy();vm.$el.remove()}
test('unknown status disables mutations until explicit refresh',async()=>{
 let calls=0; const vm=mount(async()=>{calls++;return {data:state('idle')}})
 assert.equal(calls,0); assert.ok(vm.$el.querySelector('[data-worker-start]').disabled); assert.ok(vm.$el.querySelector('[data-worker-stop]').disabled)
 vm.$el.querySelector('[data-worker-refresh]').click(); await tick()
 assert.equal(calls,1);assert.equal(vm.$el.querySelector('[data-worker-start]').disabled,false);dispose(vm)
})
test('start is explicit and serialized; stopping uses observed run identity',async()=>{
 let release; const sent=[]
 const vm=mount(async()=>({data:state('idle')}),(url,body)=>{sent.push({url,body});return new Promise(resolve=>{release=resolve})})
 await vm.refresh();const pending=vm.start();await tick();vm.start();vm.refresh()
 assert.equal(sent.length,1);assert.ok(vm.$el.querySelector('[data-worker-refresh]').disabled)
 release({data:state('running')});await pending;await tick()
 const stopping=vm.stop();assert.deepEqual(sent[1],{url:'/apps/duplicatefinder/api/review/worker/stop',body:{runId:'a'.repeat(32)}})
 release({data:state('stopping')});await stopping;await tick()
 assert.ok(vm.$el.querySelector('[data-worker-start]').disabled);assert.ok(vm.$el.querySelector('[data-worker-stop]').disabled);dispose(vm)
})
test('ambiguous mutation and stale generation discard actionable status',async()=>{
 for(const status of [503,409]){
 const vm=mount(async()=>({data:state('running')}),async()=>{throw {response:{status}}})
 await vm.refresh();await vm.stop();await tick()
 assert.ok(vm.$el.querySelector('[role=alert]'));assert.ok(vm.$el.querySelector('[data-worker-start]').disabled);assert.ok(vm.$el.querySelector('[data-worker-stop]').disabled);dispose(vm)
 }
})
test('unavailable or malformed response cannot enable start',async()=>{
 for(const data of [{available:false}, {available:true,state:'idle'}, {available:true,state:'finished',runId:'a'.repeat(32),exitCode:7}]){
 const vm=mount(async()=>({data}));await vm.refresh();await tick();assert.ok(vm.$el.querySelector('[data-worker-start]').disabled);dispose(vm)
 }
})
