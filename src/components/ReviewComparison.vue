<template>
    <section class="review-comparison" :aria-label="t('duplicatefinder', 'Image comparison')">
        <h2 ref="heading" tabindex="-1">{{ t('duplicatefinder', 'Image comparison') }} · {{ items.length }}/4</h2>
        <p>{{ t('duplicatefinder', 'Compare up to four historical first-frame previews. Selection stays across pages. This does not verify current originals or assess other frames.') }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="!items.length">{{ t('duplicatefinder', 'Load a successful finding, then add its reference to the comparison.') }}</p>
        <button type="button" :disabled="!items.length || loading" @click="loadAll">{{ t('duplicatefinder', 'Load selected previews') }}</button>
        <p v-if="loading" role="status">{{ t('duplicatefinder', 'Loading selected previews…') }}</p>
        <button type="button" :disabled="!items.length" @click="clear">{{ t('duplicatefinder', 'Clear comparison') }}</button>
        <div class="review-comparison__grid">
            <article v-for="item in items" :key="item.appRef + ':' + item.evidenceId">
                <h3>{{ item.owner || t('duplicatefinder', 'Unknown') }}</h3>
                <p class="review-comparison__path">{{ item.path }}</p>
                <p>{{ t('duplicatefinder', 'User and path at check time') }}</p>
                <p>{{ t('duplicatefinder', 'File reference') }}: {{ item.appRef }} · {{ t('duplicatefinder', 'Finding IDs') }}: {{ item.evidenceId }}</p>
                <p>{{ t('duplicatefinder', 'Decoded frames') }}: {{ item.frames }}</p>
                <ReviewPreview ref="previews" :app-ref="item.appRef" :evidence-id="item.evidenceId" />
                <button type="button" @click="remove(item.appRef)">{{ t('duplicatefinder', 'Remove from comparison') }}</button>
            </article>
        </div>
    </section>
</template>
<script>
import ReviewPreview from './ReviewPreview.vue'
export default {
    name: 'ReviewComparison',
    components: { ReviewPreview },
    data() { return { items: [], error: '', loading: false, loadGeneration: 0 } },
    beforeDestroy() { this.loadGeneration++ },
    methods: {
        async loadAll() {
            if (this.loading) return
            const generation = ++this.loadGeneration
            this.loading = true
            try {
                await this.$nextTick()
                if (generation !== this.loadGeneration) return
                await Promise.all((this.$refs.previews || []).map(preview => preview.load()))
            } finally { if (generation === this.loadGeneration) this.loading = false }
        },
        choose(member, entry) {
            this.error = ''
            if (!Number.isSafeInteger(member?.id) || member.id < 1 || !Number.isSafeInteger(entry?.id) || entry.id < 1
                || entry.record?.report?.status !== 'passed' || !entry.record?.after) {
                this.error = this.t('duplicatefinder', 'Load a successful finding before adding a comparison.')
                return
            }
            const existing = this.items.find(item => item.appRef === member.id)
            if (existing) {
                if (existing.evidenceId !== entry.id) this.error = this.t('duplicatefinder', 'Another finding for this reference is already selected. Remove it before choosing a different finding.')
                return
            }
            if (this.items.length >= 4) {
                this.error = this.t('duplicatefinder', 'Four references are already selected. Remove one before adding another.')
                return
            }
            this.items.push({ appRef: member.id, evidenceId: entry.id, owner: entry.record.after.owner,
                path: entry.record.after.path, frames: entry.record.report.frames_decoded })
            this.$nextTick(() => { this.$refs.heading?.focus(); this.$refs.heading?.scrollIntoView?.({ block: 'start' }) })
        },
        remove(appRef) { this.items = this.items.filter(item => item.appRef !== appRef); this.error = '' },
        clear() { this.loadGeneration++; this.loading = false; this.items = []; this.error = '' },
    },
}
</script>
<style scoped>
.review-comparison { margin-block: 24px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.review-comparison__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
.review-comparison article { min-width: 0; padding: 12px; border: 1px solid var(--color-border); }
.review-comparison__path { overflow-wrap: anywhere; white-space: pre-wrap; }
.review-comparison h2 { scroll-margin-top: calc(var(--header-height, 50px) + 16px); }
.review-comparison h3 { font-weight: 600; }
@media (max-width: 800px) { .review-comparison__grid { grid-template-columns: minmax(0, 1fr); } }
</style>
