<template>
	<main class="review">
		<header class="review__header">
			<h1>{{ t('duplicatefinder', 'Administrative duplicate review') }}</h1>
			<p>{{ t('duplicatefinder', 'Read-only review of indexed SHA-256 candidates across users. Stored hashes have not been rechecked. Reference counts do not establish physical copies or file integrity.') }}</p>
		</header>
		<form class="review__filters" @submit.prevent="applyFilters">
            <label>{{ t('duplicatefinder', 'Indexed user (exact)') }}<input v-model="filterOwner" data-filter-owner maxlength="255"></label>
            <label>{{ t('duplicatefinder', 'Indexed folder (absolute path)') }}<input v-model="filterFolder" data-filter-folder maxlength="4096"></label>
            <button type="submit" data-apply-filters>{{ t('duplicatefinder', 'Apply filters') }}</button>
            <button type="button" data-reset-filters @click="resetFilters">{{ t('duplicatefinder', 'Reset filters') }}</button>
            <p>{{ t('duplicatefinder', 'Other copies in matching groups remain visible. Filters use stored index users and paths, not verified current ownership.') }}</p>
            <p>{{ t('duplicatefinder', 'Applied scope') }}: {{ appliedOwner || t('duplicatefinder', 'All users') }} &middot; {{ appliedFolder || t('duplicatefinder', 'All folders') }}</p>
        </form>
        <div class="review__columns">
			<section class="review__groups" :aria-busy="groupsLoading" aria-labelledby="review-groups-heading">
				<h2 id="review-groups-heading">
					{{ t('duplicatefinder', 'Indexed groups') }}
				</h2>
				<p v-if="groupsLoading" role="status">
					{{ t('duplicatefinder', 'Loading groups…') }}
				</p>
				<div v-else-if="groupsError" role="alert">
					<p>{{ t('duplicatefinder', 'Groups could not be loaded.') }}</p>
					<button type="button" data-retry-groups @click="loadGroups(groupCursor)">
						{{ t('duplicatefinder', 'Retry') }}
					</button>
				</div>
				<p v-else-if="!groups.length">
					{{ t('duplicatefinder', 'No indexed groups on this page.') }}
				</p>
				<ul v-else class="review__group-list">
					<li v-for="group in groups" :key="group.hash">
						<button type="button"
							data-group
							class="review__group"
							:aria-pressed="selectedHash === group.hash"
							@click="selectGroup(group.hash)">
							<span>{{ group.referenceCount }} {{ t('duplicatefinder', 'references') }}</span>
							<code>{{ group.hash }}</code>
						</button>
					</li>
				</ul>
				<nav class="review__pagination" :aria-label="t('duplicatefinder', 'Group pages')">
					<button type="button" :disabled="groupsLoading || !groupCursor" @click="loadGroups('')">
						{{ t('duplicatefinder', 'First page') }}
					</button>
					<button type="button"
						data-next-groups
						:disabled="groupsLoading || !nextGroupCursor"
						@click="loadGroups(nextGroupCursor)">
						{{ t('duplicatefinder', 'Next groups') }}
					</button>
				</nav>
			</section>
			<section class="review__members" :aria-busy="membersLoading" aria-labelledby="review-members-heading">
				<h2 id="review-members-heading" ref="membersHeading" tabindex="-1">
					{{ t('duplicatefinder', 'References') }}
				</h2>
				<p v-if="!selectedHash">
					{{ t('duplicatefinder', 'Select a group to inspect its references.') }}
				</p>
				<template v-else>
					<p class="review__hash">
						{{ t('duplicatefinder', 'Stored SHA-256') }}: <code>{{ selectedHash }}</code>
					</p>
					<p v-if="membersLoading" role="status">
						{{ t('duplicatefinder', 'Loading references…') }}
					</p>
					<div v-else-if="membersError" role="alert">
						<p>{{ t('duplicatefinder', 'References could not be loaded.') }}</p>
						<button type="button" data-retry-members @click="loadMembers(memberCursor)">
							{{ t('duplicatefinder', 'Retry') }}
						</button>
					</div>
					<p v-else-if="!members.length">
						{{ t('duplicatefinder', 'No references remain on this page. The live index may have changed.') }}
					</p>
					<ol v-else class="review__member-list">
						<li v-for="member in members" :key="member.id" class="review__member">
							<h3>{{ member.indexOwner || t('duplicatefinder', 'Unknown index owner') }}</h3>
							<p class="review__path">
								{{ member.indexPath }}
							</p>
							<p>{{ member.availability === 'available' ? t('duplicatefinder', 'Metadata accessible') : t('duplicatefinder', 'Unavailable — index reference retained') }} · {{ t('duplicatefinder', 'Integrity: Not checked') }}</p>
							<p>{{ formatSize(member.size) }} · {{ t('duplicatefinder', 'Modified') }}: {{ formatTime(member.mtime) }}</p>
							<button type="button"
								data-load-evidence
								:disabled="evidence[member.id] && evidence[member.id].loading"
								@click="loadEvidence(member)">
								{{ t('duplicatefinder', 'Load latest finding') }}
							</button>
							<div v-if="evidence[member.id]" data-evidence class="review__evidence">
								<p v-if="evidence[member.id].loading" role="status">
									{{ t('duplicatefinder', 'Loading finding…') }}
								</p>
								<p v-else-if="evidence[member.id].error" role="alert">
									{{ t('duplicatefinder', 'Finding could not be loaded. Use the button to retry.') }}
								</p>
								<template v-else-if="evidence[member.id].entry">
									<h4>{{ t('duplicatefinder', 'Historical finding') }} · {{ formatTime(evidence[member.id].entry.createdAt) }}</h4>
									<p>{{ findingStatus(evidence[member.id].entry) }}</p>
									<p>{{ findingUsability(evidence[member.id].entry) }}</p>
									<p>{{ t('duplicatefinder', 'Original revision has not been rechecked.') }}</p>
									<p>
										{{ display(reportOf(evidence[member.id].entry).format) }} ·
										{{ t('duplicatefinder', 'Decoded frames') }}: {{ display(reportOf(evidence[member.id].entry).frames_decoded) }}
									</p>
									<p v-if="reportOf(evidence[member.id].entry).decoder">
										{{ t('duplicatefinder', 'Decoder') }}:
										{{ reportOf(evidence[member.id].entry).decoder.name }}
										{{ reportOf(evidence[member.id].entry).decoder.version }}
									</p>
									<p>{{ t('duplicatefinder', 'Visual content has not been assessed.') }}</p>
									<ReviewPreview v-if="reportOf(evidence[member.id].entry).status === 'passed'"
										:key="member.id + ':' + evidence[member.id].entry.id"
										:app-ref="member.id"
										:evidence-id="evidence[member.id].entry.id" />
								</template>
								<p v-else>
									{{ t('duplicatefinder', 'No saved finding. Integrity remains unchecked.') }}
								</p>
							</div>
							<button type="button" data-check-add @click="$refs.checks.choose(member)">
								{{ t('duplicatefinder', 'Add to check selection') }}
							</button>
							<div class="review__decisions">
								<button type="button" data-plan-keep @click="$refs.plan.choose(member, 'keep', evidence[member.id])">
									{{ t('duplicatefinder', 'Keep') }}
								</button>
								<button type="button" data-plan-remove @click="$refs.plan.choose(member, 'remove', evidence[member.id])">
									{{ t('duplicatefinder', 'Propose removal') }}
								</button>
								<button type="button" @click="$refs.plan.choose(member, 'exclude', evidence[member.id])">
									{{ t('duplicatefinder', 'Exclude from proposal') }}
								</button>
							</div>
							<ReviewShares :app-ref="member.id" />
							<details>
								<summary>{{ t('duplicatefinder', 'File identity details') }}</summary>
								<dl>
									<dt>{{ t('duplicatefinder', 'App reference') }}</dt><dd>{{ member.id }}</dd>
									<dt>{{ t('duplicatefinder', 'Observed owner') }}</dt><dd>{{ display(member.owner) }}</dd>
									<dt>{{ t('duplicatefinder', 'Observed path') }}</dt><dd>{{ display(member.path) }}</dd>
									<dt>{{ t('duplicatefinder', 'Node ID') }}</dt><dd>{{ display(member.nodeId) }}</dd>
									<dt>{{ t('duplicatefinder', 'Storage ID') }}</dt><dd>{{ display(member.storageId) }}</dd>
									<dt>{{ t('duplicatefinder', 'Size (bytes)') }}</dt><dd>{{ display(member.size) }}</dd>
									<dt>{{ t('duplicatefinder', 'ETag') }}</dt><dd>{{ display(member.etag) }}</dd>
								</dl>
							</details>
						</li>
					</ol>
					<nav class="review__pagination" :aria-label="t('duplicatefinder', 'Reference pages')">
						<button type="button" :disabled="membersLoading || !memberCursor" @click="loadMembers(0)">
							{{ t('duplicatefinder', 'First page') }}
						</button>
						<button type="button"
							data-next-members
							:disabled="membersLoading || nextMemberCursor === null"
							@click="loadMembers(nextMemberCursor)">
							{{ t('duplicatefinder', 'Next references') }}
						</button>
					</nav>
				</template>
			</section>
		</div>
		<ReviewChecks ref="checks" :visible-refs="members.map(member => member.id)" @load-evidence="loadEvidence({ id: $event })" />
		<p v-if="referenceLoading" role="status">{{ t('duplicatefinder', 'Loading current reference') }}</p>
        <p v-if="referenceError" role="alert">{{ referenceError }}</p>
        <p v-if="openedReference">{{ t('duplicatefinder', 'Opened from a file search. This group is independent of the group list filters.') }} {{ openedReference }}</p>
        <ReviewMissingFindings :opening="referenceLoading" @open-reference="openEvidenceReference" />
		<ReviewEvidenceSearch :opening="referenceLoading" @open-reference="openEvidenceReference" />
		<ReviewPlan ref="plan" :hash="selectedHash" />
	</main>
</template>

<script>
import ReviewMissingFindings from './components/ReviewMissingFindings.vue'
import ReviewEvidenceSearch from './components/ReviewEvidenceSearch.vue'
import ReviewShares from './components/ReviewShares.vue'
import ReviewChecks from './components/ReviewChecks.vue'
import ReviewPlan from './components/ReviewPlan.vue'
import ReviewPreview from './components/ReviewPreview.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'Review',
	components: { ReviewMissingFindings, ReviewEvidenceSearch, ReviewPlan, ReviewPreview, ReviewChecks, ReviewShares },
	data() {
		return {
			filterOwner: '', filterFolder: '', appliedOwner: '', appliedFolder: '',
			groups: [],
			members: [],
			selectedHash: '',
			groupCursor: '',
			memberCursor: 0,
			nextGroupCursor: null,
			nextMemberCursor: null,
			groupsLoading: false,
			membersLoading: false,
			groupsError: false,
			membersError: false,
			referenceRequest: 0, referenceLoading: false, referenceError: '', openedReference: null,
			groupRequest: 0,
			memberRequest: 0,
			evidence: {},
			evidenceEpoch: 0,
		}
	},
	mounted() {
		this.loadGroups('')
	},
	beforeDestroy() {
		this.referenceRequest++
		this.evidenceEpoch++
		this.groupRequest++
		this.memberRequest++
	},
	methods: {
		applyFilters() { this.appliedOwner = this.filterOwner; this.appliedFolder = this.filterFolder; this.loadGroups('') },
		resetFilters() { this.filterOwner = ''; this.filterFolder = ''; this.applyFilters() },
		reportOf(entry) {
			return entry.record?.report || {}
		},
		findingStatus(entry) {
			const report = this.reportOf(entry)
			const labels = {
				passed: report.scope === 'original_all_exposed_frames' ? 'All exposed frames decoded' : 'Check completed',
				corrupt: 'Decoding failed',
				unsupported: 'Format not supported',
				inaccessible: 'Original could not be read',
				limit: 'Check stopped at a resource limit',
				stale: 'Original changed during the check',
				error: 'Check could not be completed',
			}
			return this.t('duplicatefinder', labels[report.status] || 'Unknown finding')
		},
		findingUsability(entry) {
			if (entry.usability === 'stale') return this.t('duplicatefinder', 'File metadata changed since this check.')
			if (entry.usability === 'checker_outdated') return this.t('duplicatefinder', 'Checker or decoder version changed.')
			if (entry.reason === 'checker_policy_unavailable') return this.t('duplicatefinder', 'Checker policy is not configured.')
			if (entry.reason === 'nextcloud_revision_unavailable') return this.t('duplicatefinder', 'Current file metadata is unavailable.')
			return this.t('duplicatefinder', 'This historical finding does not establish current file integrity.')
		},
		async loadEvidence(member) {
			if (this.evidence[member.id]?.loading) return
			const epoch = this.evidenceEpoch
			this.$set(this.evidence, member.id, { loading: true, error: false, entry: null })
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/members/' + encodeURIComponent(member.id) + '/evidence'), { params: { cursor: 0, limit: 1 } })
				if (epoch !== this.evidenceEpoch) return
				this.$set(this.evidence, member.id, { loading: false, error: false, entry: data.items[0] || null })
			} catch (error) {
				if (epoch !== this.evidenceEpoch) return
				this.$set(this.evidence, member.id, { loading: false, error: true, entry: null })
			}
		},
		formatSize(value) {
			if (value === null || value === undefined || value < 0) return this.t('duplicatefinder', 'Unknown size')
			const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB']
			const unit = value > 0 ? Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1) : 0
			return new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(value / (1024 ** unit)) + ' ' + units[unit]
		},
		formatTime(value) {
			if (value === null || value === undefined) return this.t('duplicatefinder', 'Unknown')
			return new Date(value * 1000).toLocaleString()
		},
		display(value) {
			return value === null || value === undefined ? this.t('duplicatefinder', 'Unknown') : value
		},
		async loadGroups(cursor) {
			this.cancelReferenceNavigation()
			this.evidenceEpoch++
			this.evidence = {}
			const request = ++this.groupRequest
			this.memberRequest++
			this.selectedHash = ''
			this.members = []
			this.membersLoading = false
			this.groups = []
			this.groupCursor = cursor
			this.nextGroupCursor = null
			this.groupsLoading = true
			this.groupsError = false
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/groups'), { params: { cursor, limit: 25, owner: this.appliedOwner, folder: this.appliedFolder } })
				if (request !== this.groupRequest) return
				this.groups = data.items
				this.nextGroupCursor = data.nextCursor
			} catch (error) {
				if (request === this.groupRequest) this.groupsError = true
			} finally {
				if (request === this.groupRequest) this.groupsLoading = false
			}
		},
        cancelReferenceNavigation(preserveOrigin = false) {
            this.referenceRequest++
            this.referenceLoading = false
            this.referenceError = ''
            if (!preserveOrigin) this.openedReference = null
        },
        async openEvidenceReference(appRef) {
            this.cancelReferenceNavigation()
            const request = this.referenceRequest
            this.referenceLoading = true
            this.memberRequest++
            this.evidenceEpoch++
            this.evidence = {}
            this.members = []
            this.membersLoading = false
            this.selectedHash = ''
            try {
                if (!Number.isSafeInteger(appRef) || appRef < 1) throw new Error('Invalid reference')
                const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/references/' + appRef))
                if (request !== this.referenceRequest) return
                if (data.id !== appRef || !/^[a-f0-9]{64}$/.test(data.candidateHash)) throw new Error('Invalid reference')
                this.selectedHash = data.candidateHash
                await this.loadMembers(appRef - 1, true)
                if (request !== this.referenceRequest) return
                if (this.membersError || !this.members.some(member => member.id === appRef)) throw new Error('Reference changed')
                this.openedReference = appRef
                await this.$nextTick()
                if (request !== this.referenceRequest) return
                this.$refs.membersHeading?.focus()
                this.$refs.membersHeading?.scrollIntoView?.({ block: 'start' })
            } catch (error) {
                if (request === this.referenceRequest) this.referenceError = this.t('duplicatefinder', 'Current reference could not be opened. It may have changed or disappeared. Try again.')
            } finally {
                if (request === this.referenceRequest) this.referenceLoading = false
            }
        },
		selectGroup(hash) {
			this.cancelReferenceNavigation()
			this.selectedHash = hash
			this.loadMembers(0)
		},
		async loadMembers(cursor, referenceNavigation = false) {
			if (!referenceNavigation) this.cancelReferenceNavigation(true)
			this.evidenceEpoch++
			this.evidence = {}
			const request = ++this.memberRequest
			const hash = this.selectedHash
			this.memberCursor = cursor
			this.members = []
			this.nextMemberCursor = null
			this.membersLoading = true
			this.membersError = false
			try {
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/groups/' + encodeURIComponent(hash) + '/members'), { params: { cursor, limit: 50 } })
				if (request !== this.memberRequest) return
				this.members = data.items
				this.nextMemberCursor = data.nextCursor
			} catch (error) {
				if (request === this.memberRequest) this.membersError = true
			} finally {
				if (request === this.memberRequest) this.membersLoading = false
			}
		},
	},
}
</script>

<style scoped>
.review__filters { padding: 16px; border-bottom: 1px solid var(--color-border); }
.review__filters label { display: block; margin: 8px 0; }
.review__filters input { display: block; width: min(100%, 650px); }
.review__filters p { overflow-wrap: anywhere; }
.review__filters button { margin: 4px; }
.review {
	width: 100%;
	height: 100%;
	overflow: auto;
	padding: 24px;
	color: var(--color-main-text);
	background: var(--color-main-background);
}
.review__header { margin-bottom: 24px; }
.review__header p { max-width: 85ch; }
h1 { font-size: 24px; font-weight: 600; margin-bottom: 12px; }
h2 { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
h3 { font-weight: 600; }
.review__columns { display: grid; grid-template-columns: minmax(250px, 1fr) minmax(0, 2fr); gap: 32px; }
.review__columns > section { min-width: 0; }
.review__group-list, .review__member-list { list-style: none; padding: 0; }
.review__group { width: 100%; height: auto; text-align: start; padding: 12px; margin: 0 0 8px; border-radius: var(--border-radius); }
.review__group[aria-pressed="true"] { background: var(--color-primary-element-light); border-color: var(--color-primary-element); }
.review__group span, .review__group code { display: block; }
.review__group code, .review__hash code { font-size: 12px; overflow-wrap: anywhere; white-space: normal; }
.review__member { padding: 16px 0; border-bottom: 1px solid var(--color-border); }
.review__evidence { margin: 12px 0; padding: 12px; border-inline-start: 3px solid var(--color-border); }
.review__evidence h4 { font-weight: 600; }
.review__member:first-child { padding-top: 0; }
.review__path, dd { overflow-wrap: anywhere; white-space: pre-wrap; }
.review__member p { margin: 8px 0; }
dl { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(0, 2fr); gap: 4px 16px; margin-top: 12px; }
dt { color: var(--color-text-maxcontrast); }
dd { margin: 0; }
.review__pagination { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
summary { cursor: pointer; padding: 8px 0; }
button:focus-visible, summary:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
@media (max-width: 800px) {
	.review { padding: 16px; }
	.review__columns { grid-template-columns: minmax(0, 1fr); }
}
</style>
