export type ChatMessage = {
    id: number;
    gameId: number;
    authorId: number | null;
    authorName: string;
    clientMessageId: string;
    body: string;
    createdAt: string;
};

export type ChatHistoryPage = {
    data: ChatMessage[];
    hasMore: boolean;
};
