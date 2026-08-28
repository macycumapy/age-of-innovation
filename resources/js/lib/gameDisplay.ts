import type { Faction, PlayerColor, RoundBonus, TerrainType } from '@/types';

export const terrainNames: Record<TerrainType, string> = {
    desert: 'Пустыня',
    plains: 'Равнина',
    swamp: 'Болото',
    lake: 'Озеро',
    forest: 'Лес',
    mountain: 'Горы',
    wasteland: 'Пустошь',
    water: 'Вода',
};

export const terrainColors: Record<TerrainType, string> = {
    desert: '#e9c65c',
    plains: '#9c6339',
    swamp: '#334c62',
    lake: '#39a6bd',
    forest: '#477c48',
    mountain: '#87909a',
    wasteland: '#b8513e',
    water: '#2499b5',
};

export const playerColorValues: Record<PlayerColor, string> = {
    yellow: '#facc15',
    red: '#ef4444',
    black: '#18181b',
    blue: '#3b82f6',
    green: '#22c55e',
    brown: '#92400e',
    grey: '#9ca3af',
};

export const factionNames: Record<Faction, string> = {
    blessed: 'Благословенные',
    felines: 'Кошачьи',
    goblins: 'Гоблины',
    illusionists: 'Иллюзионисты',
    inventors: 'Изобретатели',
    lizards: 'Ящеры',
    moles: 'Кроты',
    monks: 'Монахи',
    navigators: 'Навигаторы',
    omar: 'Омар',
    philosophers: 'Философы',
    psychics: 'Провидцы',
};

export const roundBonusNames: Record<RoundBonus, string> = {
    river_workshop: 'Речная мастерская',
    send_scholar: 'Отправка учёного',
    build_guild: 'Строительство гильдии',
    pass_palace_university: 'Дворцы и университеты',
    spade: 'Бесплатная лопата',
    bridge: 'Бесплатный мост',
    knowledge: 'Шаг знания',
    pass_school: 'Школы при пасе',
    power_coins: 'Сила и монеты',
    coins: 'Монеты',
};
