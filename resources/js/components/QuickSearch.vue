<template>
	<cdx-dialog
		:open="open"
		title="Search the portal"
		:hide-title="true"
		:use-close-button="false"
		class="ts-switcher"
		:class="[ `ts-switcher--${ layout.size }`, `ts-switcher--${ layout.density }` ]"
		@update:open="close"
	>
		<div
			class="ts-switcher__frame"
			:class="{
				'ts-switcher__frame--split': showPreview,
				'ts-switcher__frame--searching': !!term,
				'ts-switcher__frame--left-open': zone === 'left',
				'ts-switcher__frame--right-open': zone === 'right'
			}"
			:style="columns"
			@keydown="onFrameKeydown"
		>
			<div class="ts-switcher__search">
				<cdx-icon :icon="cdxIconSearch" class="ts-switcher__search-icon" />

				<input
					ref="inputRef"
					v-model="q"
					class="ts-switcher__input"
					type="text"
					role="combobox"
					aria-expanded="true"
					aria-controls="ts-switcher-results"
					:aria-activedescendant="activeDescendant"
					:placeholder="placeholder"
					aria-label="Search the portal"
					autocomplete="off"
					spellcheck="false"
					enterkeyhint="go"
					@keydown="onKeydown"
				>

				<span v-if="contextLabel" class="ts-switcher__context" :title="contextLabel">
					<cdx-icon :icon="cdxIconEye" size="small" />
					<span class="ts-switcher__context-text">{{ contextLabel }}</span>
				</span>

				<div class="ts-switcher__layout">
					<cdx-button
						ref="layoutToggleRef"
						class="ts-switcher__layout-toggle"
						:class="{ 'ts-switcher__filter--on': settingsOpen }"
						weight="quiet"
						:aria-expanded="settingsOpen"
						aria-controls="ts-switcher-settings"
						aria-label="Customise the layout"
						@click="settingsOpen = !settingsOpen"
					>
						<cdx-icon :icon="cdxIconSettings" />
					</cdx-button>

					<div
						v-if="settingsOpen"
						id="ts-switcher-settings"
						ref="settingsRef"
						class="ts-switcher__settings"
						role="dialog"
						aria-label="Layout"
						@keydown.escape.prevent="close( false )"
					>
						<header class="ts-switcher__settings-head">
							<strong>Layout</strong>
							<button type="button" class="ts-linkish" @click="resetLayout">
								Reset
							</button>
						</header>

						<section class="ts-switcher__settings-group">
							<h3 class="ts-switcher__settings-heading">Panels</h3>

							<label
								v-for="panel in PANELS"
								:key="panel.key"
								class="ts-switcher__settings-option"
							>
								<cdx-icon :icon="panel.icon" class="ts-switcher__settings-icon" />
								<span class="ts-switcher__settings-text">
									<span>{{ panel.label }}</span>
									<span v-if="panel.hint" class="ts-switcher__settings-hint">
										<kbd>{{ panel.key === 'nav' ? ( layout.swap ? '→' : '←' ) : ( layout.swap ? '←' : '→' ) }}</kbd>
										{{ panel.hint }}
									</span>
								</span>
								<cdx-toggle-switch
									:model-value="layout[ panel.key ]"
									:aria-label="panel.label"
									@update:model-value="setLayout( { [ panel.key ]: $event } )"
								/>
							</label>
						</section>

						<section class="ts-switcher__settings-group">
							<h3 class="ts-switcher__settings-heading">Size</h3>
							<div class="ts-switcher__segments" role="radiogroup" aria-label="Size">
								<button
									v-for="size in SIZES"
									:key="size.value"
									type="button"
									role="radio"
									class="ts-switcher__segment"
									:class="{ 'ts-switcher__segment--on': layout.size === size.value }"
									:aria-checked="layout.size === size.value"
									@click="setLayout( { size: size.value } )"
								>
									{{ size.label }}
								</button>
							</div>

							<h3 class="ts-switcher__settings-heading">Rows</h3>
							<div class="ts-switcher__segments" role="radiogroup" aria-label="Rows">
								<button
									v-for="density in DENSITIES"
									:key="density.value"
									type="button"
									role="radio"
									class="ts-switcher__segment"
									:class="{ 'ts-switcher__segment--on': layout.density === density.value }"
									:aria-checked="layout.density === density.value"
									@click="setLayout( { density: density.value } )"
								>
									{{ density.label }}
								</button>
							</div>
						</section>

						<section class="ts-switcher__settings-group">
							<h3 class="ts-switcher__settings-heading">The empty box shows</h3>

							<ol class="ts-switcher__settings-list">
								<li
									v-for="( section, at ) in orderedSections"
									:key="section.value"
									class="ts-switcher__settings-option ts-switcher__settings-option--order"
								>
									<span class="ts-switcher__settings-rank">{{ at + 1 }}</span>
									<cdx-icon :icon="section.icon" class="ts-switcher__settings-icon" />
									<span class="ts-switcher__settings-text">{{ section.label }}</span>
									<span class="ts-switcher__settings-moves">
										<button
											type="button"
											class="ts-switcher__settings-move"
											:disabled="at === 0"
											:aria-label="`Move ${ section.label } up`"
											@click="moveSection( at, -1 )"
										>
											<cdx-icon :icon="cdxIconUpTriangle" size="x-small" />
										</button>
										<button
											type="button"
											class="ts-switcher__settings-move"
											:disabled="at === orderedSections.length - 1"
											:aria-label="`Move ${ section.label } down`"
											@click="moveSection( at, 1 )"
										>
											<cdx-icon :icon="cdxIconDownTriangle" size="x-small" />
										</button>
									</span>
								</li>
							</ol>
						</section>
					</div>
				</div>

				<cdx-button
					class="ts-switcher__close"
					weight="quiet"
					aria-label="Close the search"
					@click="close( false )"
				>
					<cdx-icon :icon="cdxIconClose" />
				</cdx-button>
			</div>

			<div class="ts-switcher__filters">
				<div class="ts-switcher__kinds" role="group" aria-label="Look in">
					<button
						type="button"
						class="ts-switcher__kind"
						:class="{ 'ts-switcher__kind--on': !kinds.length }"
						:aria-pressed="!kinds.length"
						:title="`Everywhere (${ altKey }0)`"
						@click="kinds = []"
					>
						Everywhere
						<span v-if="term && totalFound && !kinds.length" class="ts-switcher__kind-count">{{ totalFound }}</span>
					</button>

					<button
						v-for="( kind, at ) in kindList"
						:key="kind.kind"
						type="button"
						class="ts-switcher__kind"
						:class="{
							'ts-switcher__kind--on': kinds.includes( kind.kind ),
							'ts-switcher__kind--suggested': kind.kind === contextKind && !kinds.length
						}"
						:aria-pressed="kinds.includes( kind.kind )"
						:title="`${ kind.plural } (${ altKey }${ at + 1 }). Shift-click to add to the others.`"
						@click="pickKind( kind.kind, $event.shiftKey )"
					>
						<cdx-icon :icon="icon( kind.kind )" size="small" />
						{{ kind.plural }}
						<span v-if="term && kindCounts[ kind.kind ]" class="ts-switcher__kind-count">
							{{ kindCounts[ kind.kind ] }}
						</span>
					</button>
				</div>

				<div class="ts-switcher__refine">
					<cdx-toggle-button
						v-model="titlesOnly"
						:quiet="true"
						class="ts-switcher__filter"
						:aria-label="titlesOnly ? 'Searching titles only' : 'Searching everything written down'"
					>
						<span class="ts-switcher__aa" aria-hidden="true">Aa</span>
						Title only
					</cdx-toggle-button>

					<cdx-button
						v-for="extra in activeExtras"
						:key="extra.value"
						class="ts-switcher__filter ts-switcher__filter--on"
						weight="quiet"
						:aria-label="`Stop filtering to ${ extra.label.toLowerCase() }`"
						@click="dropExtra( extra.value )"
					>
						<cdx-icon :icon="extra.icon" size="small" />
						{{ extra.label }}
						<cdx-icon :icon="cdxIconClose" size="small" class="ts-switcher__caret" />
					</cdx-button>

					<cdx-menu-button
						v-if="spareExtras.length"
						:selected="null"
						class="ts-switcher__filter"
						:menu-items="spareExtras"
						weight="quiet"
						@update:selected="addExtra"
					>
						<cdx-icon :icon="cdxIconFunnel" size="small" />
						Filter
					</cdx-menu-button>

					<cdx-menu-button
						:selected="since"
						class="ts-switcher__filter"
						:class="{ 'ts-switcher__filter--on': since !== 'any' }"
						:menu-items="SINCE"
						weight="quiet"
						@update:selected="since = $event"
					>
						<cdx-icon :icon="cdxIconClock" size="small" />
						{{ sinceLabel }}
					</cdx-menu-button>

					<cdx-button
						class="ts-switcher__filter"
						:class="{ 'ts-switcher__filter--on': sort === 'newest' }"
						weight="quiet"
						:aria-label="sort === 'newest' ? 'Sorted newest first' : 'Sorted by best match'"
						@click="sort = sort === 'newest' ? 'relevance' : 'newest'"
					>
						<cdx-icon :icon="cdxIconSortVertical" size="small" />
						{{ sort === 'newest' ? 'Newest' : 'Best match' }}
					</cdx-button>

					<cdx-button
						v-if="relatedTo"
						class="ts-switcher__filter ts-switcher__filter--on"
						weight="quiet"
						@click="leaveRelated"
					>
						<cdx-icon :icon="cdxIconLightbulb" size="small" />
						Like {{ relatedTo.reference || relatedTo.title }}
						<cdx-icon :icon="cdxIconClose" size="small" class="ts-switcher__caret" />
					</cdx-button>

					<cdx-button
						v-if="filtered"
						class="ts-switcher__filter"
						weight="quiet"
						@click="clearFilters"
					>
						Clear
					</cdx-button>
				</div>
			</div>

			<div class="ts-switcher__body" @mousedown="keepFocus">
				<aside
					v-if="railAt( 'left' )"
					class="ts-switcher__rail ts-switcher__rail--left"
					:class="`ts-switcher__rail--${ railAt( 'left' ) }`"
					:aria-label="railLabel( 'left' )"
					@mouseenter="hoverRail( 'left' )"
					@mouseleave="unhoverRail( 'left' )"
				>
					<ul
						class="ts-switcher__rail-panel"
						:class="{ 'ts-switcher__rail-panel--open': zone === 'left' }"
						role="listbox"
						:aria-label="railLabel( 'left' )"
					>
						<li
							v-for="( item, at ) in railItems( 'left' )"
							:id="railId( 'left', at )"
							:key="item.key"
							class="ts-switcher__rail-item"
							:class="{
								'ts-switcher__rail-item--active': zone === 'left' && railIndex === at,
								'ts-switcher__rail-item--current': item.current,
								'ts-switcher__rail-item--primary': item.primary
							}"
							role="option"
							:aria-selected="zone === 'left' && railIndex === at"
							:aria-disabled="item.disabled || undefined"
							:title="item.label"
							@mousemove="zone === 'left' && ( railIndex = at )"
							@click="runRailItem( item, $event )"
						>
							<cdx-icon :icon="item.icon" class="ts-switcher__rail-icon" />
							<span class="ts-switcher__rail-label">{{ item.label }}</span>
							<kbd v-if="at < 9" class="ts-switcher__rail-key">{{ at + 1 }}</kbd>
						</li>
					</ul>
				</aside>

				<div ref="listRef" class="ts-switcher__list">
					<cdx-progress-bar
						v-if="loading || relatedLoading"
						class="ts-switcher__progress"
						:inline="true"
						aria-label="Searching"
					/>

					<ul
						v-if="groups.length"
						id="ts-switcher-results"
						class="ts-switcher__results"
						:class="{ 'ts-switcher__results--stale': loading }"
						role="listbox"
						aria-label="Results"
					>
						<template v-for="group in groups" :key="group.key">
							<li class="ts-switcher__group" role="presentation">
								<cdx-icon v-if="group.icon" :icon="group.icon" size="small" />
								{{ group.label }}
								<span v-if="group.note" class="ts-switcher__group-note">{{ group.note }}</span>
							</li>

							<li
								v-for="row in group.rows"
								:id="rowId( row )"
								:key="`${ group.key }-${ rowId( row ) }`"
								class="ts-switcher__row"
								:class="{
									'ts-switcher__row--active': isActive( row ),
									'ts-switcher__row--held': isActive( row ) && zone !== 'list',
									'ts-switcher__row--page': row.kind === 'page' || row.kind === 'jump'
								}"
								role="option"
								:aria-selected="isActive( row )"
								@mousemove="makeActive( row )"
								@click="go( row, $event.metaKey || $event.ctrlKey )"
							>
								<cdx-icon :icon="row.icon || icon( row.kind )" class="ts-switcher__row-icon" />

								<span class="ts-switcher__row-main">
									<span class="ts-switcher__row-title">
										<span
											v-for="( run, i ) in marked( row.title )"
											:key="i"
											:class="{ 'ts-switcher__hit': run.match }"
										>{{ run.text }}</span>
									</span>

									<span class="ts-switcher__row-meta">
										<span v-if="row.reference" class="ts-mono">
											<span
												v-for="( run, i ) in marked( row.reference )"
												:key="i"
												:class="{ 'ts-switcher__hit': run.match }"
											>{{ run.text }}</span>
										</span>
										<span>{{ row.kind_label }}</span>
										<span v-if="row.assignee" class="ts-switcher__row-who">
											<cdx-icon :icon="cdxIconUserAvatar" size="x-small" />
											{{ row.assignee === me ? 'You' : row.assignee }}
										</span>
										<span v-if="row.accounts?.length && row.kind !== 'subject' && !row.related_by" class="ts-switcher__row-who">
											{{ row.accounts.slice( 0, 2 ).join( ', ' ) }}{{ row.accounts.length > 2 ? ` +${ row.accounts.length - 2 }` : '' }}
										</span>
										<span v-if="row.related_by">{{ row.related_by }}</span>
										<span v-if="row.updated">{{ ago( row.updated ) }}</span>
									</span>

									<span v-if="row.snippet" class="ts-switcher__row-snippet">
										<span
											v-for="( run, i ) in row.snippet"
											:key="i"
											:class="{ 'ts-switcher__hit': run.match }"
										>{{ run.text }}</span>
									</span>
								</span>

								<span class="ts-switcher__row-end">
									<StatusChip
										v-if="row.status"
										:kind="row.status_of || 'case'"
										:value="row.status"
									/>
									<StatusChip
										v-if="row.priority === 'urgent' || row.priority === 'high'"
										kind="priority"
										:value="row.priority"
									/>
								</span>

								<cdx-button
									v-if="row.kind !== 'page' && row.kind !== 'jump'"
									class="ts-switcher__row-more"
									weight="quiet"
									size="small"
									:aria-label="`What you can do with ${ row.title }`"
									@click.stop="showActionsFor( row )"
								>
									<cdx-icon :icon="cdxIconEllipsis" size="small" />
								</cdx-button>
							</li>
						</template>
					</ul>

					<p v-else-if="loading" class="ts-empty">Looking…</p>

					<p v-else-if="error" class="ts-empty">
						The search did not answer. Try again, or use the queue's own search.
					</p>

					<p v-else-if="term" class="ts-empty">
						Nothing matches “{{ term }}”{{ titlesOnly ? ' in any title' : '' }}.
						<button v-if="titlesOnly" type="button" class="ts-linkish" @click="titlesOnly = false">
							Search everything written down
						</button>
						<button v-else-if="filtered" type="button" class="ts-linkish" @click="clearFilters">
							Search without the filters
						</button>
					</p>

					<p v-else class="ts-empty">
						Type a name, a reference, or a phrase somebody wrote.
					</p>
				</div>

				<div v-if="showPreview" class="ts-switcher__preview">
					<div v-if="activeRow && ( activeRow.kind === 'page' || activeRow.kind === 'jump' )" class="ts-switcher__card">
						<div class="ts-switcher__card-head">
							<cdx-icon :icon="activeRow.icon || cdxIconArrowNext" class="ts-switcher__card-icon" />
							<div class="ts-switcher__card-title">
								<span class="ts-switcher__card-kind">{{ activeRow.kind_label }}</span>
								<strong>{{ activeRow.title }}</strong>
							</div>
						</div>
						<p class="ts-meta">
							Press <kbd>↵</kbd> to go there, or <kbd>{{ metaKey }}↵</kbd> to open it in a new tab.
						</p>
					</div>

					<div v-else-if="preview" class="ts-switcher__card">
						<div class="ts-switcher__card-head">
							<cdx-icon :icon="icon( preview.kind )" class="ts-switcher__card-icon" />

							<div class="ts-switcher__card-title">
								<span class="ts-switcher__card-kind">
									{{ preview.kind_label }}
									<span v-if="preview.reference" class="ts-mono">{{ preview.reference }}</span>
								</span>
								<strong>{{ preview.title }}</strong>
								<span v-if="preview.subtitle" class="ts-meta">{{ preview.subtitle }}</span>
							</div>

							<cdx-button
								weight="quiet"
								aria-label="Open in a new tab"
								@click="go( activeRow, true )"
							>
								<cdx-icon :icon="cdxIconLinkExternal" />
							</cdx-button>
						</div>

						<div class="ts-switcher__chips">
							<StatusChip
								v-if="preview.status"
								:kind="preview.status_of || 'case'"
								:value="preview.status"
							/>
							<StatusChip v-if="preview.priority" kind="priority" :value="preview.priority" />
							<cdx-info-chip v-if="preview.threat_to_life" status="error">
								Threat to life
							</cdx-info-chip>
						</div>

						<div v-if="activeRow?.snippet" class="ts-switcher__why">
							<span class="ts-switcher__section-label">Where “{{ term }}” turns up</span>
							<p>
								<span
									v-for="( run, i ) in activeRow.snippet"
									:key="i"
									:class="{ 'ts-switcher__hit': run.match }"
								>{{ run.text }}</span>
							</p>
						</div>

						<dl v-if="preview.facts.length" class="ts-switcher__facts">
							<div v-for="fact in preview.facts" :key="fact.label" class="ts-switcher__fact">
								<dt>{{ fact.label }}</dt>
								<dd :title="fact.date ? dateTime( fact.value ) : undefined">
									{{ fact.date ? ago( fact.value ) : fact.value }}
								</dd>
							</div>
						</dl>

						<div v-if="preview.accounts.length" class="ts-switcher__accounts">
							<button
								v-for="account in preview.accounts"
								:key="account.id"
								type="button"
								class="ts-switcher__account"
								:title="`Go to ${ account.username }`"
								@click="go( accountRow( account ), $event.metaKey || $event.ctrlKey )"
							>
								<cdx-icon :icon="cdxIconUserAvatar" size="small" />
								<span
									v-for="( run, i ) in marked( account.username )"
									:key="i"
									:class="{ 'ts-switcher__hit': run.match }"
								>{{ run.text }}</span>
								<StatusChip v-if="account.role" kind="role" :value="account.role" />
							</button>
						</div>

						<div v-if="previewSections.length" class="ts-switcher__sections">
							<section
								v-for="section in previewSections"
								:key="section.label"
								class="ts-switcher__section"
							>
								<span class="ts-switcher__section-label">{{ section.label }}</span>
								<p class="ts-switcher__section-text">
									<span
										v-for="( run, i ) in marked( section.text )"
										:key="i"
										:class="{ 'ts-switcher__hit': run.match }"
									>{{ run.text }}</span>
								</p>
							</section>
						</div>

						<div v-if="preview.thread?.length" class="ts-switcher__thread">
							<span class="ts-switcher__section-label">
								<cdx-icon :icon="cdxIconSpeechBubbles" size="small" />
								Latest
							</span>

							<article
								v-for="message in preview.thread"
								:key="message.id"
								class="ts-switcher__message"
								:class="{ 'ts-switcher__message--internal': message.internal }"
							>
								<header class="ts-switcher__message-head">
									<strong>{{ message.author }}</strong>
									<span v-if="message.internal && preview.kind === 'case'" class="ts-switcher__message-tag">Internal</span>
									<span class="ts-meta" :title="dateTime( message.when )">{{ ago( message.when ) }}</span>
								</header>
								<p>
									<span
										v-for="( run, i ) in marked( message.body )"
										:key="i"
										:class="{ 'ts-switcher__hit': run.match }"
									>{{ run.text }}</span>
								</p>
							</article>
						</div>

						<div v-if="preview.counts.length" class="ts-switcher__counts">
							<span v-for="count in preview.counts" :key="count.label">
								<strong>{{ count.value }}</strong> {{ count.label }}
							</span>
						</div>
					</div>

					<div v-else-if="previewLoading" class="ts-switcher__card ts-switcher__card--loading" aria-hidden="true">
						<span class="ts-switcher__skeleton ts-switcher__skeleton--title" />
						<span class="ts-switcher__skeleton" />
						<span class="ts-switcher__skeleton" />
						<span class="ts-switcher__skeleton ts-switcher__skeleton--short" />
					</div>

					<p v-else class="ts-empty">
						{{ activeRow ? 'Nothing to preview.' : 'Pick something to see it here.' }}
					</p>
				</div>

				<aside
					v-if="railAt( 'right' )"
					class="ts-switcher__rail ts-switcher__rail--right"
					:class="`ts-switcher__rail--${ railAt( 'right' ) }`"
					:aria-label="railLabel( 'right' )"
					@mouseenter="hoverRail( 'right' )"
					@mouseleave="unhoverRail( 'right' )"
				>
					<ul
						class="ts-switcher__rail-panel"
						:class="{ 'ts-switcher__rail-panel--open': zone === 'right' }"
						role="listbox"
						:aria-label="railLabel( 'right' )"
					>
						<li
							v-for="( item, at ) in railItems( 'right' )"
							:id="railId( 'right', at )"
							:key="item.key"
							class="ts-switcher__rail-item"
							:class="{
								'ts-switcher__rail-item--active': zone === 'right' && railIndex === at,
								'ts-switcher__rail-item--current': item.current,
								'ts-switcher__rail-item--primary': item.primary
							}"
							role="option"
							:aria-selected="zone === 'right' && railIndex === at"
							:aria-disabled="item.disabled || undefined"
							:title="item.label"
							@mousemove="zone === 'right' && ( railIndex = at )"
							@click="runRailItem( item, $event )"
						>
							<cdx-icon :icon="item.icon" class="ts-switcher__rail-icon" />
							<span class="ts-switcher__rail-label">{{ item.label }}</span>
							<kbd v-if="at < 9" class="ts-switcher__rail-key">{{ at + 1 }}</kbd>
						</li>
					</ul>
				</aside>
			</div>

			<div class="ts-switcher__foot">
				<span class="ts-switcher__hints">
					<template v-if="zone === 'list'">
						<span v-if="railAt( 'left' )"><kbd>←</kbd> {{ railLabel( 'left' ) }}</span>
						<span v-if="railAt( 'right' )"><kbd>→</kbd> {{ railLabel( 'right' ) }}</span>
						<span><kbd>↵</kbd> Open</span>
						<span><kbd>{{ metaKey }}↵</kbd> New tab</span>
						<span><kbd>↑↓</kbd> Move</span>
						<span v-if="kindList.length"><kbd>{{ altKey }}0–{{ kindList.length }}</kbd> Look in</span>
					</template>
					<template v-else>
						<span><kbd>↑↓</kbd> Move</span>
						<span><kbd>↵</kbd> or <kbd>1–9</kbd> Choose</span>
						<span><kbd>{{ zone === 'left' ? '→' : '←' }}</kbd> Back to results</span>
					</template>
					<span><kbd>Esc</kbd> Close</span>
				</span>

				<span class="ts-switcher__engine">{{ engineNote }}</span>
			</div>
		</div>
	</cdx-dialog>
</template>

<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
	CdxButton, CdxDialog, CdxIcon, CdxInfoChip, CdxMenuButton, CdxProgressBar, CdxToggleButton,
	CdxToggleSwitch
} from '@wikimedia/codex';
import {
	cdxIconAdd, cdxIconAlert, cdxIconArrowNext, cdxIconArticle, cdxIconBlock, cdxIconClock,
	cdxIconClose, cdxIconCopy, cdxIconDownTriangle, cdxIconEllipsis, cdxIconEye, cdxIconFlag,
	cdxIconFunnel, cdxIconHistory, cdxIconLightbulb, cdxIconLink, cdxIconLinkExternal, cdxIconMenu,
	cdxIconSearch, cdxIconSettings, cdxIconSortVertical, cdxIconSpeechBubbles, cdxIconTrash,
	cdxIconUpTriangle, cdxIconUserAdd, cdxIconUserAvatar, cdxIconUserGroup
} from '@wikimedia/codex-icons';
import StatusChip from './StatusChip.vue';
import { api } from '../lib/api.js';
import { ago, dateTime } from '../lib/format.js';
import { sections as navSections } from '../lib/navigation.js';
import { recents, remember } from '../lib/recents.js';
import { session } from '../lib/session.js';

const props = defineProps( {
	open: { type: Boolean, default: false }
} );

const emit = defineEmits( [ 'update:open', 'open-investigation' ] );
const router = useRouter();
const route = useRoute();
const notify = inject( 'notify', () => {} );

const PREVIEW_KEY = 'tsportal:switcher-preview';
const ORDER_KEY = 'tsportal:switcher-order';
const LAYOUT_KEY = 'tsportal:switcher-layout';
const DETAIL_ROUTES = [ 'case', 'investigation', 'subject', 'transparency-report' ];
const RELATABLE = [ 'case', 'investigation', 'subject', 'sanction', 'removal' ];
const OPEN_CASE = [ 'received', 'in-review', 'investigating', 'awaiting-reply' ];
const REFERENCE = /^[A-Z]{1,6}-\d{4}-\d{1,7}$/i;
const CACHE_FOR = 45000;

const SEED_KINDS = {
	case: 'case',
	investigation: 'investigation',
	subject: 'subject',
	'transparency-report': 'transparency'
};

const ICONS = {
	case: cdxIconFlag,
	investigation: cdxIconUserGroup,
	subject: cdxIconUserAvatar,
	sanction: cdxIconBlock,
	removal: cdxIconTrash,
	transparency: cdxIconArticle
};

const EXTRAS = [
	{ value: 'mine', label: 'With me', icon: cdxIconUserAvatar },
	{ value: 'unassigned', label: 'With nobody', icon: cdxIconUserAdd },
	{ value: 'urgent', label: 'Urgent or high', icon: cdxIconAlert },
	{ value: 'open', label: 'Only what is open', icon: cdxIconFlag }
];

const SINCE = [
	{ value: 'any', label: 'Any time' },
	{ value: 'day', label: 'Past day' },
	{ value: 'week', label: 'Past week' },
	{ value: 'month', label: 'Past month' },
	{ value: 'year', label: 'Past year' }
];

const SECTIONS = [
	{ value: 'related', label: 'Related', icon: cdxIconLightbulb },
	{ value: 'recent', label: 'Recent', icon: cdxIconHistory },
	{ value: 'mine', label: 'Open, and yours', icon: cdxIconUserAvatar }
];

const PANELS = [
	{ key: 'nav', label: 'Navigation rail', hint: 'from the start of the box', icon: cdxIconMenu },
	{ key: 'actions', label: 'Actions rail', hint: 'from the end of the box', icon: cdxIconEllipsis },
	{ key: 'preview', label: 'Preview', icon: cdxIconEye },
	{ key: 'swap', label: 'Swap the rails\' sides', icon: cdxIconSortVertical }
];

const SIZES = [
	{ value: 'regular', label: 'Regular' },
	{ value: 'large', label: 'Large' },
	{ value: 'full', label: 'Full' }
];

const DENSITIES = [
	{ value: 'comfortable', label: 'Roomy' },
	{ value: 'compact', label: 'Compact' }
];

const DEFAULT_LAYOUT = {
	nav: true,
	actions: true,
	preview: true,
	swap: false,
	size: 'large',
	density: 'comfortable'
};

const appName = window.TSPortal?.appName ?? 'TSPortal';

const onMac = /Mac|iPhone|iPad/.test( navigator.platform ?? navigator.userAgent );
const metaKey = onMac ? '⌘' : 'Ctrl ';
const altKey = onMac ? '⌥' : 'Alt ';

const q = ref( '' );
const titlesOnly = ref( false );
const kinds = ref( [] );
const extras = ref( [] );
const since = ref( 'any' );
const sort = ref( 'relevance' );

const rows = ref( [] );
const meta = ref( { engine: null, total: 0, degraded: false, full_text: false } );
const tookMs = ref( null );
const kindList = ref( [] );
const serverRecents = ref( [] );
const localRecents = ref( [] );
const related = ref( [] );
const relatedSeed = ref( null );

const relatedTo = ref( null );
const relatedRows = ref( [] );

const order = ref( readOrder() );
const layout = reactive( readLayout() );
const settingsOpen = ref( false );

const loading = ref( false );
const relatedLoading = ref( false );
const error = ref( false );

const activeKey = ref( null );
const previews = new Map();
const preview = ref( null );
const previewLoading = ref( false );

const zone = ref( 'list' );
const railIndex = ref( 0 );

const inputRef = ref( null );
const listRef = ref( null );
const settingsRef = ref( null );
const layoutToggleRef = ref( null );

const me = computed( () => session.user?.username ?? null );

const term = computed( () => q.value.trim() );
const fullText = computed( () => meta.value.full_text === true );

const filtered = computed( () => titlesOnly.value || kinds.value.length > 0 ||
	extras.value.length > 0 || since.value !== 'any' || sort.value !== 'relevance' );

const currentSection = computed(
	() => navSections().find( ( section ) => section.match.includes( route.name ) ) ?? null
);

const contextKind = computed( () => currentSection.value?.kind ?? null );

const placeholder = computed( () => {
	const plural = kindList.value.find( ( k ) => k.kind === contextKind.value )?.plural;

	return plural
		? `Search ${ plural.toLowerCase() } and everything else in ${ appName }…`
		: `Search ${ appName }…`;
} );

const contextLabel = computed( () => {
	if ( relatedSeed.value ) {
		return `On ${ relatedSeed.value.reference || relatedSeed.value.title }`;
	}

	return currentSection.value ? `In ${ currentSection.value.label }` : null;
} );

const sinceLabel = computed( () => SINCE.find( ( item ) => item.value === since.value )?.label ?? 'Any time' );

const activeExtras = computed(
	() => EXTRAS.filter( ( extra ) => extras.value.includes( extra.value ) )
);

const spareExtras = computed( () => EXTRAS
	.filter( ( extra ) => !extras.value.includes( extra.value ) )
	.filter( ( extra ) => !( extra.value === 'mine' && extras.value.includes( 'unassigned' ) ) )
	.filter( ( extra ) => !( extra.value === 'unassigned' && extras.value.includes( 'mine' ) ) )
	.map( ( extra ) => ( { value: extra.value, label: extra.label, icon: extra.icon } ) ) );

const kindCounts = computed( () => rows.value.reduce( ( counts, row ) => {
	counts[ row.kind ] = ( counts[ row.kind ] ?? 0 ) + 1;

	return counts;
}, {} ) );

const totalFound = computed( () => meta.value.total || rows.value.length );

const seed = computed( () => {
	const kind = SEED_KINDS[ route.name ];
	const id = Number( route.params.id );

	return kind && Number.isInteger( id ) && id > 0 ? { kind, id } : null;
} );

const orderedSections = computed( () => order.value
	.map( ( value ) => SECTIONS.find( ( section ) => section.value === value ) )
	.filter( Boolean ) );

const relatedLabel = computed( () => {
	const about = relatedSeed.value;

	return about ? `Related to ${ about.reference || about.title }` : 'Related';
} );

const groups = computed( () => {
	if ( relatedTo.value && !term.value ) {
		return relatedRows.value.length
			? [ {
				key: 'like',
				label: `Like ${ relatedTo.value.reference || relatedTo.value.title }`,
				icon: cdxIconLightbulb,
				rows: relatedRows.value
			} ]
			: [];
	}

	return term.value ? foundGroups() : idleGroups();
} );

const flat = computed( () => groups.value.flatMap( ( group ) => group.rows ) );

const activeRow = computed(
	() => flat.value.find( ( row ) => rowId( row ) === activeKey.value ) ?? flat.value[ 0 ] ?? null
);

const showPreview = computed( () => layout.preview );

const columns = computed( () => ( {
	'--ts-sw-left': railAt( 'left' ) ? 'var( --ts-sw-rail )' : '0',
	'--ts-sw-right': railAt( 'right' ) ? 'var( --ts-sw-rail )' : '0',
	'--ts-sw-preview': showPreview.value ? 'var( --ts-sw-preview-width )' : '0'
} ) );

const navItems = computed( () => [
	{
		key: 'new-investigation',
		label: 'Open investigation',
		icon: cdxIconAdd,
		primary: true,
		run: () => {
			close( false );
			emit( 'open-investigation' );
		}
	},
	...navSections().map( ( section ) => ( {
		key: section.name,
		label: section.label,
		icon: section.icon,
		current: section.match.includes( route.name ),
		run: ( newTab ) => go( pageRow( section ), newTab )
	} ) )
] );

const actionItems = computed( () => actionsFor( activeRow.value ) );

const activeDescendant = computed( () => {
	if ( zone.value !== 'list' ) {
		return railId( zone.value, railIndex.value );
	}

	return activeRow.value ? rowId( activeRow.value ) : undefined;
} );

const previewSections = computed( () => {
	const card = preview.value;

	if ( !card ) {
		return [];
	}

	if ( card.sections?.length ) {
		return card.sections;
	}

	return card.excerpt ? [ { label: card.excerpt_label || 'Excerpt', text: card.excerpt } ] : [];
} );

const engineNote = computed( () => {
	if ( meta.value.degraded ) {
		return 'Full-text search is unavailable.';
	}

	if ( relatedTo.value && !term.value ) {
		return `${ relatedRows.value.length } alike`;
	}

	if ( !term.value ) {
		return fullText.value ? 'Full-text search is available.' : '';
	}

	const took = tookMs.value !== null ? ` · ${ tookMs.value } ms` : '';

	if ( titlesOnly.value ) {
		return `Titles, references and account names · ${ totalFound.value } found${ took }`;
	}

	return fullText.value && meta.value.engine === 'opensearch'
		? `Full-text search · ${ totalFound.value } found${ took }`
		: `Titles, summaries and comments · ${ totalFound.value } found${ took }`;
} );

const terms = computed( () => [ ...new Set(
	term.value
		.toLowerCase()
		.split( /\s+/ )
		.map( ( word ) => word.replace( /^["'(]+|["'),.:;!?]+$/g, '' ) )
		.filter( ( word ) => word.length >= 2 )
) ] );

const termPattern = computed( () => {
	if ( !terms.value.length ) {
		return null;
	}

	const escaped = terms.value
		.slice()
		.sort( ( a, b ) => b.length - a.length )
		.map( ( word ) => word.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) );

	return new RegExp( `(${ escaped.join( '|' ) })`, 'gi' );
} );

function marked( text ) {
	const value = String( text ?? '' );
	const pattern = termPattern.value;

	if ( !pattern || !value ) {
		return [ { text: value, match: false } ];
	}

	return value
		.split( pattern )
		.filter( ( part ) => part !== '' )
		.map( ( part ) => ( { text: part, match: terms.value.includes( part.toLowerCase() ) } ) );
}

function rowId( row ) {
	return `ts-switcher-${ row.kind }-${ row.id }`;
}

function railId( side, at ) {
	return `ts-switcher-rail-${ side }-${ at }`;
}

function isActive( row ) {
	return activeRow.value !== null && rowId( activeRow.value ) === rowId( row );
}

function makeActive( row ) {
	if ( zone.value === 'list' ) {
		activeKey.value = rowId( row );
	}
}

function icon( kind ) {
	return ICONS[ kind ] ?? cdxIconArticle;
}

function pageRow( section ) {
	return {
		kind: 'page',
		kind_label: 'Go to',
		id: section.name,
		title: section.label,
		route: section.name,
		icon: section.icon
	};
}

function accountRow( account ) {
	return {
		kind: 'subject',
		kind_label: 'Account',
		id: account.id,
		title: account.username,
		route: 'subject',
		status: account.standing,
		status_of: 'standing'
	};
}

function railAt( side ) {
	const left = layout.swap ? 'actions' : 'nav';
	const right = layout.swap ? 'nav' : 'actions';
	const which = side === 'left' ? left : right;

	return layout[ which ] ? which : null;
}

function railLabel( side ) {
	return railAt( side ) === 'nav' ? 'Go to' : 'Actions';
}

function railItems( side ) {
	return railAt( side ) === 'nav' ? navItems.value : actionItems.value;
}

function readLayout() {
	let saved = {};

	try {
		saved = JSON.parse( window.localStorage.getItem( LAYOUT_KEY ) ?? '{}' ) ?? {};
	} catch ( e ) {
		saved = {};
	}

	const merged = { ...DEFAULT_LAYOUT };

	for ( const key of Object.keys( DEFAULT_LAYOUT ) ) {
		if ( typeof saved[ key ] === typeof DEFAULT_LAYOUT[ key ] ) {
			merged[ key ] = saved[ key ];
		}
	}

	if ( !( 'preview' in saved ) ) {
		try {
			merged.preview = window.localStorage.getItem( PREVIEW_KEY ) !== '0';
		} catch ( e ) { }
	}

	return merged;
}

function setLayout( patch ) {
	Object.assign( layout, patch );

	try {
		window.localStorage.setItem( LAYOUT_KEY, JSON.stringify( layout ) );
	} catch ( e ) { }
}

function resetLayout() {
	setLayout( { ...DEFAULT_LAYOUT } );
	order.value = SECTIONS.map( ( section ) => section.value );

	try {
		window.localStorage.removeItem( ORDER_KEY );
	} catch ( e ) { }
}

function onPointerDown( e ) {
	if ( !settingsOpen.value ) {
		return;
	}

	const inside = settingsRef.value?.contains( e.target ) ||
		layoutToggleRef.value?.$el?.contains( e.target ) ||
		e.target.closest?.( '.cdx-menu' );

	if ( !inside ) {
		settingsOpen.value = false;
	}
}

function pickKind( kind, add ) {
	if ( add ) {
		kinds.value = kinds.value.includes( kind )
			? kinds.value.filter( ( k ) => k !== kind )
			: [ ...kinds.value, kind ];

		return;
	}

	kinds.value = kinds.value.length === 1 && kinds.value[ 0 ] === kind ? [] : [ kind ];
}

function addExtra( value ) {
	if ( value && !extras.value.includes( value ) ) {
		extras.value = [ ...extras.value, value ];
	}
}

function dropExtra( value ) {
	extras.value = extras.value.filter( ( extra ) => extra !== value );
}

function clearFilters() {
	titlesOnly.value = false;
	kinds.value = [];
	extras.value = [];
	since.value = 'any';
	sort.value = 'relevance';
}

function pageMatches() {
	const needle = term.value.toLowerCase();

	if ( needle.length < 2 || kinds.value.length ) {
		return { named: [], alike: [] };
	}

	const named = [];
	const alike = [];

	for ( const section of navSections() ) {
		const label = section.label.toLowerCase();

		if ( label.startsWith( needle ) || label.split( ' ' ).some( ( word ) => word.startsWith( needle ) ) ) {
			named.push( pageRow( section ) );
		} else if ( section.words.split( ' ' ).some( ( word ) => word.startsWith( needle ) ) ) {
			alike.push( pageRow( section ) );
		}
	}

	return { named: named.slice( 0, 3 ), alike: alike.slice( 0, 3 ) };
}

function jumpRow() {
	const value = term.value.toUpperCase();

	if ( !REFERENCE.test( value ) ) {
		return null;
	}

	if ( rows.value.some( ( row ) => String( row.reference ?? '' ).toUpperCase() === value ) ) {
		return null;
	}

	return {
		kind: 'jump',
		kind_label: 'Look up',
		id: value,
		title: `Go straight to ${ value }`,
		reference: value,
		route: 'item',
		icon: cdxIconArrowNext
	};
}

function score( row, at ) {
	const needle = term.value.toLowerCase();
	const title = String( row.title ?? '' ).toLowerCase();
	const reference = String( row.reference ?? '' ).toLowerCase();

	let points = -at;

	if ( reference && reference === needle ) {
		points += 1000;
	} else if ( reference && reference.startsWith( needle ) ) {
		points += 200;
	}

	if ( title === needle ) {
		points += 500;
	} else if ( title.startsWith( needle ) ) {
		points += 60;
	}

	if ( ( row.accounts ?? [] ).some( ( name ) => String( name ).toLowerCase() === needle ) ) {
		points += 120;
	}

	return points;
}

function foundGroups() {
	const out = [];
	const pages = pageMatches();
	const jump = jumpRow();
	const lead = [ ...( jump ? [ jump ] : [] ), ...pages.named ];
	const tail = rows.value.length ? pages.alike : [];

	if ( !rows.value.length ) {
		lead.push( ...pages.alike );
	}

	if ( lead.length ) {
		out.push( { key: 'go', label: 'Go to', icon: cdxIconArrowNext, rows: lead } );
	}

	if ( !rows.value.length ) {
		const alsoRelated = related.value.filter( relatedMatches );

		if ( alsoRelated.length ) {
			out.push( { key: 'related', label: relatedLabel.value, icon: cdxIconLightbulb, rows: alsoRelated } );
		}

		return out;
	}

	const ranked = sort.value === 'newest'
		? rows.value.slice()
		: rows.value
			.map( ( row, at ) => ( { row, points: score( row, at ) } ) )
			.sort( ( a, b ) => b.points - a.points )
			.map( ( entry ) => entry.row );

	const spread = new Set( ranked.map( ( row ) => row.kind ) ).size > 1;
	let rest = ranked;

	if ( spread && ranked.length > 3 ) {
		out.push( { key: 'top', label: 'Best match', icon: cdxIconLightbulb, rows: [ ranked[ 0 ] ] } );
		rest = ranked.slice( 1 );
	}

	const byKind = new Map();

	for ( const row of rest ) {
		if ( !byKind.has( row.kind ) ) {
			byKind.set( row.kind, [] );
		}

		byKind.get( row.kind ).push( row );
	}

	const kindsInOrder = [ ...byKind.keys() ].sort( ( a, b ) => {
		if ( a === contextKind.value ) {
			return -1;
		}

		if ( b === contextKind.value ) {
			return 1;
		}

		return 0;
	} );

	for ( const kind of kindsInOrder ) {
		const label = kindList.value.find( ( k ) => k.kind === kind )?.plural ?? kind;

		out.push( {
			key: kind,
			label,
			note: kind === contextKind.value && spread ? 'Where you are' : null,
			rows: byKind.get( kind )
		} );
	}

	const shown = new Set( rows.value.map( ( row ) => `${ row.kind }:${ row.id }` ) );
	const alsoRelated = related.value.filter( ( row ) => !shown.has( `${ row.kind }:${ row.id }` ) &&
		( !kinds.value.length || kinds.value.includes( row.kind ) ) &&
		relatedMatches( row ) );

	if ( alsoRelated.length ) {
		out.push( { key: 'related', label: relatedLabel.value, icon: cdxIconLightbulb, rows: alsoRelated } );
	}

	if ( tail.length ) {
		out.push( { key: 'go-also', label: 'Pages', icon: cdxIconArrowNext, rows: tail } );
	}

	return out;
}

function relatedMatches( row ) {
	const haystack = [ row.title, row.reference, row.subtitle, ...( row.accounts ?? [] ) ]
		.filter( Boolean )
		.join( ' ' )
		.toLowerCase();

	return terms.value.some( ( word ) => haystack.includes( word ) );
}

function idleGroups() {
	const seen = new Set();

	const fresh = ( list ) => list.filter( ( row ) => {
		const key = `${ row.kind }:${ row.id }`;

		if ( seen.has( key ) ) {
			return false;
		}

		seen.add( key );

		return true;
	} );

	const out = [];
	const about = relatedSeed.value;

	if ( about?.route ) {
		out.push( {
			key: 'here',
			label: 'On this page',
			icon: cdxIconEye,
			note: 'Press → for what you can do with it',
			rows: fresh( [ { ...about, title: about.title || about.reference } ] )
		} );
	}

	const filterKinds = ( list ) => ( kinds.value.length
		? list.filter( ( row ) => kinds.value.includes( row.kind ) )
		: list );

	order.value.forEach( ( section ) => {
		if ( section === 'related' ) {
			out.push( { key: 'related', label: relatedLabel.value, icon: cdxIconLightbulb, rows: fresh( related.value ) } );
		} else if ( section === 'mine' ) {
			out.push( { key: 'mine', label: 'Open, and yours', icon: cdxIconUserAvatar, rows: fresh( filterKinds( serverRecents.value ) ) } );
		} else {
			out.push( ...recentBuckets( fresh( filterKinds( localRecents.value ) ) ) );
		}
	} );

	return out.filter( ( group ) => group.rows.length );
}

function recentBuckets( list ) {
	const buckets = [
		{ key: 'today', label: 'Today', icon: cdxIconHistory, rows: [] },
		{ key: 'week', label: 'Past week', icon: cdxIconHistory, rows: [] },
		{ key: 'earlier', label: 'Earlier', icon: cdxIconHistory, rows: [] }
	];

	for ( const row of list ) {
		const days = ( Date.now() - new Date( row.visited ).getTime() ) / 86400000;
		const bucket = days < 1 ? buckets[ 0 ] : ( days < 7 ? buckets[ 1 ] : buckets[ 2 ] );

		bucket.rows.push( { ...row, updated: row.updated ?? row.visited } );
	}

	return buckets;
}

function readOrder() {
	const known = SECTIONS.map( ( section ) => section.value );

	let saved = [];

	try {
		saved = ( window.localStorage.getItem( ORDER_KEY ) ?? '' ).split( ',' );
	} catch ( e ) {
		saved = [];
	}

	const kept = saved.filter(
		( value, at ) => known.includes( value ) && saved.indexOf( value ) === at
	);

	return [ ...kept, ...known.filter( ( value ) => !kept.includes( value ) ) ];
}

function moveSection( at, by ) {
	const to = at + by;

	if ( to < 0 || to >= order.value.length ) {
		return;
	}

	const next = [ ...order.value ];
	[ next[ at ], next[ to ] ] = [ next[ to ], next[ at ] ];

	order.value = next;

	try {
		window.localStorage.setItem( ORDER_KEY, next.join( ',' ) );
	} catch ( e ) { }
}

function actionsFor( row ) {
	if ( !row ) {
		return [ { key: 'none', label: 'Pick something first', icon: cdxIconEllipsis, disabled: true, run: () => {} } ];
	}

	const list = [
		{ key: 'open', label: 'Open', icon: cdxIconArrowNext, primary: true, run: () => go( row, false ) },
		{ key: 'tab', label: 'Open in a new tab', icon: cdxIconLinkExternal, run: () => go( row, true ) }
	];

	if ( row.kind === 'page' || row.kind === 'jump' ) {
		return list;
	}

	if ( row.kind === 'case' && OPEN_CASE.includes( row.status ) && row.assignee !== me.value ) {
		list.push( {
			key: 'claim',
			label: row.assignee ? `Take it from ${ row.assignee }` : 'Take it',
			icon: cdxIconUserAdd,
			run: () => claim( row )
		} );
	}

	const card = preview.value && preview.value.kind === row.kind && preview.value.id === row.id
		? preview.value
		: null;

	const file = card?.facts.find( ( fact ) => fact.label === 'File' )?.value;

	if ( file ) {
		list.push( {
			key: 'file',
			label: `Go to ${ file }`,
			icon: cdxIconUserGroup,
			run: ( newTab ) => go( { kind: 'jump', id: file, reference: file, route: 'item' }, newTab )
		} );
	}

	for ( const account of ( card?.accounts ?? [] ).slice( 0, 2 ) ) {
		if ( row.kind === 'subject' ) {
			break;
		}

		list.push( {
			key: `account-${ account.id }`,
			label: `Go to ${ account.username }`,
			icon: cdxIconUserAvatar,
			run: ( newTab ) => go( accountRow( account ), newTab )
		} );
	}

	if ( RELATABLE.includes( row.kind ) ) {
		list.push( {
			key: 'related',
			label: 'Find things like it',
			icon: cdxIconLightbulb,
			run: () => showRelated( row )
		} );
	}

	if ( row.kind === 'subject' ) {
		list.push( {
			key: 'their-reports',
			label: 'Search reports about them',
			icon: cdxIconFlag,
			run: () => {
				q.value = `"${ row.title }"`;
				kinds.value = [ 'case' ];
				backToList();
			}
		} );
	}

	if ( row.reference ) {
		list.push( {
			key: 'copy-ref',
			label: `Copy ${ row.reference }`,
			icon: cdxIconCopy,
			run: () => copy( row.reference, `Copied ${ row.reference }.` )
		} );
	}

	list.push( {
		key: 'copy-link',
		label: 'Copy a link to it',
		icon: cdxIconLink,
		run: () => {
			const to = target( row );

			if ( to ) {
				copy( new URL( router.resolve( to ).href, window.location.origin ).href, 'Copied the link.' );
			}
		}
	} );

	return list;
}

async function copy( text, message ) {
	try {
		await navigator.clipboard.writeText( text );
		notify( message );
	} catch ( e ) {
		notify( 'The browser would not let us copy that.', 'error' );
	}
}

async function claim( row ) {
	try {
		await api.claimCase( row.id );

		for ( const list of [ rows.value, serverRecents.value, related.value, relatedRows.value ] ) {
			list.filter( ( r ) => r.kind === 'case' && r.id === row.id ).forEach( ( r ) => {
				r.assignee = me.value;
			} );
		}

		previews.delete( `case:${ row.id }` );
		searchCache.clear();
		refreshPreview();
		notify( `${ row.reference || 'The report' } is with you now.` );
		backToList();
	} catch ( e ) {
		notify( e.message || 'That report could not be taken.', 'error' );
	}
}

let relatedToRequest = 0;

async function showRelated( row ) {
	const id = ++relatedToRequest;

	relatedTo.value = row;
	relatedRows.value = [];
	q.value = '';
	backToList();
	relatedLoading.value = true;

	try {
		const response = await api.searchRelated( row.kind, row.id, { limit: 10 } );

		if ( id === relatedToRequest ) {
			relatedRows.value = response.data;
			activeKey.value = null;
		}
	} catch ( e ) {
		if ( id === relatedToRequest ) {
			relatedRows.value = [];
		}
	} finally {
		if ( id === relatedToRequest ) {
			relatedLoading.value = false;
		}
	}
}

function leaveRelated() {
	relatedToRequest++;
	relatedTo.value = null;
	relatedRows.value = [];
	relatedLoading.value = false;
}

function showActionsFor( row ) {
	activeKey.value = rowId( row );

	const side = layout.swap ? 'left' : 'right';

	if ( railAt( side ) === 'actions' ) {
		enterRail( side );
	} else if ( railAt( side === 'left' ? 'right' : 'left' ) === 'actions' ) {
		enterRail( side === 'left' ? 'right' : 'left' );
	}

	inputRef.value?.focus( { preventScroll: true } );
}

const searchCache = new Map();

let timer = null;
let inFlight = null;
let requestId = 0;

function params() {
	return {
		kinds: kinds.value.join( ',' ) || null,
		titles_only: titlesOnly.value || null,
		mine: extras.value.includes( 'mine' ) || null,
		unassigned: extras.value.includes( 'unassigned' ) || null,
		urgent: extras.value.includes( 'urgent' ) || null,
		open_only: extras.value.includes( 'open' ) || null,
		since: since.value === 'any' ? null : since.value,
		sort: sort.value === 'newest' ? 'newest' : null,
		limit: 40
	};
}

function applyResponse( response ) {
	rows.value = response.data;
	meta.value = { ...meta.value, ...response.meta };
	error.value = false;
	activeKey.value = null;
}

function schedule() {
	clearTimeout( timer );

	if ( term.value && relatedTo.value ) {
		leaveRelated();
	}

	if ( !term.value ) {
		inFlight?.abort();
		rows.value = [];
		loading.value = false;
		error.value = false;
		tookMs.value = null;

		return;
	}

	const key = JSON.stringify( [ term.value, params() ] );
	const cached = searchCache.get( key );

	if ( cached && Date.now() - cached.at < CACHE_FOR ) {
		inFlight?.abort();
		requestId++;
		applyResponse( cached.response );
		tookMs.value = 0;
		loading.value = false;

		return;
	}

	loading.value = true;
	timer = setTimeout( () => run( key ), rows.value.length ? 110 : 70 );
}

async function run( key ) {
	const id = ++requestId;
	const started = performance.now();

	inFlight?.abort();
	inFlight = new AbortController();

	try {
		const response = await api.search( term.value, params(), { signal: inFlight.signal } );

		if ( id !== requestId ) {
			return;
		}

		searchCache.set( key, { at: Date.now(), response } );

		if ( searchCache.size > 40 ) {
			searchCache.delete( searchCache.keys().next().value );
		}

		tookMs.value = Math.round( performance.now() - started );
		applyResponse( response );
	} catch ( e ) {
		if ( e.name === 'AbortError' || id !== requestId ) {
			return;
		}

		rows.value = [];
		error.value = true;
	} finally {
		if ( id === requestId ) {
			loading.value = false;
		}
	}
}

watch( q, schedule );
watch( [ titlesOnly, since, sort ], schedule );
watch( kinds, schedule, { deep: true } );
watch( extras, schedule, { deep: true } );

let previewTimer = null;
let previewRequest = 0;
let previewInFlight = null;

function previewable( row ) {
	return row && row.kind !== 'page' && row.kind !== 'jump' && row.kind !== 'transparency';
}

watch( [ activeRow, showPreview ], refreshPreview );

function refreshPreview() {
	clearTimeout( previewTimer );
	previewRequest++;
	previewInFlight?.abort();

	const row = activeRow.value;

	if ( !previewable( row ) ) {
		preview.value = null;
		previewLoading.value = false;

		return;
	}

	const key = `${ row.kind }:${ row.id }`;

	if ( previews.has( key ) ) {
		preview.value = previews.get( key );
		previewLoading.value = false;
		prefetchNext( row );

		return;
	}

	previewLoading.value = true;
	previewTimer = setTimeout( () => loadPreview( row, key ), 60 );
}

async function fetchPreview( row, key, signal ) {
	const response = await api.searchPreview( row.kind, row.id, { signal } );

	previews.set( key, response.data );

	return response.data;
}

async function loadPreview( row, key ) {
	const id = ++previewRequest;

	previewInFlight?.abort();
	previewInFlight = new AbortController();

	try {
		const card = await fetchPreview( row, key, previewInFlight.signal );

		if ( id === previewRequest ) {
			preview.value = card;
			prefetchNext( row );
		}
	} catch ( e ) {
		if ( e.name === 'AbortError' ) {
			return;
		}

		if ( id === previewRequest ) {
			previews.delete( key );
			preview.value = null;
		}
	} finally {
		if ( id === previewRequest ) {
			previewLoading.value = false;
		}
	}
}

const prefetching = new Set();

function prefetchNext( row ) {
	const list = flat.value;
	const at = list.findIndex( ( r ) => rowId( r ) === rowId( row ) );

	for ( const next of [ list[ at + 1 ], list[ at - 1 ] ] ) {
		if ( !previewable( next ) ) {
			continue;
		}

		const key = `${ next.kind }:${ next.id }`;

		if ( previews.has( key ) || prefetching.has( key ) ) {
			continue;
		}

		prefetching.add( key );
		fetchPreview( next, key ).catch( () => {} ).finally( () => prefetching.delete( key ) );
	}
}

let relatedRequest = 0;
let relatedInFlight = null;

async function loadRelated() {
	const id = ++relatedRequest;

	relatedInFlight?.abort();

	related.value = [];
	relatedSeed.value = null;

	const item = seed.value;

	if ( !item ) {
		relatedLoading.value = false;

		return;
	}

	relatedInFlight = new AbortController();
	relatedLoading.value = true;

	try {
		const response = await api.searchRelated(
			item.kind,
			item.id,
			{ limit: 5 },
			{ signal: relatedInFlight.signal }
		);

		if ( id !== relatedRequest ) {
			return;
		}

		related.value = response.data;
		relatedSeed.value = response.meta.seed;
	} catch ( e ) {
		if ( id === relatedRequest ) {
			related.value = [];
			relatedSeed.value = null;
		}
	} finally {
		if ( id === relatedRequest ) {
			relatedLoading.value = false;
		}
	}
}

async function loadRecents() {
	localRecents.value = recents();

	try {
		const response = await api.searchRecents();

		serverRecents.value = response.data;
		kindList.value = response.meta.kinds;
		meta.value = { ...meta.value, full_text: response.meta.full_text };
	} catch ( e ) {
		serverRecents.value = [];
	}
}

let lastClosed = 0;
let lastQuery = '';

function pageSelection() {
	const text = String( window.getSelection?.() ?? '' ).replace( /\s+/g, ' ' ).trim();

	return text.length >= 2 && text.length <= 80 ? text : '';
}

watch( () => props.open, ( isOpen ) => {
	if ( !isOpen ) {
		clearTimeout( timer );
		clearTimeout( previewTimer );
		inFlight?.abort();
		relatedInFlight?.abort();
		previewInFlight?.abort();
		lastClosed = Date.now();
		lastQuery = q.value;

		return;
	}

	const selected = pageSelection();
	const restore = !selected && !seed.value && Date.now() - lastClosed < 120000 ? lastQuery : '';

	q.value = selected || restore;
	error.value = false;
	activeKey.value = null;
	settingsOpen.value = false;
	zone.value = 'list';
	previews.clear();
	preview.value = null;
	leaveRelated();

	loadRecents();
	loadRelated();
	schedule();
	refreshPreview();

	nextTick( () => {
		inputRef.value?.focus();

		if ( q.value ) {
			inputRef.value?.select();
		}
	} );
} );

function onGlobalKeydown( e ) {
	if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
		e.preventDefault();
		emit( 'update:open', !props.open );
	}
}

onMounted( () => {
	window.addEventListener( 'keydown', onGlobalKeydown );
	document.addEventListener( 'pointerdown', onPointerDown, true );
} );

onUnmounted( () => {
	window.removeEventListener( 'keydown', onGlobalKeydown );
	document.removeEventListener( 'pointerdown', onPointerDown, true );
	clearTimeout( timer );
	clearTimeout( previewTimer );
	inFlight?.abort();
	relatedInFlight?.abort();
	previewInFlight?.abort();
} );

function close( value ) {
	emit( 'update:open', value );
}

function scrollToActive() {
	nextTick( () => {
		document.getElementById( activeDescendant.value )?.scrollIntoView( { block: 'nearest' } );
	} );
}

function move( by ) {
	const list = flat.value;

	if ( !list.length ) {
		return;
	}

	const at = list.findIndex( ( row ) => rowId( row ) === rowId( activeRow.value ?? {} ) );
	const next = ( ( at === -1 ? 0 : at ) + by + list.length ) % list.length;

	activeKey.value = rowId( list[ next ] );
	scrollToActive();
}

function target( row ) {
	if ( !row?.route ) {
		return null;
	}

	if ( row.kind === 'jump' ) {
		return { name: 'item', params: { reference: row.reference } };
	}

	if ( row.kind === 'page' ) {
		return { name: row.route };
	}

	return DETAIL_ROUTES.includes( row.route )
		? { name: row.route, params: { id: String( row.id ) } }
		: { name: row.route };
}

function go( row, newTab ) {
	const to = target( row );

	if ( !to ) {
		return;
	}

	if ( row.kind !== 'page' && row.kind !== 'jump' ) {
		remember( row );
	}

	if ( newTab ) {
		window.open( router.resolve( to ).href, '_blank', 'noopener' );

		return;
	}

	q.value = '';
	router.push( to );
	close( false );
}

function enterRail( side ) {
	const items = railItems( side );

	hovering = null;
	zone.value = side;
	railIndex.value = Math.max( 0, items.findIndex( ( item ) => !item.disabled ) );

	if ( railAt( side ) === 'nav' ) {
		const current = items.findIndex( ( item ) => item.current );

		if ( current > 0 ) {
			railIndex.value = current;
		}
	}
}

function keepFocus( e ) {
	if ( e.target.closest?.( '.ts-switcher__rail-item, .ts-switcher__row' ) ) {
		e.preventDefault();
	}

	if ( e.target.closest?.( '.ts-switcher__list, .ts-switcher__preview' ) && zone.value !== 'list' ) {
		backToList();
	}
}

function backToList() {
	zone.value = 'list';
	hovering = null;
}

let hovering = null;

function hoverRail( side ) {
	if ( window.matchMedia( '( max-width: 48rem )' ).matches || zone.value === side ) {
		return;
	}

	hovering = side;
	zone.value = side;
	railIndex.value = -1;
}

function unhoverRail( side ) {
	if ( hovering === side && zone.value === side ) {
		backToList();
	}

	hovering = null;
}

function onFrameKeydown( e ) {
	if ( e.target === inputRef.value || e.defaultPrevented ) {
		return;
	}

	if ( e.target.closest?.( '.ts-switcher__settings, .cdx-menu' ) ) {
		return;
	}

	if ( [ 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown' ].includes( e.key ) ) {
		inputRef.value?.focus( { preventScroll: true } );
		onKeydown( e );
	}
}

function runRailItem( item, e ) {
	if ( item.disabled ) {
		return;
	}

	item.run( e?.metaKey || e?.ctrlKey );
}

function caretAt( edge ) {
	const input = inputRef.value;

	if ( !input || input.selectionStart !== input.selectionEnd ) {
		return false;
	}

	return edge === 'start' ? input.selectionStart === 0 : input.selectionEnd === input.value.length;
}

function onRailKeydown( e ) {
	const items = railItems( zone.value );
	const inward = zone.value === 'left' ? 'ArrowRight' : 'ArrowLeft';
	const outward = zone.value === 'left' ? 'ArrowLeft' : 'ArrowRight';

	if ( ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) && hovering ) {
		hovering = null;
	}

	if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
		e.preventDefault();

		const by = e.key === 'ArrowDown' ? 1 : -1;
		let next = railIndex.value < 0 ? ( by === 1 ? -1 : items.length ) : railIndex.value;

		for ( let i = 0; i < items.length; i++ ) {
			next = ( next + by + items.length ) % items.length;

			if ( !items[ next ].disabled ) {
				break;
			}
		}

		railIndex.value = next;
		scrollToActive();
	} else if ( e.key === 'Enter' ) {
		e.preventDefault();

		const item = items[ railIndex.value ];

		if ( item ) {
			runRailItem( item, e );
		}
	} else if ( e.key === 'Escape' ) {
		e.preventDefault();
		close( false );
	} else if ( e.key === inward ) {
		e.preventDefault();
		backToList();
	} else if ( e.key === outward ) {
		e.preventDefault();
	} else if ( /^[1-9]$/.test( e.key ) && !e.metaKey && !e.ctrlKey && !e.altKey ) {
		const item = items[ Number( e.key ) - 1 ];

		e.preventDefault();

		if ( item ) {
			railIndex.value = Number( e.key ) - 1;
			runRailItem( item, e );
		}
	} else if ( e.key === 'Home' || e.key === 'End' ) {
		e.preventDefault();
		railIndex.value = e.key === 'Home' ? 0 : items.length - 1;
	} else if ( e.key.length === 1 || e.key === 'Backspace' ) {
		backToList();
	}
}

function onKeydown( e ) {
	if ( e.isComposing ) {
		return;
	}

	const railKeys = [ 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight' ];

	if ( hovering && !railKeys.includes( e.key ) && !( e.key === 'Enter' && railIndex.value >= 0 ) ) {
		backToList();
	}

	if ( zone.value !== 'list' ) {
		onRailKeydown( e );

		return;
	}

	if ( e.altKey && /^Digit\d$/.test( e.code ) ) {
		e.preventDefault();

		const at = Number( e.code.slice( 5 ) );

		if ( at === 0 ) {
			kinds.value = [];
		} else if ( kindList.value[ at - 1 ] ) {
			pickKind( kindList.value[ at - 1 ].kind, e.shiftKey );
		}

		return;
	}

	if ( e.key === 'ArrowDown' ) {
		e.preventDefault();
		move( 1 );
	} else if ( e.key === 'ArrowUp' ) {
		e.preventDefault();
		move( -1 );
	} else if ( e.key === 'ArrowLeft' && railAt( 'left' ) && caretAt( 'start' ) && !e.shiftKey ) {
		e.preventDefault();
		enterRail( 'left' );
	} else if ( e.key === 'ArrowRight' && railAt( 'right' ) && caretAt( 'end' ) && !e.shiftKey ) {
		e.preventDefault();
		enterRail( 'right' );
	} else if ( e.key === 'Enter' ) {
		e.preventDefault();

		if ( activeRow.value ) {
			go( activeRow.value, e.metaKey || e.ctrlKey );
		}
	} else if ( e.key === 'Escape' ) {
		e.preventDefault();
		close( false );
	}
}
</script>
