const assert=require('node:assert/strict');const {test}=require('node:test');const fs=require('node:fs');const compiler=require('vue-template-compiler');const babel=require('@babel/core');const {JSDOM}=require('jsdom');
const dom=new JSDOM('<html><body></body></html>');global.window=dom.window;global.document=dom.window.document;global.navigator=dom.window.navigator;
const Vue=require('vue/dist/vue.common.js');Vue.config.productionTip=false;Vue.config.devtools=false;
async function tick(){await Promise.resolve();await Vue.nextTick();await Promise.resolve();await Vue.nextTick()}
function mount(get){const sfc=compiler.parseComponent(fs.readFileSync('src/components/ReviewMissingFindings.vue','utf8'));const mod={exports:{}};const code=babel.transformSync(sfc.script.content,{babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code;
new Function('require','module','exports',code)(n=>n==='@nextcloud/axios'?{get}:n==='@nextcloud/router'?{generateUrl:x=>x}:require(n),mod,mod.exports);
const c=mod.exports.default;Object.assign(c,compiler.compileToFunctions(sfc.template.content));const vm=new Vue({...c,methods:{...c.methods,t:(_,s)=>s}}).$mount();document.body.append(vm.$el);return vm;}
function dispose(vm){vm.$destroy();vm.$el.remove()}

const page={scope:'indexed_candidates_without_reports',items:[{id:7,indexOwner:'alice',indexPath:'/alice/files/<img>/a',indexMime:'image/png'}],nextCursor:7};
test('missing findings require explicit search, escape paths and open only by reference',async()=>{
 const calls=[];const vm=mount(async(u,o)=>{calls.push(o.params);return {data:page}});await tick();assert.equal(calls.length,0);
 vm.owner='alice';vm.folder='/alice/files';vm.mime='image/png';await vm.apply();await tick();
 assert.deepEqual(calls[0],{cursor:0,pageSize:25,owner:'alice',folder:'/alice/files',mime:'image/png'});
 assert.equal(vm.$el.querySelector('img'),null);assert.match(vm.$el.textContent,/without any saved finding/);
 let opened;vm.$on('open-reference',id=>opened=id);vm.$el.querySelector('[data-open-reference]').click();assert.equal(opened,7);dispose(vm);
});
test('missing-finding pagination keeps applied scope and ignores late searches',async()=>{
 let release;let count=0;const calls=[];const vm=mount((u,o)=>{calls.push(o.params);return ++count===1?new Promise(r=>release=r):Promise.resolve({data:page})});
 const old=vm.apply();vm.owner='alice';await vm.apply();release({data:{...page,items:[]}});await old;
 vm.owner='bob';await vm.load(7);assert.equal(vm.page.items.length,1);assert.equal(calls[2].owner,'alice');assert.equal(calls[2].cursor,7);dispose(vm);
});
test('missing-finding errors clear obsolete results and expose no server details',async()=>{
 let fail=false;const vm=mount(async()=>{if(fail)throw new Error('private');return {data:page}});
 await vm.apply();fail=true;await vm.apply();await tick();assert.equal(vm.page,null);assert.match(vm.error,/Could not load/);assert.doesNotMatch(vm.$el.textContent,/private/);dispose(vm);
});
