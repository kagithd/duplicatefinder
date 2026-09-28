<template>
	<details class="review-shares">
		<summary>{{ t('duplicatefinder', 'Sharing details') }}</summary>
		<p>{{ t('duplicatefinder', 'Incomplete sharing observation. Load one share type and ancestor page explicitly. Other providers are not covered. Group membership must be loaded separately. This is not an execution authorization.') }}</p>
		<label>{{ t('duplicatefinder', 'Share type') }}
			<select v-model.number="type" :disabled="busy || disabled" @change="reset">
				<option :value="0">{{ t('duplicatefinder', 'User shares') }}</option>
				<option :value="1">{{ t('duplicatefinder', 'Group shares') }}</option>
				<option :value="3">{{ t('duplicatefinder', 'Public links') }}</option>
			</select>
		</label>
		<button type="button" :disabled="busy || disabled" @click="load(0, 0)">{{ t('duplicatefinder', 'Load direct shares') }}</button>
		<p v-if="busy" role="status">{{ t('duplicatefinder', 'Loading sharing details') }}</p>
		<p v-if="error" role="alert">{{ error }}</p>
		<section v-if="page" :aria-label="t('duplicatefinder', 'Observed sharing page')">
			<p>{{ t('duplicatefinder', 'Owner context path') }}: {{ page.ownerPath }}</p>
			<p>{{ t('duplicatefinder', 'Share anchor') }}: {{ page.anchor.path }} · {{ t('duplicatefinder', 'Ancestor depth') }}: {{ page.anchor.depth }}</p>
			<p>{{ t('duplicatefinder', 'Observed at') }}: {{ timestamp(page.observedAt) }}</p>
			<p v-if="!page.items.length">{{ t('duplicatefinder', 'No entries on this page. This does not mean there are no other shares.') }}</p>
			<ul>
				<li v-for="item in page.items" :key="item.id">
					<p>{{ t('duplicatefinder', 'Share reference') }}: {{ item.id }} · {{ item.recipient || t('duplicatefinder', 'Public link') }}</p>
					<p>{{ t('duplicatefinder', 'Verified recipient path') }}: {{ item.recipientPath || t('duplicatefinder', 'Not determined') }}</p>
					<p>{{ pathStatus(item.pathStatus) }}</p>
					<p>{{ t('duplicatefinder', 'Share permissions (bitmask)') }}: {{ item.permissions }} · {{ t('duplicatefinder', 'Effective file permissions (bitmask)') }}: {{ item.effectivePermissions == null ? '—' : item.effectivePermissions }}</p>
					<p>{{ t('duplicatefinder', 'Deletion permission at observed recipient path') }}: {{ permission(item.deletable) }}</p>
					<p>{{ t('duplicatefinder', 'Recorded expiration') }}: {{ item.expiration || '—' }} · {{ t('duplicatefinder', 'Recorded share status') }}: {{ item.status }}</p>
                    <button v-if="page.type === 1" type="button" :disabled="busy || groupBusy || disabled" @click="loadGroup(item, 0)">{{ t('duplicatefinder', 'Load group members') }}</button>
				</li>
			</ul>
            <p v-if="groupBusy" role="status">{{ t('duplicatefinder', 'Loading group members') }}</p>
            <p v-if="groupError" role="alert">{{ groupError }}</p>
            <section v-if="groupPage" :aria-label="t('duplicatefinder', 'Observed group members')">
                <h4>{{ groupPage.groupId }} · {{ t('duplicatefinder', 'Share reference') }} {{ groupPage.shareId }}</h4>
                <p>{{ t('duplicatefinder', 'Observed at') }}: {{ timestamp(groupPage.observedAt) }} · {{ t('duplicatefinder', 'Page offset') }} {{ groupQuery.offset }}</p>
                <p>{{ t('duplicatefinder', 'Membership does not prove file access. Recipient paths, effective permissions and other access paths remain unverified. Only this page is observed; pages are not an atomic snapshot.') }}</p>
                <p v-if="groupPage.status === 'backend_not_qualified'">{{ t('duplicatefinder', 'This group provider has not been qualified for bounded membership inspection. No members were queried.') }}</p>
                <template v-else>
                    <p v-if="!groupPage.members.length">{{ t('duplicatefinder', 'No members on this observed page.') }}</p>
                    <ul><li v-for="member in groupPage.members" :key="member.uid">{{ member.uid }} · {{ t('duplicatefinder', member.enabled ? 'Account enabled' : 'Account disabled') }} · {{ t('duplicatefinder', 'File access not verified') }}</li></ul>
                    <button v-if="groupPage.nextOffset !== null" type="button" :disabled="busy || groupBusy || disabled" @click="loadGroup({ id: groupPage.shareId }, groupPage.nextOffset)">{{ t('duplicatefinder', 'Next membership page') }}</button>
                    <button v-if="groupQuery.offset > 0" type="button" :disabled="busy || groupBusy || disabled" @click="loadGroup({ id: groupPage.shareId }, Math.max(0, groupQuery.offset - 25))">{{ t('duplicatefinder', 'Previous membership page') }}</button>
                </template>
                <button v-if="selectable" type="button" :disabled="busy || groupBusy || disabled" @click="selectGroup">{{ t('duplicatefinder', 'Include this membership page in proposal') }}</button>
            </section>
			<button v-if="page.nextOffset !== null" type="button" :disabled="busy || disabled" @click="load(page.anchor.depth, page.nextOffset)">{{ t('duplicatefinder', 'Next share page') }}</button>
			<button v-if="page.nextDepth !== null" type="button" :disabled="busy || disabled" @click="load(page.nextDepth, 0)">{{ t('duplicatefinder', 'Inspect parent shares') }}</button>
			<button v-if="selectable" type="button" :disabled="busy || disabled" @click="selectPage">{{ t('duplicatefinder', 'Include this sharing page in proposal') }}</button>
			<p>{{ t('duplicatefinder', 'Only this page is displayed. Pages are not an atomic snapshot. Inclusion in a proposal requires explicit selection within that proposal.') }}</p>
		</section>
	</details>
</template>
<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
export default {
	name: 'ReviewShares',
	props: { appRef: { type: Number, required: true }, selectable: { type: Boolean, default: false }, disabled: { type: Boolean, default: false } },
	data() { return { type: 0, page: null, pageOffset: 0, busy: false, error: '', requestId: 0, groupPage: null, groupQuery: null, groupBusy: false, groupError: '', groupRequestId: 0 } },
	watch: { appRef() { this.reset() } },
	beforeDestroy() { this.requestId++; this.groupRequestId++ },
    methods: {
        resetGroup() { this.groupRequestId++; this.groupPage = null; this.groupQuery = null; this.groupBusy = false; this.groupError = '' },
        selectGroup() {
            if (!this.selectable || this.disabled || this.busy || this.groupBusy || !this.groupPage) return
            this.$emit('group-selected', { appRef: this.appRef, query: JSON.parse(JSON.stringify(this.groupQuery)), expected: JSON.parse(JSON.stringify(this.groupPage)) })
        },
        async loadGroup(item, offset) {
            if (this.busy || this.groupBusy || this.disabled || this.page?.type !== 1 || !this.page.items.some(row => String(row.id) === String(item.id))) return
            this.resetGroup()
            const generation = this.groupRequestId
            const ref = this.appRef
            const query = { depth: this.page.anchor.depth, shareOffset: this.pageOffset, shareId: String(item.id), offset }
            this.groupBusy = true
            try {
                const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/members/' + ref + '/group-members'), { params: { ...query, pageSize: 25 } })
                if (generation !== this.groupRequestId) return
                if (data.appRef !== ref || data.shareId !== query.shareId || data.sharePage?.anchor?.depth !== query.depth
                    || data.scope !== 'group_membership_only' || data.complete !== false
                    || !['observed', 'backend_not_qualified'].includes(data.status)
                    || !Array.isArray(data.members) || data.members.length > 25
                    || !(data.nextOffset === null || data.nextOffset === offset + 25)) throw new Error('Invalid membership page')
                this.groupPage = data; this.groupQuery = query
            } catch (error) {
                if (generation === this.groupRequestId) this.groupError = this.t('duplicatefinder', 'Group members could not be loaded. Reload the sharing page and try again.')
            } finally { if (generation === this.groupRequestId) this.groupBusy = false }
        },
		selectPage() {
			if (!this.selectable || this.disabled || this.busy || !this.page) return
			this.$emit('selected', { appRef: this.appRef, query: { depth: this.page.anchor.depth, type: this.page.type, offset: this.pageOffset }, expected: JSON.parse(JSON.stringify(this.page)) })
		},
		reset() { this.resetGroup(); this.requestId++; this.page = null; this.busy = false; this.error = '' },
		timestamp(seconds) { return typeof seconds === 'number' && Number.isFinite(seconds) ? new Date(seconds * 1000).toLocaleString() : '—' },
		permission(value) { return this.t('duplicatefinder', value === true ? 'Allowed at observation time' : value === false ? 'Not allowed at observation time' : 'Not determined') },
		pathStatus(value) { return this.t('duplicatefinder', ({ observed: 'Recipient identity verified at observation time', unverifiable: 'Recipient path could not be verified', group_members_not_expanded: 'Group membership and individual paths have not been expanded' })[value] || 'Recipient path not determined') },
		async load(depth, offset) {
			if (this.busy || this.disabled) return
			this.resetGroup()
			const generation = ++this.requestId
			const ref = this.appRef
			const type = this.type
			this.busy = true; this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/members/' + ref + '/shares'), { params: { depth, type, offset, limit: 25 } })
				if (generation !== this.requestId) return
				if (data.appRef !== ref || data.type !== type || data.anchor?.depth !== depth || !Array.isArray(data.items) || data.items.length > 25) throw new Error('Invalid share page')
				this.page = data; this.pageOffset = offset
			} catch (error) {
				if (generation === this.requestId) this.error = this.t('duplicatefinder', 'Sharing details could not be loaded. Any displayed page is the previous observation. Retry explicitly.')
			} finally { if (generation === this.requestId) this.busy = false }
		},
	},
}
</script>
<style scoped>
.review-shares { margin: 12px 0; padding: 12px; border: 1px solid var(--color-border); }
summary { cursor: pointer; font-weight: 600; }
p { margin: 8px 0; overflow-wrap: anywhere; white-space: pre-wrap; }
button { margin: 4px; }
li { border-top: 1px solid var(--color-border); padding: 8px 0; }
select { max-width: 100%; }
</style>
