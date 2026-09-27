
const assert=require('node:assert/strict'),{test}=require('node:test'),fs=require('node:fs'),compiler=require('vue-template-compiler'),babel=require('@babel/core'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<html><body></body></html>');global.window=dom.window;global.document=dom.window.document;global.navigator=dom.window.navigator;
const Vue=require('vue/dist/vue.common.js');Vue.config.productionTip=false;Vue.config.devtools=false;
async function tick(){await Promise.resolve();await Vue.nextTick();}
function mount(get=async()=>{throw Error('unexpected read')}){
 function component(name){const sfc=compiler.parseComponent(fs.readFileSync('src/components/'+name,'utf8'));const mod={exports:{}};const code=babel.transformSync(sfc.script.content,{babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code;
 new Function('require','module','exports',code)(n=>n==='./ReviewPreview.vue'?{default:component('ReviewPreview.vue'),__esModule:true}:n==='@nextcloud/axios'?{get}:n==='@nextcloud/router'?{generateUrl:x=>x}:require(n),mod,mod.exports);
 const c=mod.exports.default;Object.assign(c,compiler.compileToFunctions(sfc.template.content));c.methods.t=(_,s)=>s;return c;}
 const vm=new Vue(component('ReviewComparison.vue')).$mount();document.body.append(vm.$el);return vm;
}
function finding(id){return {id,record:{after:{owner:'alice',path:'/alice/files/<img>/a.png'},report:{status:'passed',frames_decoded:2}}};}
function dispose(vm){vm.$destroy();vm.$el.remove();}
test('comparison retains four explicit bound snapshots and never silently replaces a revision',async()=>{
 let reads=0;const vm=mount(async()=>{reads++;throw Error('unexpected')});const entry=finding(10);
 vm.choose({id:1},entry);entry.record.after.path='changed';vm.choose({id:1},finding(11));
 for(let id=2;id<=5;id++)vm.choose({id},finding(id+10));await tick();
 assert.equal(vm.items.length,4);assert.equal(vm.items[0].evidenceId,10);assert.equal(vm.items[0].path,'/alice/files/<img>/a.png');assert.equal(reads,0);
 assert.equal(vm.$el.querySelector('img'),null);assert.match(vm.$el.textContent,/first-frame/);assert.ok(vm.error);
 vm.remove(1);assert.equal(vm.items.length,3);vm.clear();assert.equal(vm.items.length,0);dispose(vm);
});
test('comparison rejects absent or unsuccessful evidence and exposes bounded preview bindings',async()=>{
 const vm=mount();vm.choose({id:1},null);const failed=finding(10);failed.record.report.status='corrupt';vm.choose({id:1},failed);assert.equal(vm.items.length,0);
 vm.choose({id:2},finding(12));await tick();assert.equal(vm.$children[0].appRef,2);assert.equal(vm.$children[0].evidenceId,12);assert.equal(vm.$children[0].allowAssessment,false);dispose(vm);
});

test('load selected previews is explicit and late loads cannot restore cleared comparison',async()=>{
 const calls=[],pending=[];const vm=mount(url=>{calls.push(url);return new Promise(resolve=>pending.push(resolve));});
 vm.choose({id:1},finding(10));vm.choose({id:2},finding(11));await tick();assert.equal(calls.length,0);
 const loading=vm.loadAll();await tick();assert.equal(calls.length,2);assert.equal(vm.loading,true);
 vm.clear();await tick();for(const resolve of pending)resolve({data:{}});await loading;await tick();
 assert.equal(vm.items.length,0);assert.equal(vm.loading,false);dispose(vm);
});
