export function specialActionDescription(
    payload: Record<string, unknown>,
    disciplineNames: Record<string, string>,
): string {
    if (payload.palace_bridge === 'palace_15') {
        return 'построил мост за Дворец';
    }

    if (payload.palace_bridge_skipped === 'palace_15') {
        return 'отказался от строительства моста за Дворец';
    }

    if (payload.round_bonus === 'bridge') {
        return 'построил мост за бонус раунда';
    }

    if (payload.round_bonus === 'spade') {
        return 'получил 1 лопату за бонус раунда';
    }

    if (payload.round_bonus === 'knowledge') {
        const discipline = typeof payload.discipline === 'string' ? payload.discipline : null;
        const disciplineName = discipline === null ? null : (disciplineNames[discipline] ?? discipline);

        return `продвинулся на 1 шаг по культам за бонус раунда${disciplineName === null ? '' : `: ${disciplineName}`}`;
    }

    if (payload.faction === 'moles' && typeof payload.from_hex_id === 'string') {
        return 'построил мост за способность Кротов';
    }

    if (typeof payload.palace === 'string') {
        return 'использовал действие Дворца';
    }

    return 'выполнил особое действие';
}
