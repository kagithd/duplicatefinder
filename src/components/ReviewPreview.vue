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
				@error="imageError">
			<figcaption>
				<p>{{ t('duplicatefinder', 'Historical original preview') }} · {{ timestamp }}</p>
				<p>{{ t('duplicatefinder', 'The preview shows only the first frame. The original check decoded all exposed frames.') }}</p>
				<p>{{ t('duplicatefinder', 'Original revision has not been rechecked.') }} {{ t('duplicatefinder', 'Visual content has not been assessed.') }}</p>
			</figcaption>
		</figure>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ReviewPreview',
	props: {
		appRef: { type: [Number, String], required: true },
		evidenceId: { type: [Number, String], required: true },
	},
	data() {
		return { source: '', width: 0, height: 0, createdAt: null, loading: false, unavailable: false, error: false, request: 0 }
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
			this.source = ''
			this.createdAt = null
			this.width = 0
			this.height = 0
			this.loading = false
			this.error = false
			this.unavailable = false
		},
		imageError() {
			this.source = ''
			this.error = true
		},
		validate(data, appRef, evidenceId) {
			const descriptor = data.record?.descriptor
			if (String(data.appRef) !== String(appRef) || String(data.evidenceId) !== String(evidenceId)
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
