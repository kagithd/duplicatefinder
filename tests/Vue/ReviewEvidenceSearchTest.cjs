const assert=require('node:assert/strict');const {test}=require('node:test');const fs=require('node:fs');const compiler=require('vue-template-compiler');const babel=require('@babel/core');const {JSDOM}=require('jsdom');
const dom=new JSDOM('<html><body></body></html>');global.window=dom.window;global.document=dom.window.document;global.navigator=dom.window.navigator;
const Vue=require('vue/dist/vue.common.js');Vue.config.productionTip=false;Vue.config.devtools=false;
async function tick(){await Promise.resolve();await Vue.nextTick();await Promise.resolve();await Vue.nextTick()}
function mount(get){const sfc=compiler.parseComponent(fs.readFileSync('src/components/ReviewEvidenceSearch.vue','utf8'));const mod={exports:{}};const code=babel.transformSync(sfc.script.content,{babelrc:false,configFile:false,plugins:['@babel/plugin-transform-modules-commonjs']}).code;
new Function('require','module','exports',code)(n=>n==='@nextcloud/axios'?{get}:n==='@nextcloud/router'?{generateUrl:x=>x}:require(n),mod,mod.exports);
const c=mod.exports.default;Object.assign(c,compiler.compileToFunctions(sfc.template.content));const vm=new Vue({...c,methods:{...c.methods,t:(_,s)=>s}}).$mount();document.body.append(vm.$el);return vm;}
function dispose(vm){vm.$destroy();vm.$el.remove()}
const page={items:[{id:2,appRef:7,createdAt:1,historicalStatus:'corrupt',historicalFormat:'PNG',issue:null,evidence:{usability:'stale',reason:'nextcloud_revision_changed',record:{after:{owner:'alice',path:'/alice/files/<img src=x>/a.png'},report:{reason:'decode_failed'}}}}],nextCursor:2,metadataComplete:false,scope:'historical_reports_only'};
test('evidence search loads explicitly and shows escaped full paths, historical validity and incomplete coverage',async()=>{
const calls=[];const vm=mount(async(u,o)=>{calls.push([u,o.params]);return {data:page}});await tick();assert.equal(calls.length,0);
vm.$el.querySelector('form').dispatchEvent(new window.Event('submit',{bubbles:true,cancelable:true}));await tick();assert.equal(calls.length,1);assert.equal(calls[0][1].status,'corrupt');
assert.match(vm.$el.textContent,/\/alice\/files\/<img src=x>\/a.png/);assert.equal(vm.$el.querySelector('img'),null);assert.match(vm.$el.textContent,/Search metadata is incomplete/);assert.match(vm.$el.textContent,/Historical findings/);assert.match(vm.$el.textContent,/stale/);dispose(vm);
});
test('new search discards late responses and paging preserves applied filters',async()=>{
let resolve;const calls=[];const vm=mount((u,o)=>{calls.push(o.params);return calls.length===1?new Promise(r=>resolve=r):Promise.resolve({data:{...page,metadataComplete:true}})});
const old=vm.apply();vm.status='unsupported';await vm.apply();resolve({data:{...page,items:[]}});await old;await tick();assert.equal(vm.page.items.length,1);
vm.status='passed';await vm.load(2);assert.equal(calls[2].status,'unsupported');assert.equal(calls[2].cursor,2);dispose(vm);
});
test('failed search clears stale results, does not disclose server errors and can retry',async()=>{
let fail=false;const vm=mount(async()=>{if(fail)throw new Error('secret-details');return {data:page}});await vm.apply();fail=true;await vm.apply();await tick();assert.equal(vm.page,null);assert.match(vm.$el.textContent,/Could not load findings/);assert.doesNotMatch(vm.$el.textContent,/secret-details/);fail=false;await vm.apply();assert.ok(vm.page);dispose(vm);
});