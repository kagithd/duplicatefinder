<template>
    <section class="review-missing" :aria-label="t('duplicatefinder', 'Without saved findings')">
        <h2>{{ t('duplicatefinder', 'Without saved findings') }}</h2>
        <p>{{ t('duplicatefinder', 'Indexed duplicate candidates without any saved finding. Old or invalid findings are excluded. This is not a complete list of files needing a new check.') }}</p>
        <form @submit.prevent="apply">
            <label>{{ t('duplicatefinder', 'Indexed user (exact)') }}<input v-model="owner" maxlength="255"></label>
            <label>{{ t('duplicatefinder', 'Indexed folder (absolute path)') }}<input v-model="folder" maxlength="4096"></label>
            <label>{{ t('duplicatefinder', 'Indexed MIME type (exact, optional)') }}<input v-model="mime" maxlength="200" placeholder="image/png"></label>
            <button type="submit">{{ t('duplicatefinder', 'Find files without reports') }}</button>
        </form>
        <p>{{ t('duplicatefinder', 'Paths and MIME types come from the stored index. Opening a reference loads current metadata before you can select a check or decision.') }}</p>
        <p v-if="busy" role="status">{{ t('duplicatefinder', 'Loading references…') }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <div v-if="page">
            <p>{{ t('duplicatefinder', 'Applied scope') }}: {{ applied.owner || t('duplicatefinder', 'All users') }} · {{ applied.folder || t('duplicatefinder', 'All folders') }} · {{ applied.mime || t('duplicatefinder', 'All formats') }}</p>
            <p v-if="!page.items.length">{{ t('duplicatefinder', 'No matching indexed candidates without reports. This does not establish current file integrity or index completeness.') }}</p>
            <ul>
                <li v-for="item in page.items" :key="item.id">
                    <h3>{{ item.indexOwner || t('duplicatefinder', 'Unknown index owner') }}</h3>
                    <p class="review-missing__path">{{ item.indexPath }}</p>
                    <p>{{ item.indexMime || t('duplicatefinder', 'Unknown format') }} · {{ t('duplicatefinder', 'File reference') }}: {{ item.id }}</p>
                    <button type="button" data-open-reference :disabled="opening" @click="$emit('open-reference', item.id)">{{ t('duplicatefinder', 'Open current reference') }}</button>
                </li>
            </ul>
            <button type="button" :disabled="busy" @click="load(0)">{{ t('duplicatefinder', 'First page') }}</button>
            <button type="button" :disabled="busy || page.nextCursor === null" @click="load(page.nextCursor)">{{ t('duplicatefinder', 'Next references') }}</button>
        </div>
    </section>
</template>
<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
export default {
    name: 'ReviewMissingFindings',
    props: { opening: { type: Boolean, default: false } },
    data() { return { owner: '', folder: '', mime: '', applied: { owner: '', folder: '', mime: '' }, page: null, busy: false, error: '', requestId: 0 } },
    beforeDestroy() { this.requestId++ },
    methods: {
        apply() { this.applied = { owner: this.owner, folder: this.folder, mime: this.mime }; return this.load(0) },
        async load(cursor) {
            const request = ++this.requestId
            this.page = null; this.error = ''; this.busy = true
            try {
                const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/missing-findings'), { params: { cursor, pageSize: 25, ...this.applied } })
                if (request !== this.requestId) return
                if (!data || data.scope !== 'indexed_candidates_without_reports' || !Array.isArray(data.items)) throw new Error('Unexpected response')
                this.page = data
            } catch (error) {
                if (request === this.requestId) this.error = this.t('duplicatefinder', 'Could not load files without reports. Check your filters and session, then try again.')
            } finally { if (request === this.requestId) this.busy = false }
        },
    },
}
</script>
<style scoped>
.review-missing { margin-block: 24px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.review-missing form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
.review-missing label { display: flex; flex-direction: column; max-width: 100%; }
.review-missing li { padding-block: 12px; border-bottom: 1px solid var(--color-border); }
.review-missing p { overflow-wrap: anywhere; }
.review-missing__path { white-space: pre-wrap; }
</style>
