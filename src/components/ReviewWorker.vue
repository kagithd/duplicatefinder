<template>
    <section class="review-worker" aria-labelledby="worker-heading" :aria-busy="busy">
        <h2 id="worker-heading">{{ t('duplicatefinder', 'Check worker') }}</h2>
        <p role="status" aria-live="polite">{{ statusLabel }}</p>
        <p>{{ t('duplicatefinder', 'Start processes queued checks only. Stop finishes the current file. Review unfinished jobs and their findings before selecting work again.') }}</p>
        <div class="review-worker__actions">
            <button type="button" data-worker-refresh :disabled="busy" @click="refresh">{{ t('duplicatefinder', 'Refresh worker status') }}</button>
            <button type="button" data-worker-start :disabled="!canStart" @click="start">{{ t('duplicatefinder', 'Start check worker') }}</button>
            <button type="button" data-worker-stop :disabled="!canStop" @click="stop">{{ t('duplicatefinder', 'Stop after current file') }}</button>
        </div>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="observedAt">{{ t('duplicatefinder', 'Last status observation') }}: {{ new Date(observedAt).toLocaleString() }}. {{ t('duplicatefinder', 'Status is not updated automatically. Job results are listed separately below.') }}</p>
    </section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
    name: 'ReviewWorker',
    data() { return { status: null, busy: false, error: '', observedAt: null, disposed: false } },
    computed: {
        canStart() { return !this.busy && this.status && ['idle', 'finished', 'stopped', 'failed'].includes(this.status.state) },
        canStop() { return !this.busy && this.status?.state === 'running' },
        statusLabel() {
            if (this.busy) return this.t('duplicatefinder', 'Contacting worker control…')
            const labels = { idle: 'No worker process started', running: 'Worker process running', stopping: 'Worker is stopping after the current file',
                finished: 'Worker run finished', stopped: 'Worker stopped', failed: 'Worker process failed; review job results' }
            return this.t('duplicatefinder', labels[this.status?.state] || 'Worker status unknown. Refresh before starting or stopping.')
        },
    },
    beforeDestroy() { this.disposed = true },
    methods: {
        valid(data) {
            if (!data || data.available !== true) return false
            if (data.state === 'idle') return data.runId === null && data.exitCode === null
            if (typeof data.runId !== 'string' || !/^[a-f0-9]{32}$/.test(data.runId)) return false
            if (['running', 'stopping'].includes(data.state)) return data.exitCode === null
            if (['finished', 'stopped'].includes(data.state)) return data.exitCode === 0
            return data.state === 'failed' && (data.exitCode === null || (Number.isInteger(data.exitCode) && data.exitCode !== 0))
        },
        refresh() { return this.request('status') },
        start() { if (this.canStart) return this.request('start') },
        stop() { if (this.canStop) return this.request('stop') },
        async request(action) {
            if (this.busy || this.disposed) return
            const runId = this.status?.runId
            this.busy = true; this.error = ''
            try {
                const base = generateUrl('/apps/duplicatefinder/api/review/worker')
                const response = action === 'status' ? await axios.get(base)
                    : await axios.post(base + '/' + action, action === 'stop' ? { runId } : undefined)
                if (this.disposed) return
                if (!this.valid(response.data)) throw new Error('Invalid worker response')
                this.status = response.data; this.observedAt = Date.now()
            } catch (error) {
                if (this.disposed) return
                this.status = null; this.observedAt = null
                this.error = this.t('duplicatefinder', error.response?.status === 409
                    ? 'The worker run changed. Refresh its status before stopping.'
                    : 'Worker state could not be confirmed. Refresh before trying again; a failed reply does not prove the worker stopped.')
            } finally { if (!this.disposed) this.busy = false }
        },
    },
}
</script>

<style scoped>
.review-worker { margin-block: 24px; padding-block: 16px; border-block: 1px solid var(--color-border); }
.review-worker p { max-width: 75ch; margin-block: 8px; overflow-wrap: anywhere; }
.review-worker__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-block: 12px; }
.review-worker button { white-space: normal; height: auto; min-height: 44px; }
.review-worker button:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
</style>
