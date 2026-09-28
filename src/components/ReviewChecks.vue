<template>
	<section class="review-checks" aria-labelledby="checks-heading">
		<h2 id="checks-heading">
			{{ t('duplicatefinder', 'Original checks') }}
		</h2>
		<p>{{ t('duplicatefinder', 'Select up to 20 indexed references explicitly. The selected metadata revision is retained across groups and pages. Checks read originals without changing files.') }}</p>
		<p>{{ t('duplicatefinder', 'Queued checks wait for a separate worker. Not all formats are supported. A successful decode does not assess visual content.') }}</p>
		<label>{{ t('duplicatefinder', 'Check type') }}
            <select v-model="kind" data-check-kind :disabled="busy">
                <option value="image">{{ t('duplicatefinder', 'Image format check') }}</option>
                <option value="content">{{ t('duplicatefinder', 'Read file bytes and calculate SHA-256') }}</option>
            </select>
        </label>
        <p v-if="kind === 'content'">{{ t('duplicatefinder', 'Content checks read selected files of any type. Image settings are retained but are not used for this request.') }}</p>
        <h3>{{ t('duplicatefinder', 'Check selection') }} · {{ selection.length }}/20</h3>
		<ol>
			<li v-for="item in selection" :key="item.appRef">
				<p>{{ item.expected.indexOwner }} · {{ item.expected.indexPath }} · {{ item.appRef }}</p>

                <label v-if="kind === 'image'"><input type="checkbox" :checked="!!item.detail" :disabled="busy" @change="toggleDetail(item, $event.target.checked)">{{ t('duplicatefinder', 'Request a native-resolution detail') }}</label>
                <fieldset v-if="kind === 'image' && item.detail" class="review-checks__detail-fields" :disabled="busy">
                    <legend>{{ t('duplicatefinder', 'Selected frame and pixel region') }}</legend>
                    <label>{{ t('duplicatefinder', 'Frame or page (starting at 1)') }}<input data-detail-frame type="number" min="1" max="2147483648" :value="item.detail.frameIndex + 1" @input="item.detail.frameIndex = Number($event.target.value) - 1"></label>
                    <label>{{ t('duplicatefinder', 'Left (pixels)') }}<input v-model.number="item.detail.x" type="number" min="0" max="2147483647"></label>
                    <label>{{ t('duplicatefinder', 'Top (pixels)') }}<input v-model.number="item.detail.y" type="number" min="0" max="2147483647"></label>
                    <label>{{ t('duplicatefinder', 'Width (1–512 pixels)') }}<input v-model.number="item.detail.width" type="number" min="1" max="512"></label>
                    <label>{{ t('duplicatefinder', 'Height (1–512 pixels)') }}<input v-model.number="item.detail.height" type="number" min="1" max="512"></label>
                    <p>{{ t('duplicatefinder', 'Coordinates start at zero in the oriented original. Regions outside the image are unavailable. Every request checks the original again and creates its own finding.') }}</p>
                </fieldset>
				<button type="button" :disabled="busy" @click="remove(item.appRef)">
					{{ t('duplicatefinder', 'Remove from check selection') }}
				</button>
			</li>
		</ol>
		<label v-if="kind === 'image'"><input v-model="preview" type="checkbox" :disabled="busy">{{ t('duplicatefinder', 'Create a scaled first-frame preview when supported') }}</label>
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
					{{ kindLabel(job.kind) }} · {{ job.jobId }} · {{ stateLabel(job.state) }} · {{ job.completedCount }}/{{ job.total }}
				</button>
			</li>
		</ul>
		<button type="button" :disabled="busy || nextCursor === null" @click="loadJobs(nextCursor)">
			{{ t('duplicatefinder', 'Next check jobs') }}
		</button>
		<article v-if="record" class="review-checks__job">
			<h3>{{ t('duplicatefinder', 'Check job') }} · {{ record.jobId }}</h3>
            <p>{{ kindLabel(record.kind) }}</p>
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
                    <button v-if="record.kind === 'content' && item.contentEvidenceId" type="button" data-content-load :disabled="busy" @click="loadContent(item)">{{ t('duplicatefinder', 'Load stored content finding') }}</button>
                    <section v-if="contentFindings[contentKey(item)]" data-content-finding>
                        <p>{{ t('duplicatefinder', 'Historical content finding') }} · {{ item.contentEvidenceId }} · {{ formatTime(contentFindings[contentKey(item)].createdAt) }}</p>
                        <p>SHA-256: <code>{{ contentFindings[contentKey(item)].record.report.digest }}</code></p>
                        <p>{{ t('duplicatefinder', 'File format was not checked. This finding does not authorize removal.') }}</p>
                        <p>{{ t('duplicatefinder', 'Original revision has not been rechecked.') }}</p>
                    </section>
					<p v-if="item.reason">
						{{ t('duplicatefinder', 'Technical reason') }}: <code>{{ item.reason }}</code>
					</p>

                    <template v-if="item.detail">
                        <p>{{ t('duplicatefinder', 'Requested detail') }}: {{ t('duplicatefinder', 'Frame or page') }} {{ item.detail.frameIndex + 1 }} · x={{ item.detail.x }}, y={{ item.detail.y }} · {{ item.detail.width }} × {{ item.detail.height }} px</p>
                        <p v-if="item.status !== 'pending' && item.detailStatus !== 'available'">{{ t('duplicatefinder', 'No detail image is available for this request. A successful decode alone does not produce a usable detail.') }}</p>
                        <button v-if="item.detailId && item.evidenceId" type="button" data-detail-load :disabled="busy" @click="loadDetail(item)">{{ t('duplicatefinder', 'Load historical detail image') }}</button>
                        <figure v-if="detailImages[detailKey(item)]">
                            <div class="review-checks__detail-viewport" tabindex="0" :aria-label="t('duplicatefinder', 'Native detail pixels; scroll to inspect the full region')">
                            <img data-detail-image :src="detailImages[detailKey(item)].source" :width="item.detail.width" :height="item.detail.height" :alt="t('duplicatefinder', 'Historical selected-frame detail')" @load="detailLoaded(item)" @error="detailFailed(item)">
                            </div>
                            <figcaption>{{ t('duplicatefinder', 'Oriented source dimensions') }}: {{ detailImages[detailKey(item)].descriptor.sourceWidth }} × {{ detailImages[detailKey(item)].descriptor.sourceHeight }} px. {{ t('duplicatefinder', 'Historical pixels: the current original and visual content have not been verified by viewing this region.') }}</figcaption>
                            <p>{{ t('duplicatefinder', 'This assessment covers only this historical frame region. First select the same finding in a proposal.') }}</p>
                            <button type="button" data-detail-assess-visible :disabled="busy || !detailImages[detailKey(item)].loaded" @click="assessDetail(item, 'content_visible')">{{ t('duplicatefinder', 'Content visible in this detail') }}</button>
                            <button type="button" data-detail-assess-problem :disabled="busy || !detailImages[detailKey(item)].loaded" @click="assessDetail(item, 'problem')">{{ t('duplicatefinder', 'Problem visible in this detail') }}</button>
                            <p v-if="detailNotices[detailKey(item)]" role="status">{{ detailNotices[detailKey(item)] }}</p>
                        </figure>
                    </template>
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
		return { selection: [], kind: 'image', contentFindings: {}, preview: true, signature: '', retryKey: '', busy: false, error: '', jobs: [], nextCursor: null, record: null, detailImages: {}, detailGeneration: 0, detailNotices: {} }
	},

    watch: {
        record() { this.contentFindings = {}; this.detailGeneration++; this.detailImages = {}; this.detailNotices = {} },
    },
    beforeDestroy() { this.detailGeneration++ },
    methods: {
        kindLabel(kind) { return this.t('duplicatefinder', kind === 'content' ? 'Read file bytes and calculate SHA-256' : 'Image format check') },
        contentKey(item) { return item.appRef + ':' + item.contentEvidenceId },
        async loadContent(item) {
            if (this.busy || this.record?.kind !== 'content' || !this.record.items.includes(item)
                || !Number.isSafeInteger(item.contentEvidenceId) || item.contentEvidenceId < 1 || item.contentEvidenceId >= Number.MAX_SAFE_INTEGER) return
            const key = this.contentKey(item), generation = this.detailGeneration
            this.$delete(this.contentFindings, key); this.busy = true; this.error = ''
            try {
                const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/members/' + item.appRef + '/content-evidence'), { params: { cursor: item.contentEvidenceId + 1, pageSize: 1 } })
                if (generation !== this.detailGeneration) return
                const finding = data.items?.[0], r = finding?.record?.report
                if (!finding || finding.id !== item.contentEvidenceId || finding.appRef !== item.appRef || !r
                    || r.status !== 'read' || r.algorithm !== 'sha256' || !/^[a-f0-9]{64}$/.test(r.digest)
                    || r.formatStatus !== 'not_checked' || r.actionEligible !== false || finding.actionEligible !== false
                    || JSON.stringify(r.before) !== JSON.stringify(item.snapshot) || JSON.stringify(r.after) !== JSON.stringify(item.snapshot)) throw Error('Wrong content finding')
                this.$set(this.contentFindings, key, finding)
            } catch (error) {
                if (generation === this.detailGeneration) this.error = this.t('duplicatefinder', 'Content finding could not be loaded or did not match this check.')
            } finally { if (generation === this.detailGeneration) this.busy = false }
        },
        toggleDetail(item, enabled) {
            if (this.busy) return
            if (enabled) this.$set(item, 'detail', { frameIndex: 0, x: 0, y: 0, width: 128, height: 128 })
            else this.$delete(item, 'detail')
        },
        validDetail(value) {
            return value && ['frameIndex', 'x', 'y', 'width', 'height'].every(field =>
                Number.isInteger(value[field]) && value[field] >= (['width', 'height'].includes(field) ? 1 : 0)
                && value[field] <= (['width', 'height'].includes(field) ? 512 : 2147483647))
        },
        setDetailNotice(assessment, message) {
            const key = this.detailKey({ appRef: assessment.appRef, evidenceId: assessment.source.evidenceId, detailId: assessment.source.detailId })
            this.$set(this.detailNotices, key, message)
        },
        detailLoaded(item) {
            const image = this.detailImages[this.detailKey(item)]
            if (image) image.loaded = true
        },
        assessDetail(item, status) {
            const image = this.detailImages[this.detailKey(item)]
            if (this.busy || !this.record?.items.includes(item) || !image?.loaded || !['content_visible', 'problem'].includes(status)) return
            this.$emit('assessed-detail', { appRef: item.appRef, status, source: copy(image.assessmentSource) })
        },
        detailKey(item) { return item.appRef + ':' + item.evidenceId + ':' + item.detailId },
        detailFailed(item) { this.$delete(this.detailImages, this.detailKey(item)); this.error = this.t('duplicatefinder', 'Detail image could not be displayed.') },
        async loadDetail(item) {
            if (this.busy || !this.record?.items.includes(item)) return
            const record = this.record
            const generation = ++this.detailGeneration
            const key = this.detailKey(item)
            this.$delete(this.detailImages, key)
            this.busy = true; this.error = ''
            try {
                const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/members/' + item.appRef + '/evidence/' + item.evidenceId + '/details/' + item.detailId))
                if (generation !== this.detailGeneration || this.record !== record) return
                const d = data.record?.descriptor
                if (data.id !== item.detailId || data.appRef !== item.appRef || data.evidenceId !== item.evidenceId
                    || data.nativeFreshness !== 'not_rechecked' || data.validityScope !== 'original_selected_frame_region'
                    || data.visualAssessment !== 'not_provided' || data.record?.schemaVersion !== 1
                    || !/^[a-f0-9]{64}$/.test(data.record?.sha256 || '') || !d
                    || d.status !== 'available' || d.scope !== 'original_selected_frame_region' || d.mime !== 'image/png'
                    || !['RGB', 'RGBA'].includes(d.rasterMode) || d.frameIndex !== item.detail.frameIndex
                    || !this.validDetail(item.detail) || !d.region
                    || !['x', 'y', 'width', 'height'].every(field => d.region[field] === item.detail[field])
                    || d.width !== item.detail.width || d.height !== item.detail.height
                    || !['sourceWidth', 'sourceHeight'].every(field => Number.isInteger(d[field]) && d[field] > 0 && d[field] <= 2147483647)
                    || d.region.x + d.width > d.sourceWidth || d.region.y + d.height > d.sourceHeight
                    || typeof d.imageBase64 !== 'string' || d.imageBase64.length > 2796204) throw Error('Invalid detail')
                const bytes = atob(d.imageBase64)
                const uint32 = offset => ((bytes.charCodeAt(offset) * 16777216) + (bytes.charCodeAt(offset + 1) << 16) + (bytes.charCodeAt(offset + 2) << 8) + bytes.charCodeAt(offset + 3))
                if (bytes.length < 33 || bytes.length > 2097152 || btoa(bytes) !== d.imageBase64
                    || bytes.slice(0, 8) !== '\x89PNG\r\n\x1a\n' || bytes.slice(12, 16) !== 'IHDR'
                    || uint32(16) !== d.width || uint32(20) !== d.height || bytes.charCodeAt(24) !== 8
                    || bytes.charCodeAt(25) !== (d.rasterMode === 'RGB' ? 2 : 6)) throw Error('Invalid PNG')
                this.$set(this.detailImages, key, { descriptor: d, source: 'data:image/png;base64,' + d.imageBase64, loaded: false,
                    assessmentSource: { kind: 'original_detail', evidenceId: data.evidenceId, detailId: data.id, sha256: data.record.sha256,
                        scope: data.validityScope, frameIndex: d.frameIndex, region: copy(d.region) } })
            } catch (error) {
                if (generation === this.detailGeneration) this.error = this.t('duplicatefinder', 'Detail image could not be loaded or did not match the requested region.')
            } finally { if (generation === this.detailGeneration) this.busy = false }
        },
		choose(member) {
			if (this.busy || this.selection.some(item => item.appRef === member.id)) return
			if (this.selection.length >= 20) { this.error = this.t('duplicatefinder', 'Select at most 20 references per check job.'); return }
			this.selection.push({ appRef: member.id, expected: copy(member) }); this.error = ''
		},
		remove(appRef) { if (!this.busy) this.selection = this.selection.filter(item => item.appRef !== appRef) },
		discard() { if (this.busy) return; this.selection = []; this.signature = ''; this.retryKey = ''; this.error = '' },
		async submit() {
			if (this.busy || !this.selection.length) return
			if (this.kind === 'image' && this.selection.some(item => item.detail && !this.validDetail(item.detail))) {
                this.error = this.t('duplicatefinder', 'Use whole pixel coordinates, a frame starting at 1, and dimensions from 1 to 512.')
                return
            }
            const members = copy(this.selection)
            if (this.kind === 'content') members.forEach(item => { delete item.detail })
            const payload = { members, preview: this.kind === 'content' ? false : this.preview, kind: this.kind }
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
			return this.t('duplicatefinder', { read: 'File bytes read; format not checked', pending: 'Not checked', passed: 'All exposed frames decoded', corrupt: 'Decoding failed', unsupported: 'Format not supported', inaccessible: 'Original could not be read', limit: 'Check stopped at a resource limit', stale: 'Original changed during the check', error: 'Check could not be completed' }[status] || 'Unknown finding')
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

.review-checks__detail-fields { display: flex; flex-wrap: wrap; gap: 12px; padding: 12px; border: 1px solid var(--color-border); }
.review-checks__detail-fields label { min-width: 0; }
.review-checks__detail-fields input { display: block; width: 130px; max-width: 100%; }
.review-checks__detail-fields p { flex-basis: 100%; }
.review-checks__detail-viewport { max-width: 100%; max-height: 70vh; overflow: auto; }
[data-detail-image] { max-width: none; background: repeating-conic-gradient(#ddd 0% 25%, #fff 0% 50%) 0 0 / 16px 16px; }
figcaption { overflow-wrap: anywhere; margin: 8px 0; }
</style>
