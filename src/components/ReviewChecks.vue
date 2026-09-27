<template>
	<section class="review-checks" aria-labelledby="checks-heading">
		<h2 id="checks-heading">
			{{ t('duplicatefinder', 'Original checks') }}
		</h2>
		<p>{{ t('duplicatefinder', 'Select up to 20 indexed references explicitly. The selected metadata revision is retained across groups and pages. Checks read originals without changing files.') }}</p>
		<p>{{ t('duplicatefinder', 'Queued checks wait for a separate worker. Not all formats are supported. A successful decode does not assess visual content.') }}</p>
		<h3>{{ t('duplicatefinder', 'Check selection') }} · {{ selection.length }}/20</h3>
		<ol>
			<li v-for="item in selection" :key="item.appRef">
				<p>{{ item.expected.indexOwner }} · {{ item.expected.indexPath }} · {{ item.appRef }}</p>
				<button type="button" :disabled="busy" @click="remove(item.appRef)">
					{{ t('duplicatefinder', 'Remove from check selection') }}
				</button>
			</li>
		</ol>
		<label><input v-model="preview" type="checkbox" :disabled="busy">{{ t('duplicatefinder', 'Create a scaled first-frame preview when supported') }}</label>
		<button type="button"
			data-check-submit
			:disabled="busy || !selection.length"
			@click="submit">
			{{ t('duplicatefinder', 'Queue selected original checks') }}
		</button>
		<button type="button" :disabled="busy || !selection.length" @click="discard">
			{{ t('duplicatefinder', 'Discard check selection') }}
		</button>
		<p v-if="busy" role="status">
			{{ t('duplicatefinder', 'Loading…') }}
		</p>
		<p v-if="error" role="alert">
			{{ error }}
		</p>
		<h3>{{ t('duplicatefinder', 'Saved check jobs') }}</h3>
		<button type="button" :disabled="busy" @click="loadJobs(0)">
			{{ t('duplicatefinder', 'Load check jobs') }}
		</button>
		<ul>
			<li v-for="job in jobs" :key="job.jobId">
				<button type="button" :disabled="busy" @click="openJob(job.jobId)">
					{{ job.jobId }} · {{ stateLabel(job.state) }} · {{ job.completedCount }}/{{ job.total }}
				</button>
			</li>
		</ul>
		<button type="button" :disabled="busy || nextCursor === null" @click="loadJobs(nextCursor)">
			{{ t('duplicatefinder', 'Next check jobs') }}
		</button>
		<article v-if="record" class="review-checks__job">
			<h3>{{ t('duplicatefinder', 'Check job') }} · {{ record.jobId }}</h3>
			<p>{{ stateLabel(record.state) }} · {{ record.completedCount }}/{{ record.total }} · {{ record.creator }}</p>
			<p>{{ t('duplicatefinder', 'Created') }}: {{ formatTime(record.createdAt) }} · {{ t('duplicatefinder', 'Updated') }}: {{ formatTime(record.updatedAt) }}</p>
			<p v-if="record.state === 'interrupted'">
				{{ t('duplicatefinder', 'Interrupted checks are not retried automatically. Select references explicitly to create a new request.') }}
			</p>
			<p>{{ t('duplicatefinder', 'Original revision has not been rechecked.') }}</p>
			<p>{{ t('duplicatefinder', 'Visual content has not been assessed.') }}</p>
			<button type="button" :disabled="busy" @click="openJob(record.jobId)">
				{{ t('duplicatefinder', 'Refresh check job') }}
			</button>
			<button v-if="['queued', 'running'].includes(record.state)"
				type="button"
				:disabled="busy"
				@click="cancel">
				{{ t('duplicatefinder', 'Cancel remaining checks') }}
			</button>
			<p>{{ t('duplicatefinder', 'Cancellation may allow the current read-only check to finish. No files or artifacts are removed.') }}</p>
			<ol>
				<li v-for="item in record.items" :key="item.appRef">
					<p>{{ item.snapshot.indexOwner }} · {{ item.snapshot.indexPath }} · {{ item.appRef }}</p>
					<p>{{ itemLabel(item.status) }}</p>
					<p v-if="item.reason">
						{{ t('duplicatefinder', 'Technical reason') }}: <code>{{ item.reason }}</code>
					</p>
					<p v-if="item.evidenceId">
						{{ t('duplicatefinder', 'Finding IDs') }}: {{ item.evidenceId }}
					</p>
					<button v-if="item.evidenceId && visibleRefs.includes(item.appRef)"
						type="button"
						data-check-evidence
						@click="$emit('load-evidence', item.appRef)">
						{{ t('duplicatefinder', 'Load latest finding and check usability in References') }}
					</button>
					<p v-else-if="item.evidenceId">
						{{ t('duplicatefinder', 'Open the corresponding reference page to load the latest finding and its usability.') }}
					</p>
				</li>
			</ol>
		</article>
	</section>
</template>
<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
const copy = value => JSON.parse(JSON.stringify(value))
const endpoint = '/apps/duplicatefinder/api/review/checks'
export default {
	name: 'ReviewChecks',
	props: { visibleRefs: { type: Array, default: () => [] } },
	data() {
		return { selection: [], preview: true, signature: '', retryKey: '', busy: false, error: '', jobs: [], nextCursor: null, record: null }
	},
	methods: {
		choose(member) {
			if (this.busy || this.selection.some(item => item.appRef === member.id)) return
			if (this.selection.length >= 20) { this.error = this.t('duplicatefinder', 'Select at most 20 references per check job.'); return }
			this.selection.push({ appRef: member.id, expected: copy(member) }); this.error = ''
		},
		remove(appRef) { if (!this.busy) this.selection = this.selection.filter(item => item.appRef !== appRef) },
		discard() { if (this.busy) return; this.selection = []; this.signature = ''; this.retryKey = ''; this.error = '' },
		async submit() {
			if (this.busy || !this.selection.length) return
			const payload = { members: copy(this.selection), preview: this.preview }
			const signature = JSON.stringify(payload)
			if (signature !== this.signature) { this.signature = signature; this.retryKey = globalThis.crypto?.randomUUID?.() || Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) }
			payload.idempotencyKey = this.retryKey
			this.busy = true; this.error = ''
			try {
				const { data } = await axios.post(generateUrl(endpoint), { payload })
				this.record = data; this.selection = []; this.signature = ''; this.retryKey = ''
			} catch (error) {
				this.error = this.t('duplicatefinder', error.response?.status === 409 ? 'Conflict: the selected revision changed. Your selection is retained. Remove and select a reference again only after reviewing its current metadata.' : 'Check job could not be queued. Your selection is retained for retry.')
			} finally { this.busy = false }
		},
		async loadJobs(cursor) {
			if (this.busy) return
			this.busy = true; this.error = ''
			try { const { data } = await axios.get(generateUrl(endpoint), { params: { cursor, limit: 25 } }); this.jobs = data.items; this.nextCursor = data.nextCursor } catch (error) { this.error = this.t('duplicatefinder', 'Check jobs could not be loaded. Try again.') } finally { this.busy = false }
		},
		async openJob(jobId) {
			if (this.busy) return
			this.busy = true; this.error = ''
			try { const { data } = await axios.get(generateUrl(endpoint + '/' + encodeURIComponent(jobId))); this.record = data } catch (error) { this.error = this.t('duplicatefinder', 'Check job could not be loaded. Try again.') } finally { this.busy = false }
		},
		async cancel() {
			if (this.busy || !this.record || !['queued', 'running'].includes(this.record.state)) return
			this.busy = true; this.error = ''
			try { const { data } = await axios.post(generateUrl(endpoint + '/' + encodeURIComponent(this.record.jobId) + '/cancel')); this.record = data } catch (error) { this.error = this.t('duplicatefinder', 'Cancellation could not be confirmed. Refresh the job before trying again.') } finally { this.busy = false }
		},
		stateLabel(state) {
			return this.t('duplicatefinder', { queued: 'Queued — waiting for worker', running: 'Check running', completed: 'Check job completed', cancelled: 'Check job cancelled', interrupted: 'Check job interrupted' }[state] || 'Unknown')
		},
		itemLabel(status) {
			return this.t('duplicatefinder', { pending: 'Not checked', passed: 'All exposed frames decoded', corrupt: 'Decoding failed', unsupported: 'Format not supported', inaccessible: 'Original could not be read', limit: 'Check stopped at a resource limit', stale: 'Original changed during the check', error: 'Check could not be completed' }[status] || 'Unknown finding')
		},
		formatTime(value) { return new Date(value * 1000).toLocaleString() },
	},
}
</script>
<style scoped>
.review-checks { margin-top: 32px; padding: 16px; border: 1px solid var(--color-border); }
p, label { display: block; margin: 8px 0; overflow-wrap: anywhere; white-space: pre-wrap; }
button { margin: 4px; max-width: 100%; height: auto; overflow-wrap: anywhere; }
h2, h3 { font-weight: 600; margin: 12px 0; }
li { margin: 12px 0; }
.review-checks__job { margin-top: 16px; border-top: 1px solid var(--color-border); }
button:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
</style>
