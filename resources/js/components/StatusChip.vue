<template>
	<cdx-info-chip :status="status">{{ label }}</cdx-info-chip>
</template>

<script setup>
import { computed } from 'vue';
import { CdxInfoChip } from '@wikimedia/codex';
import {
	STATUS_LABELS, STATUS_CHIP,
	STANDING_LABELS, STANDING_CHIP,
	PUSH_LABELS, PUSH_CHIP,
	FORCE_LABELS, FORCE_CHIP,
	INVESTIGATION_STATUS_LABELS, INVESTIGATION_STATUS_CHIP,
	PRIORITY_LABELS, PRIORITY_CHIP,
	REMOVAL_LABELS, REMOVAL_CHIP,
	ROLE_LABELS, ROLE_CHIP,
	OUTCOME_LABELS,
	DATA_KIND_LABELS,
	DATA_DECISION_LABELS, DATA_DECISION_CHIP,
	APPEAL_OUTCOME_LABELS, APPEAL_OUTCOME_CHIP,
	APPEAL_CONFIDENCE_LABELS, APPEAL_CONFIDENCE_CHIP,
	APPEAL_LINK_LABELS
} from '../lib/format.js';

const props = defineProps( {
	kind: { type: String, default: 'case' },
	value: { type: String, required: true }
} );

const MAPS = {
	case: [ STATUS_LABELS, STATUS_CHIP ],
	standing: [ STANDING_LABELS, STANDING_CHIP ],
	push: [ PUSH_LABELS, PUSH_CHIP ],
	force: [ FORCE_LABELS, FORCE_CHIP ],
	investigation: [ INVESTIGATION_STATUS_LABELS, INVESTIGATION_STATUS_CHIP ],
	priority: [ PRIORITY_LABELS, PRIORITY_CHIP ],
	removal: [ REMOVAL_LABELS, REMOVAL_CHIP ],
	role: [ ROLE_LABELS, ROLE_CHIP ],
	outcome: [ OUTCOME_LABELS, {} ],
	'data-kind': [ DATA_KIND_LABELS, {} ],
	'data-decision': [ DATA_DECISION_LABELS, DATA_DECISION_CHIP ],
	'appeal-outcome': [ APPEAL_OUTCOME_LABELS, APPEAL_OUTCOME_CHIP ],
	'appeal-confidence': [ APPEAL_CONFIDENCE_LABELS, APPEAL_CONFIDENCE_CHIP ],
	'appeal-link': [ APPEAL_LINK_LABELS, {} ]
};

const label = computed( () => MAPS[ props.kind ]?.[ 0 ][ props.value ] ?? props.value );
const status = computed( () => MAPS[ props.kind ]?.[ 1 ][ props.value ] ?? 'notice' );
</script>
