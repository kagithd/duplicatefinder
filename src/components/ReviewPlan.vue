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
				<p>{{ t('duplicatefinder', 'Visual content has not been assessed.') }} · {{ t('duplicatefinder', 'Finding IDs') }}: {{ decision.evidenceIds.join(', ') || '—' }}</p>
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
			<p>{{ record.creator }} · {{ record.createdAt }} · {{ t('duplicatefinder', 'Not executable') }}</p>
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
			<pre>{{ JSON.stringify(record, null, 2) }}</pre>
		</template>
	</section>
</template>
<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
const copy = value => JSON.parse(JSON.stringify(value))
export default {
	name: 'ReviewPlan',
	props: { hash: { type: String, default: '' } },
	data() {
		return { decisions: [], draftHash: '', note: '', predecessor: null, planId: null, signature: '', retryKey: '', busy: false, error: '', saved: false, plans: [], nextCursor: null, record: null, revisionInput: 1 }
	},
	mounted() { window.addEventListener('beforeunload', this.guardUnload) },
	beforeDestroy() { window.removeEventListener('beforeunload', this.guardUnload) },
	methods: {
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
			this.decisions.push({ appRef: member.id, action, expected: copy(member), evidenceIds: evidence?.entry ? [evidence.entry.id] : [], manualAssessment: { status: 'not_assessed', note: '' }, reason: '' })
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
				this.error = this.t('duplicatefinder', error.response?.status === 409 ? 'Conflict: file metadata or the predecessor revision changed. The draft remains unchanged. Review the conflict before trying again.' : 'Proposal could not be saved. The draft remains available for retry.')
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
			this.decisions = this.record.members.map(member => ({ appRef: member.appRef, action: member.action, expected: copy(member.observed || member.expected), evidenceIds: copy(member.evidenceIds || (member.evidence || []).map(entry => entry.id)), manualAssessment: copy(member.manualAssessment), reason: member.reason || '' })); this.draftHash = this.record.hash; this.note = this.record.note; this.planId = this.record.planId; this.predecessor = this.record.revision; this.signature = ''; this.saved = false
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
h2, h3 { font-weight: 600; margin: 12px 0; }
</style>
