<template>
	<section class="review-evidence-search" :aria-label="t('duplicatefinder', 'Historical findings')">
		<h2>{{ t('duplicatefinder', 'Historical findings') }}</h2>
		<p>{{ t('duplicatefinder', 'Search the latest saved finding per file reference. This does not cover files without a finding and does not verify current file contents.') }}</p>
		<form @submit.prevent="apply">
			<label>{{ t('duplicatefinder', 'Finding status') }}
				<select v-model="status"><option v-for="value in statuses" :key="value" :value="value">{{ statusLabel(value) }}</option></select>
			</label>
			<label>{{ t('duplicatefinder', 'Detected format (optional)') }}<input v-model="format" maxlength="128" placeholder="PNG"></label>
			<button type="submit">{{ t('duplicatefinder', 'Find reports') }}</button>
		</form>
		<p v-if="busy" role="status">{{ t('duplicatefinder', 'Loading findings') }}</p>
		<p v-if="error" role="alert">{{ error }}</p>
		<div v-if="page">
			<p>{{ t('duplicatefinder', 'Applied finding filter') }}: {{ statusLabel(appliedStatus) }} / {{ appliedFormat || t('duplicatefinder', 'All formats') }}</p>
			<p v-if="!page.metadataComplete" role="status">{{ t('duplicatefinder', 'Search metadata is incomplete. Results may be missing until metadata preparation finishes.') }}</p>
			<p v-if="!page.items.length">{{ t('duplicatefinder', 'No matching saved findings. This is not proof that files are healthy.') }}</p>
			<ul>
				<li v-for="item in page.items" :key="item.id">
					<h3>{{ statusLabel(item.historicalStatus) }} &middot; {{ item.historicalFormat || t('duplicatefinder', 'Unknown format') }}</h3>
					<p>{{ t('duplicatefinder', 'Saved at') }}: {{ timestamp(item.createdAt) }} &middot; {{ t('duplicatefinder', 'File reference') }}: {{ item.appRef }}</p>
					<template v-if="item.evidence && item.evidence.record && item.evidence.record.after">
						<p>{{ t('duplicatefinder', 'User at check time') }}: {{ item.evidence.record.after.owner }}</p>
						<p class="review-evidence-search__path">{{ item.evidence.record.after.path }}</p>
						<p>{{ t('duplicatefinder', 'Finding validity') }}: {{ validity(item.evidence.usability) }}</p>
					</template>
					<p v-else>{{ t('duplicatefinder', 'Finding details are unavailable. Do not use this entry as an integrity confirmation.') }}</p>
				</li>
			</ul>
			<button type="button" :disabled="busy" @click="load(0)">{{ t('duplicatefinder', 'First findings page') }}</button>
			<button type="button" :disabled="busy || page.nextCursor === null" @click="load(page.nextCursor)">{{ t('duplicatefinder', 'Next findings page') }}</button>
		</div>
	</section>
</template>
<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
export default {
	name: 'ReviewEvidenceSearch',
	data() { return { status: 'corrupt', format: '', appliedStatus: 'corrupt', appliedFormat: '', page: null, busy: false, error: '', requestId: 0,
		statuses: ['', 'corrupt', 'unsupported', 'inaccessible', 'limit', 'stale', 'error', 'invalid', 'passed'] } },
	beforeDestroy() { this.requestId++ },
	methods: {
		apply() { this.appliedStatus = this.status; this.appliedFormat = this.format; return this.load(0) },
		async load(cursor) {
			const generation = ++this.requestId
			this.page = null; this.error = ''; this.busy = true
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/evidence'), { params: { cursor, limit: 25, status: this.appliedStatus, format: this.appliedFormat } })
				if (generation !== this.requestId) return
				if (!data || data.scope !== 'historical_reports_only' || !Array.isArray(data.items) || typeof data.metadataComplete !== 'boolean') throw new Error('Unexpected response')
				this.page = data
			} catch (e) { if (generation === this.requestId) this.error = this.t('duplicatefinder', 'Could not load findings. Check your session and try again.') }
			finally { if (generation === this.requestId) this.busy = false }
		},
		statusLabel(value) {
			return this.t('duplicatefinder', ({ '': 'All finding statuses', corrupt: 'Corrupt', unsupported: 'Unsupported format', inaccessible: 'Not accessible', limit: 'Check limit reached', stale: 'Changed during check', error: 'Check failed', invalid: 'Invalid report', passed: 'Technical check passed' })[value] || 'Unknown finding status')
		},
		validity(value) { return this.t('duplicatefinder', ({ stale: 'Finding is stale', checker_outdated: 'Checker version is outdated' })[value] || 'Current integrity is not verified') },
		timestamp(value) { return new Date(value * 1000).toLocaleString() },
	},
}
</script>
<style scoped>
.review-evidence-search { margin-block: 24px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.review-evidence-search form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
.review-evidence-search label { display: flex; flex-direction: column; max-width: 100%; }
.review-evidence-search li { padding-block: 12px; border-bottom: 1px solid var(--color-border); }
.review-evidence-search__path { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>