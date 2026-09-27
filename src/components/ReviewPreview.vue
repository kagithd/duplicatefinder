<template>
	<section class="review-preview" :aria-busy="loading">
		<button type="button"
			data-load-preview
			:disabled="loading"
			@click="load">
			{{ t('duplicatefinder', source ? 'Reload historical preview' : 'Load historical preview') }}
		</button>
		<p v-if="loading" role="status">
			{{ t('duplicatefinder', 'Loading historical preview…') }}
		</p>
		<p v-else-if="unavailable" role="status">
			{{ t('duplicatefinder', 'Historical preview is unavailable. Use the button to retry.') }}
		</p>
		<p v-else-if="error" role="alert">
			{{ t('duplicatefinder', 'Historical preview could not be loaded. Use the button to retry.') }}
		</p>
		<figure v-if="source">
			<img :src="source"
				:width="width"
				:height="height"
				:alt="t('duplicatefinder', 'Scaled first frame from the historical original')"
				@load="imageLoaded"
				@error="imageError">
			<figcaption>
				<p>{{ t('duplicatefinder', 'Historical original preview') }} · {{ timestamp }}</p>
				<p>{{ t('duplicatefinder', 'The preview shows only the first frame. The original check decoded all exposed frames.') }}</p>
				<p>{{ t('duplicatefinder', 'Original revision has not been rechecked.') }} <span v-if="!allowAssessment">{{ t('duplicatefinder', 'Visual content has not been assessed.') }}</span></p>
			</figcaption>
			<div v-if="allowAssessment">
				<p>{{ t('duplicatefinder', 'Assess only this scaled first frame. This does not assess other frames or the current original.') }}</p>
				<button type="button" data-assess-visible :disabled="!imageReady || disabled" @click="assess('content_visible')">{{ t('duplicatefinder', 'Content visible in this preview') }}</button>
				<button type="button" data-assess-problem :disabled="!imageReady || disabled" @click="assess('problem')">{{ t('duplicatefinder', 'Problem visible in this preview') }}</button>
			</div>
		</figure>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ReviewPreview',
	props: {
		allowAssessment: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
		appRef: { type: [Number, String], required: true },
		evidenceId: { type: [Number, String], required: true },
	},
	data() {
		return { imageReady: false, assessmentSource: null, source: '', width: 0, height: 0, createdAt: null, loading: false, unavailable: false, error: false, request: 0 }
	},
	computed: {
		timestamp() { return new Date(this.createdAt * 1000).toLocaleString() },
	},
	watch: {
		appRef: 'clear',
		evidenceId: 'clear',
	},
	beforeDestroy() { this.clear() },
	methods: {
		clear() {
			this.request++
			this.imageReady = false
			this.assessmentSource = null
			this.source = ''
			this.createdAt = null
			this.width = 0
			this.height = 0
			this.loading = false
			this.error = false
			this.unavailable = false
		},
		imageLoaded(event) {
			this.imageReady = event.target.getAttribute('src') === this.source && event.target.naturalWidth > 0
		},
		assess(status) {
			if (!this.allowAssessment || this.disabled || !this.imageReady || !this.assessmentSource || !['content_visible', 'problem'].includes(status)) return
			this.$emit('assessed', { appRef: Number(this.appRef), status, source: { ...this.assessmentSource } })
		},
		imageError() {
			this.imageReady = false
			this.assessmentSource = null
			this.source = ''
			this.error = true
		},
		validate(data, appRef, evidenceId) {
			const descriptor = data.record?.descriptor
			if (String(data.appRef) !== String(appRef) || String(data.evidenceId) !== String(evidenceId)
				|| !Number.isSafeInteger(data.id) || data.id < 1 || !/^[a-f0-9]{64}$/.test(data.record?.sha256 || '')
				|| data.record?.schemaVersion !== 1 || !Number.isFinite(data.createdAt)
				|| data.nativeFreshness !== 'not_rechecked' || data.validityScope !== 'original_first_frame_scaled'
				|| data.visualAssessment !== 'not_provided' || !descriptor
				|| descriptor.status !== 'available' || descriptor.scope !== 'original_first_frame_scaled'
				|| descriptor.mime !== 'image/jpeg' || descriptor.frameIndex !== 0
				|| !Number.isInteger(descriptor.width) || descriptor.width < 1 || descriptor.width > 512
				|| !Number.isInteger(descriptor.height) || descriptor.height < 1 || descriptor.height > 512) throw new Error('Invalid preview')
			const encoded = descriptor.imageBase64
			if (typeof encoded !== 'string' || encoded.length > 87384 || encoded.length < 8
				|| encoded.length % 4 !== 0 || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(encoded)) throw new Error('Invalid image encoding')
			const bytes = atob(encoded)
			if (bytes.length > 65536 || btoa(bytes) !== encoded || bytes.charCodeAt(0) !== 255
				|| bytes.charCodeAt(1) !== 216 || bytes.charCodeAt(2) !== 255
				|| bytes.charCodeAt(bytes.length - 2) !== 255 || bytes.charCodeAt(bytes.length - 1) !== 217) throw new Error('Invalid JPEG')
			return descriptor
		},
		async load() {
			if (this.loading) return
			this.clear()
			const request = this.request
			const appRef = this.appRef
			const evidenceId = this.evidenceId
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/members/' + encodeURIComponent(appRef) + '/evidence/' + encodeURIComponent(evidenceId) + '/preview'))
				if (request !== this.request) return
				const descriptor = this.validate(data, appRef, evidenceId)
				this.width = descriptor.width
				this.height = descriptor.height
				this.createdAt = data.createdAt
				this.assessmentSource = { kind: 'original_preview', evidenceId: Number(evidenceId), previewId: data.id, sha256: data.record.sha256, scope: data.validityScope }
				this.source = 'data:image/jpeg;base64,' + descriptor.imageBase64
			} catch (error) {
				if (request !== this.request) return
				this.unavailable = error.response?.status === 404
				this.error = !this.unavailable
			} finally {
				if (request === this.request) this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.review-preview { margin-top: 12px; }
figure { margin: 12px 0; }
img { display: block; max-width: 100%; height: auto; object-fit: contain; }
figcaption { max-width: 65ch; }
figcaption p { margin: 8px 0; }
</style>
