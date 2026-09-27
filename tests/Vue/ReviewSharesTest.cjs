const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const compiler = require('vue-template-compiler')
const babel = require('@babel/core')
const {JSDOM}=require('jsdom')
const dom=new JSDOM('<html><body></body></html>')
global.window=dom.window;global.document=dom.window.document;global.navigator=dom.window.navigator
const Vue=require('vue/dist/vue.common.js');Vue.config.productionTip=false;Vue.config.devtools=false
async function tick(){await Promise.resolve();await Vue.nextTick();await Promise.resolve();await Vue.nextTick()}
function mount(get){
 const sfc=compiler.parseComponent(fs.readFileSync('src/components/ReviewShares.vue','utf8'))
 const mod={exports:{}};const code=babel.transformSync(sfc.script.content,{babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code
 new Function('require','module','exports',code)(n=>n==='@nextcloud/axios'?{get}:n==='@nextcloud/router'?{generateUrl:x=>x}:require(n),mod,mod.exports)
 const c=mod.exports.default;Object.assign(c,compiler.compileToFunctions(sfc.template.content))
 const vm=new Vue({...c,propsData:{appRef:7},methods:{...c.methods,t:(_,s)=>s}}).$mount();document.body.append(vm.$el);return vm
}
const page=(depth=0,offset=0)=>({appRef:7,type:0,anchor:{depth,path:'/alice/files/folder'},ownerPath:'/alice/files/folder/a.png',observedAt:1,complete:false,nextOffset:offset===0?25:null,nextDepth:depth+1,items:[{id:'1',recipient:'<img src=x>',recipientPath:'/bob/files/shared/a.png',pathStatus:'observed',permissions:31,effectivePermissions:27,deletable:true,expiration:null,status:0}]})
test('shares load only explicitly, preserve full escaped paths, and follow bounded pages',async()=>{
 const calls=[];const vm=mount(async(u,o)=>{calls.push([u,o.params]);return {data:page(o.params.depth,o.params.offset)}})
 await tick();assert.equal(calls.length,0)
 await vm.load(0,0);await tick()
 assert.deepEqual(calls[0],['/apps/duplicatefinder/api/review/members/7/shares',{depth:0,type:0,offset:0,limit:25}])
 assert.match(vm.$el.textContent,/\/bob\/files\/shared\/a.png/);assert.equal(vm.$el.querySelector('img'),null)
 assert.match(vm.$el.textContent,/Incomplete sharing observation/)
 assert.match(vm.$el.textContent,/31/);assert.match(vm.$el.textContent,/27/)
 await vm.load(0,25);assert.equal(calls[1][1].offset,25)
 vm.$destroy();vm.$el.remove()
})
test('late share response cannot populate another reference',async()=>{
 let resolve;const vm=mount(()=>new Promise(r=>resolve=r))
 const pending=vm.load(0,0);vm.appRef=8;await tick();resolve({data:page()});await pending;await tick()
 assert.equal(vm.page,null);assert.equal(vm.busy,false)
 vm.$destroy();vm.$el.remove()
})
test('errors are retryable and foreign response bindings are rejected',async()=>{
 let calls=0;const vm=mount(async()=>{calls++;if(calls===1)throw new Error('private-token');return {data:{...page(),appRef:99}}})
 await vm.load(0,0);await tick();assert.ok(vm.error);assert.doesNotMatch(vm.$el.textContent,/private-token/)
 await vm.load(0,0);await tick();assert.equal(vm.page,null);assert.ok(vm.error)
 vm.$destroy();vm.$el.remove()
})
