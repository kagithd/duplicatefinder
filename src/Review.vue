<template>
	<main class="review">
		<header class="review__header">
			<h1>{{ t('duplicatefinder', 'Administrative duplicate review') }}</h1>
			<p>{{ t('duplicatefinder', 'Read-only review of indexed SHA-256 candidates across users. Stored hashes have not been rechecked. Reference counts do not establish physical copies or file integrity.') }}</p>
		</header>
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
				<h2 id="review-members-heading">
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
	</main>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'Review',
	data() {
		return {
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
			groupRequest: 0,
			memberRequest: 0,
		}
	},
	mounted() {
		this.loadGroups('')
	},
	beforeDestroy() {
		this.groupRequest++
		this.memberRequest++
	},
	methods: {
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
				const { data } = await axios.get(generateUrl('/apps/duplicatefinder/api/review/groups'), { params: { cursor, limit: 25 } })
				if (request !== this.groupRequest) return
				this.groups = data.items
				this.nextGroupCursor = data.nextCursor
			} catch (error) {
				if (request === this.groupRequest) this.groupsError = true
			} finally {
				if (request === this.groupRequest) this.groupsLoading = false
			}
		},
		selectGroup(hash) {
			this.selectedHash = hash
			this.loadMembers(0)
		},
		async loadMembers(cursor) {
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
