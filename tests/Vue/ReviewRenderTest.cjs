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

function mount(get) {
    const file = path.join(__dirname, '../../src/Review.vue')
    const sfc = compiler.parseComponent(fs.readFileSync(file, 'utf8'))
    const code = babel.transformSync(sfc.script.content, { babelrc: false, configFile: false, plugins: ['@babel/plugin-transform-modules-commonjs'] }).code
    const module = { exports: {} }
    const fakeRequire = name => {
        if (name === '@nextcloud/axios') return { get }
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
