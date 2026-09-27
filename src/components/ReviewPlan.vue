<template>
	<section class="review-plan" aria-labelledby="plan-heading">
		<h2 id="plan-heading">
			{{ t('duplicatefinder', 'Decision proposal') }}
		</h2>
		<p>{{ t('duplicatefinder', 'Proposals do not execute file or index actions. Only explicitly selected references are included, up to 100. A selection is not a complete group snapshot.') }}</p>
		<p v-if="draftHash">
			<code>{{ draftHash }}</code> · {{ decisions.length }}/100
		</p>
		<p v-if="decisions.length">
			{{ t('duplicatefinder', 'The draft is retained when changing groups or pages. Discard it explicitly before selecting another group.') }}
		</p>
		<ol>
			<li v-for="decision in decisions" :key="decision.appRef">
				<p>{{ decision.expected.indexOwner }} · {{ decision.expected.indexPath }} · {{ decision.appRef }}</p>
				<label>{{ t('duplicatefinder', 'Proposed action') }}
					<select :value="decision.action" :disabled="busy" @change="changeAction(decision, $event.target.value)">
						<option value="keep">{{ t('duplicatefinder', 'Keep') }}</option>
						<option value="remove">{{ t('duplicatefinder', 'Propose removal') }}</option>
						<option value="exclude">{{ t('duplicatefinder', 'Exclude from proposal') }}</option>
					</select>
				</label>
				<label>{{ t('duplicatefinder', 'Reason') }}<input v-model="decision.reason"
					:disabled="busy"
					maxlength="4000"
					@input="saved = false"></label>
				<p>{{ assessmentLabel(decision.manualAssessment.status) }} · {{ t('duplicatefinder', 'Finding IDs') }}: {{ decision.evidenceIds.join(', ') || '—' }}</p>
				<p v-if="decision.manualAssessment.source">{{ t('duplicatefinder', 'Assessed historical preview') }}: {{ decision.manualAssessment.source.previewId }} · {{ decision.manualAssessment.source.scope }}</p>
				<label v-if="decision.manualAssessment.status !== 'not_assessed'">{{ t('duplicatefinder', 'Assessment note') }}<textarea v-model="decision.manualAssessment.note" :disabled="busy" maxlength="2048" @input="saved = false" /></label>
				<button v-if="decision.manualAssessment.status !== 'not_assessed'" type="button" :disabled="busy" @click="clearAssessment(decision)">{{ t('duplicatefinder', 'Clear assessment') }}</button>
				<p>{{ t('duplicatefinder', 'Selected sharing pages in this proposal') }}: {{ sharingCount() }}/20</p>
				<ol><li v-for="entry in decision.sharePages" :key="shareKey(entry.query)">
					<p>{{ entry.expected.anchor.path }} · {{ t('duplicatefinder', 'Share type') }} {{ entry.query.type }} · {{ t('duplicatefinder', 'Page offset') }} {{ entry.query.offset }}</p>
					<button type="button" :disabled="busy" @click="removeSharing(decision, entry)">{{ t('duplicatefinder', 'Remove sharing page from proposal') }}</button>
				</li></ol>
				<ReviewShares :app-ref="decision.appRef" :selectable="true" :disabled="busy" @selected="selectSharing(decision, $event)" />
				<ReviewPreview v-for="evidenceId in decision.evidenceIds" :key="decision.appRef + ':' + evidenceId" :app-ref="decision.appRef" :evidence-id="evidenceId" :allow-assessment="true" :disabled="busy" @assessed="assess(decision, $event)" />
				<button type="button" :disabled="busy" @click="removeDecision(decision)">
					{{ t('duplicatefinder', 'Remove selection') }}
				</button>
			</li>
		</ol>
		<label>{{ t('duplicatefinder', 'Proposal note') }}<textarea v-model="note"
			:disabled="busy"
			maxlength="4000"
			@input="saved = false" /></label>
		<button type="button"
			data-save-plan
			:disabled="busy || !decisions.length"
			@click="save">
			{{ t('duplicatefinder', 'Save proposal revision') }}
		</button>
		<button type="button" :disabled="busy || !draftHash" @click="discard">
			{{ t('duplicatefinder', 'Discard draft') }}
		</button>
		<p v-if="error" role="alert">
			{{ error }}
		</p>
		<p v-if="saved" role="status">
			{{ t('duplicatefinder', 'Proposal saved. No file or index actions were executed.') }}
		</p>
		<h3>{{ t('duplicatefinder', 'Saved proposals') }}</h3>
		<button type="button" :disabled="busy" @click="loadPlans(0)">
			{{ t('duplicatefinder', 'Load saved proposals') }}
		</button>
		<ul>
			<li v-for="plan in plans" :key="plan.planId">
				<button type="button" :disabled="busy" @click="openRevision(plan.planId, plan.revision)">
					{{ plan.planId }} · {{ t('duplicatefinder', 'Revision') }} {{ plan.revision }}
				</button>
			</li>
		</ul>
		<button v-if="nextCursor !== null"
			type="button"
			:disabled="busy"
			@click="loadPlans(nextCursor)">
			{{ t('duplicatefinder', 'Next proposals') }}
		</button>
		<template v-if="record">
			<h3>{{ t('duplicatefinder', 'Exact saved revision') }} {{ record.planId }} / {{ record.revision }}</h3>
			<p>{{ record.creator }} · {{ timestamp(record.createdAt) }} · {{ t('duplicatefinder', 'Not executable') }}</p>
			<label>{{ t('duplicatefinder', 'Revision') }}<input v-model.number="revisionInput" type="number" min="1"></label>
			<button type="button" :disabled="busy" @click="openRevision(record.planId, revisionInput)">
				{{ t('duplicatefinder', 'Read revision') }}
			</button>
			<button type="button" :disabled="busy" @click="download">
				{{ t('duplicatefinder', 'Download exact JSON') }}
			</button>
			<button type="button" :disabled="busy || decisions.length > 0" @click="editRevision">
				{{ t('duplicatefinder', 'Edit as new revision') }}
			</button>
			<section data-saved-summary :aria-label="t('duplicatefinder', 'Saved decisions')">
				<p>{{ t('duplicatefinder', 'Saved decisions') }} · {{ t('duplicatefinder', 'Keep') }}: {{ countAction('keep') }} · {{ t('duplicatefinder', 'Propose removal') }}: {{ countAction('remove') }} · {{ t('duplicatefinder', 'Exclude from proposal') }}: {{ countAction('exclude') }}</p>
				<p><strong>{{ t('duplicatefinder', 'Historical record only. Current file revisions and indexed hashes have not been rechecked. No execution authorization.') }}</strong></p>
				<p>{{ t('duplicatefinder', 'Sharing consequences have not been fully determined. Multiple references may point to the same physical file; counts do not represent recoverable space.') }}</p>
				<p>{{ t('duplicatefinder', 'Proposal note') }}: {{ record.note || '—' }}</p>
				<p>{{ t('duplicatefinder', 'Group hash') }}: <code>{{ record.hash }}</code></p>
				<p>{{ t('duplicatefinder', 'Index actions') }}: {{ (record.indexActions || []).length }}</p>
				<ol>
					<li v-for="member in record.members" :key="member.appRef" data-saved-member>
						<h4>{{ actionLabel(member.action) }} · {{ t('duplicatefinder', 'Reference') }} {{ member.appRef }}</h4>
						<dl>
							<dt>{{ t('duplicatefinder', 'Observed owner') }}</dt><dd>{{ observation(member).owner || '—' }}</dd>
							<dt>{{ t('duplicatefinder', 'Indexed user') }}</dt><dd>{{ observation(member).indexOwner || '—' }}</dd>
							<dt>{{ t('duplicatefinder', 'Indexed path') }}</dt><dd>{{ observation(member).indexPath || '—' }}</dd>
							<dt>{{ t('duplicatefinder', 'Observed path') }}</dt><dd>{{ observation(member).path || '—' }}</dd>
							<dt>{{ t('duplicatefinder', 'Storage / node ID') }}</dt><dd>{{ observation(member).storageId || '—' }} / {{ observation(member).nodeId || '—' }}</dd>
						</dl>
						<p>{{ t('duplicatefinder', 'Reason') }}: {{ member.reason || '—' }}</p>
						<p>{{ assessmentLabel((member.manualAssessment || {}).status) }} · {{ (member.manualAssessment || {}).note || '—' }}</p>
						<p v-if="member.manualAssessment && member.manualAssessment.source">{{ t('duplicatefinder', 'Assessed historical preview') }}: {{ member.manualAssessment.source.previewId }} · {{ t('duplicatefinder', 'Finding IDs') }}: {{ member.manualAssessment.source.evidenceId }} · {{ member.manualAssessment.source.scope }}</p>
						<h5>{{ t('duplicatefinder', 'Saved sharing observations') }}</h5>
						<p>{{ t('duplicatefinder', 'Selected pages only; unobserved sharing consequences remain unknown.') }}</p>
						<p v-if="!storedSharing(member).length">{{ t('duplicatefinder', 'No sharing pages were included in this revision.') }}</p>
						<div v-for="entry in storedSharing(member)" :key="shareKey(entry.query)" data-saved-sharing>
							<p>{{ t('duplicatefinder', 'Share anchor') }}: {{ entry.page.anchor.path }} · {{ t('duplicatefinder', 'Share type') }} {{ entry.query.type }} · {{ t('duplicatefinder', 'Page offset') }} {{ entry.query.offset }}</p>
							<p>{{ t('duplicatefinder', 'Server rechecked at') }}: {{ timestamp(entry.page.observedAt) }}</p>
							<p v-if="!entry.page.items.length">{{ t('duplicatefinder', 'No entries on this saved page; other shares are not excluded.') }}</p>
							<ul><li v-for="share in entry.page.items" :key="share.id">
								<p>{{ share.recipient || t('duplicatefinder', 'Public link') }} · {{ share.recipientPath || t('duplicatefinder', 'Not determined') }}</p>
								<p>{{ sharingPathStatus(share.pathStatus) }}</p>
								<p>{{ t('duplicatefinder', 'Deletion permission at observed recipient path') }}: {{ sharingPermission(share.deletable) }}</p>
								<p>{{ t('duplicatefinder', 'Recorded expiration') }}: {{ share.expiration || '—' }} · {{ t('duplicatefinder', 'Recorded share status') }}: {{ share.status }}</p>
								<p>{{ t('duplicatefinder', 'Share permissions (bitmask)') }}: {{ share.permissions }} · {{ t('duplicatefinder', 'Effective file permissions (bitmask)') }}: {{ share.effectivePermissions == null ? '—' : share.effectivePermissions }}</p>
							</li></ul>
						</div>
						<p v-if="!(member.evidence || []).length">{{ t('duplicatefinder', 'No technical finding selected') }}</p>
						<ul v-else>
							<li v-for="finding in member.evidence" :key="finding.id">
								<p>{{ t('duplicatefinder', 'Finding IDs') }}: {{ finding.id }} · {{ findingLabel(finding) }} · {{ timestamp(finding.createdAt) }}</p>
								<p>{{ t('duplicatefinder', 'Saved finding usability') }}: {{ finding.usability || '—' }} · {{ finding.reason || '—' }}</p>
								<p>{{ t('duplicatefinder', 'Check scope') }}: {{ reportOf(finding).scope || '—' }} · {{ t('duplicatefinder', 'Decoded frames') }}: {{ reportOf(finding).frames_decoded == null ? '—' : reportOf(finding).frames_decoded }}</p>
								<p>{{ t('duplicatefinder', 'Reason') }}: {{ reportOf(finding).reason || '—' }}</p>
							</li>
						</ul>
					</li>
				</ol>
			</section>
			<details><summary>{{ t('duplicatefinder', 'Technical JSON record') }}</summary><pre>{{ JSON.stringify(record, null, 2) }}</pre></details>
		</template>
	</section>
</template>
<script>
import ReviewShares from './ReviewShares.vue'
import ReviewPreview from './ReviewPreview.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
const copy = value => JSON.parse(JSON.stringify(value))
export default {
	name: 'ReviewPlan',
	components: { ReviewPreview, ReviewShares },
	props: { hash: { type: String, default: '' } },
	data() {
		return { decisions: [], draftHash: '', note: '', predecessor: null, planId: null, signature: '', retryKey: '', busy: false, error: '', saved: false, plans: [], nextCursor: null, record: null, revisionInput: 1 }
	},
	mounted() { window.addEventListener('beforeunload', this.guardUnload) },
	beforeDestroy() { window.removeEventListener('beforeunload', this.guardUnload) },
	methods: {
		shareKey(query) { return [query.depth, query.type, query.offset].join(':') },
		sharingCount() { return this.decisions.reduce((count, member) => count + (member.sharePages || []).length, 0) },
		selectSharing(decision, selection) {
			if (this.busy || !this.decisions.includes(decision) || selection.appRef !== decision.appRef || selection.expected?.appRef !== decision.appRef) return
			const stable = value => JSON.stringify(Object.entries(value || {}).sort(([a], [b]) => a.localeCompare(b)))
			if (stable(selection.expected.observed) !== stable(decision.expected)) { this.error = this.t('duplicatefinder', 'Sharing observation belongs to another file revision. Refresh the selection before saving.'); return }
			const pages = decision.sharePages || []
			const index = pages.findIndex(entry => this.shareKey(entry.query) === this.shareKey(selection.query))
			if (index < 0 && this.sharingCount() >= 20) { this.error = this.t('duplicatefinder', 'A proposal can include at most 20 sharing pages.'); return }
			const entry = { query: copy(selection.query), expected: copy(selection.expected) }
			if (index < 0) pages.push(entry); else pages.splice(index, 1, entry)
			this.$set(decision, 'sharePages', pages); this.saved = false; this.error = ''
		},
		removeSharing(decision, entry) { if (this.busy) return; const index = (decision.sharePages || []).indexOf(entry); if (index >= 0) { decision.sharePages.splice(index, 1); this.saved = false } },
		sharingPermission(value) { return ReviewShares.methods.permission.call(this, value) },
		sharingPathStatus(value) { return ReviewShares.methods.pathStatus.call(this, value) },
		storedSharing(member) { return member.sharing?.pages || [] },
		observation(member) { return member.observed || member.expected || {} },
		countAction(action) { return (this.record.members || []).filter(member => member.action === action).length },
		actionLabel(action) { return this.t('duplicatefinder', { keep: 'Keep', remove: 'Propose removal', exclude: 'Exclude from proposal' }[action] || 'Unknown action') },
		timestamp(seconds) {
			if (typeof seconds !== 'number' || !Number.isFinite(seconds)) return '—'
			const date = new Date(seconds * 1000)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},
		reportOf(finding) { return finding.record?.report || {} },
		findingLabel(finding) {
			const report = this.reportOf(finding)
			const labels = { passed: report.scope === 'original_all_exposed_frames' ? 'All exposed frames decoded' : 'Check completed', corrupt: 'Decoder reported corruption', unsupported: 'Format not supported', inaccessible: 'Original inaccessible', limit: 'Check limit reached', stale: 'File revision changed', error: 'Check failed' }
			return this.t('duplicatefinder', labels[report.status] || 'Unknown finding')
		},
		assessmentLabel(status) {
			return this.t('duplicatefinder', status === 'content_visible' ? 'Content visible in this preview' : status === 'problem' ? 'Problem visible in this preview' : 'Visual content has not been assessed.')
		},
		assess(decision, assessment) {
			if (this.busy || !this.decisions.includes(decision) || assessment.appRef !== decision.appRef || !decision.evidenceIds.includes(assessment.source?.evidenceId) || !['content_visible', 'problem'].includes(assessment.status)) return
			const previous = decision.manualAssessment.source
			const sameSource = previous && previous.previewId === assessment.source.previewId && previous.evidenceId === assessment.source.evidenceId && previous.sha256 === assessment.source.sha256
			decision.manualAssessment = { status: assessment.status, note: sameSource ? decision.manualAssessment.note : '', source: copy(assessment.source) }
			this.saved = false
		},
		clearAssessment(decision) { if (this.busy) return; decision.manualAssessment = { status: 'not_assessed', note: '' }; this.saved = false },
		guardUnload(event) {
			if (this.draftHash && !this.saved) { event.preventDefault(); event.returnValue = '' }
		},
		choose(member, action, evidence) {
			if (this.busy) return
			if (this.draftHash && this.draftHash !== this.hash) {
				this.error = this.t('duplicatefinder', 'The retained draft belongs to another group. Discard it first.')
				return
			}
			const existing = this.decisions.find(item => item.appRef === member.id)
			if (existing) { this.changeAction(existing, action); return }
			if (this.decisions.length >= 100) { this.error = this.t('duplicatefinder', 'The proposal is limited to 100 explicitly selected references.'); return }
			this.draftHash = this.hash
			this.decisions.push({ appRef: member.id, action, expected: copy(member), evidenceIds: evidence?.entry ? [evidence.entry.id] : [], manualAssessment: { status: 'not_assessed', note: '' }, reason: '', sharePages: [] })
			this.saved = false
			this.error = ''
		},
		changeAction(decision, action) { decision.action = action; this.saved = false },
		removeDecision(decision) { this.decisions.splice(this.decisions.indexOf(decision), 1); this.saved = false },
		discard() { this.decisions = []; this.draftHash = ''; this.note = ''; this.predecessor = null; this.planId = null; this.signature = ''; this.retryKey = ''; this.saved = false; this.error = '' },
		async save() {
			if (this.busy || !this.decisions.length) return
			const bytes = value => new TextEncoder().encode(value).length
			if (bytes(this.note) > 4096 || this.decisions.some(member => bytes(member.reason) > 2048 || bytes(member.manualAssessment.note) > 2048)) {
				this.error = this.t('duplicatefinder', 'Text exceeds the UTF-8 byte limit: reason 2048, notes 4096. Shorten it before saving. Your draft is retained.')
				return
			}
			const body = { hash: this.draftHash, members: copy(this.decisions), note: this.note, indexActions: [] }
			if (this.planId !== null) body.expectedPredecessor = this.predecessor
			const signature = JSON.stringify({ planId: this.planId, body })
			if (signature !== this.signature) { this.signature = signature; this.retryKey = globalThis.crypto?.randomUUID?.() || Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) }
			body.idempotencyKey = this.retryKey
			this.busy = true; this.error = ''; this.saved = false
			try {
				const url = '/apps/duplicatefinder/api/review/plans' + (this.planId === null ? '' : '/' + encodeURIComponent(this.planId) + '/revisions')
				const { data } = await axios.post(generateUrl(url), { payload: body })
				this.record = data; this.revisionInput = data.revision; this.saved = true
			} catch (error) {
				this.error = this.t('duplicatefinder', error.response?.status === 409 ? 'Conflict: file metadata, selected sharing pages or the predecessor revision changed. The draft remains unchanged. Review the conflict before trying again.' : 'Proposal could not be saved. The draft remains available for retry.')
			} finally { this.busy = false }
		},
		async loadPlans(cursor) {
			this.busy = true; this.error = ''
			try { const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/plans'), { params: { cursor, limit: 25 } }); this.plans = data.items; this.nextCursor = data.nextCursor } catch (error) { this.error = this.t('duplicatefinder', 'Saved proposals could not be loaded.') } finally { this.busy = false }
		},
		async openRevision(planId, revision) {
			this.busy = true; this.error = ''
			try { const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/plans/' + encodeURIComponent(planId) + '/revisions/' + encodeURIComponent(revision))); this.record = data; this.revisionInput = data.revision } catch (error) { this.error = this.t('duplicatefinder', 'Saved revision could not be loaded.') } finally { this.busy = false }
		},
		editRevision() {
			this.decisions = this.record.members.map(member => ({ appRef: member.appRef, action: member.action, expected: copy(member.observed || member.expected), evidenceIds: copy(member.evidenceIds || (member.evidence || []).map(entry => entry.id)), manualAssessment: copy(member.manualAssessment), reason: member.reason || '', sharePages: (member.sharing?.pages || []).map(entry => ({ query: copy(entry.query), expected: copy(entry.page) })) })); this.draftHash = this.record.hash; this.note = this.record.note; this.planId = this.record.planId; this.predecessor = this.record.revision; this.signature = ''; this.saved = false
		},
		async download() {
			this.busy = true; this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/plans/' + encodeURIComponent(this.record.planId) + '/revisions/' + encodeURIComponent(this.record.revision) + '/export'), { responseType: 'blob' })
				const url = URL.createObjectURL(data instanceof Blob ? data : new Blob([data], { type: 'application/json' }))
				const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'review-plan-' + this.record.planId + '-revision-' + this.record.revision + '.json'; document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url)
			} catch (error) { this.error = this.t('duplicatefinder', 'JSON export could not be downloaded.') } finally { this.busy = false }
		},
	},
}
</script>
<style scoped>
.review-plan { margin-top: 32px; padding: 16px; border: 1px solid var(--color-border); }
p, label { display: block; margin: 8px 0; overflow-wrap: anywhere; }
pre { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 500px; overflow: auto; }
input, textarea, select { max-width: 100%; }
button { margin: 4px; }
[data-saved-member] { border-top: 1px solid var(--color-border); padding: 16px 0; }
[data-saved-member] dl { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 8px 16px; }
[data-saved-member] dt, [data-saved-member] dd { float: none; width: auto; min-width: 0; }
dt { font-weight: 600; }
@media (max-width: 700px) { [data-saved-member] dl { grid-template-columns: minmax(0, 1fr); gap: 4px; } }
dd { margin: 0 0 8px; overflow-wrap: anywhere; white-space: pre-wrap; }
h4 { font-weight: 600; margin-bottom: 12px; }
summary { cursor: pointer; padding: 12px 0; }
h2, h3 { font-weight: 600; margin: 12px 0; }
</style>
