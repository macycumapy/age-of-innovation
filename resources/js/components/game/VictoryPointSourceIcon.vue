<script setup lang="ts">
import { computed } from 'vue';
import blessedUrl from '../../../images/factions/blessed.jpg';
import felinesUrl from '../../../images/factions/felines.jpg';
import goblinsUrl from '../../../images/factions/goblins.jpg';
import illusionistsUrl from '../../../images/factions/illusionists.jpg';
import inventorsUrl from '../../../images/factions/inventors.jpg';
import lizardsUrl from '../../../images/factions/lizards.jpg';
import molesUrl from '../../../images/factions/moles.jpg';
import monksUrl from '../../../images/factions/monks.jpg';
import navigatorsUrl from '../../../images/factions/navigators.jpg';
import omarUrl from '../../../images/factions/omar.jpg';
import philosophersUrl from '../../../images/factions/philosophers.jpg';
import psychicsUrl from '../../../images/factions/psychics.jpg';
import bookActionsUrl from '../../../images/token_parts/score_book_actions.png';
import competencyUrl from '../../../images/token_parts/score_competency.png';
import developmentUrl from '../../../images/token_parts/score_development.png';
import remainingResourcesUrl from '../../../images/token_parts/score_end_game_resources.png';
import factionBonusUrl from '../../../images/token_parts/score_faction.png';
import finalKnowledgeUrl from '../../../images/token_parts/score_final_knowledge.png';
import finalObjectivesUrl from '../../../images/token_parts/score_final_objectives.png';
import innovationUrl from '../../../images/token_parts/score_innovation.png';
import knowledgeIncomeUrl from '../../../images/token_parts/score_knowledge_income.png';
import palaceUrl from '../../../images/token_parts/score_palace.png';
import powerOffersUrl from '../../../images/token_parts/score_power_offers.png';
import roundBonusUrl from '../../../images/token_parts/score_round_bonus.png';
import roundScoringUrl from '../../../images/token_parts/score_round_scoring.png';
import startingUrl from '../../../images/token_parts/score_starting.png';
import townUrl from '../../../images/token_parts/score_town.png';
import untrackedUrl from '../../../images/token_parts/score_untracked.png';
import type { Faction } from '@/types';

const props = defineProps<{
    source: string;
    faction?: Faction | null;
    portrait?: boolean;
    muted?: boolean;
}>();

const factionImages: Record<Faction, string> = {
    blessed: blessedUrl,
    felines: felinesUrl,
    goblins: goblinsUrl,
    illusionists: illusionistsUrl,
    inventors: inventorsUrl,
    lizards: lizardsUrl,
    moles: molesUrl,
    monks: monksUrl,
    navigators: navigatorsUrl,
    omar: omarUrl,
    philosophers: philosophersUrl,
    psychics: psychicsUrl,
};
const illustrations: Record<string, string> = {
    starting: startingUrl,
    round_scoring: roundScoringUrl,
    town: townUrl,
    innovation: innovationUrl,
    competency: competencyUrl,
    final_objectives: finalObjectivesUrl,
    final_knowledge: finalKnowledgeUrl,
    round_bonus: roundBonusUrl,
    faction: factionBonusUrl,
    palace: palaceUrl,
    development: developmentUrl,
    knowledge_income: knowledgeIncomeUrl,
    book_actions: bookActionsUrl,
    end_game_resources: remainingResourcesUrl,
    power_offers: powerOffersUrl,
    untracked: untrackedUrl,
};
const portraitArtwork = computed(() =>
    props.source === 'faction' && props.faction && props.portrait ? factionImages[props.faction] : undefined,
);
const artwork = computed(() => portraitArtwork.value ?? illustrations[props.source] ?? untrackedUrl);
const rim = Array.from({ length: 48 }, (_, index) => {
    const angle = (index * Math.PI) / 24 - Math.PI / 2;
    const radius = index % 2 === 0 ? 23 : 20;

    return `${24 + Math.cos(angle) * radius},${24 + Math.sin(angle) * radius}`;
}).join(' ');
</script>

<template>
    <span
        class="relative isolate grid aspect-square shrink-0 place-items-center"
        :class="muted ? 'opacity-40 saturate-50' : ''"
        :data-score-source="source"
        aria-hidden="true"
    >
        <svg viewBox="0 0 48 48" class="absolute inset-0 size-full drop-shadow-sm" fill="none" aria-hidden="true">
            <polygon :points="rim" fill="#bd8d32" stroke="#76501d" stroke-width="1" />
            <circle cx="24" cy="24" r="18.5" fill="#e4c675" stroke="#ffdfa0" stroke-width="1.5" />
            <circle cx="24" cy="24" r="16" fill="#cfa74e" stroke="#8c6327" stroke-width="1" />
            <path d="M12 17a15 15 0 0 1 22-4" stroke="#fff0bc" stroke-width="1.5" stroke-linecap="round" />
        </svg>
        <img
            :src="artwork"
            alt=""
            class="absolute inset-0 z-10 m-auto drop-shadow-sm"
            :class="
                portraitArtwork ? 'size-[62%] rounded-full object-cover object-[80%_25%]' : 'size-[78%] object-contain'
            "
        />
    </span>
</template>
