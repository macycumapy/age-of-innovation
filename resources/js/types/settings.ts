import type { GameBotDifficulty } from './game';

export type Settings = {
    bots: {
        enabled: boolean;
        available_difficulties: GameBotDifficulty[];
    };
};
