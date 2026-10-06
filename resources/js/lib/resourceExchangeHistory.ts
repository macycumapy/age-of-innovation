function exchangeCount(value: unknown): number {
    const count = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value;

    return typeof count === 'number' && Number.isSafeInteger(count) && count > 0 ? count : 0;
}

export function resourceExchangeDetails(exchanges: unknown, disciplineNames: Record<string, string>): string[] {
    if (typeof exchanges !== 'object' || exchanges === null || Array.isArray(exchanges)) {
        return [];
    }

    const counts = exchanges as Record<string, unknown>;
    const scalarExchanges: Array<[string, number, string, string]> = [
        ['power_to_scholar', 5, 'Силы', 'учёных'],
        ['power_to_tool', 3, 'Силы', 'инстр.'],
        ['power_to_coin', 1, 'Силы', 'золота'],
        ['scholar_to_tool', 1, 'учёных', 'инстр.'],
        ['tool_to_coin', 1, 'инстр.', 'золота'],
    ];
    const details = scalarExchanges.flatMap(([key, cost, source, target]) => {
        const count = exchangeCount(counts[key]);

        return count > 0 ? [`потрачено ${count * cost} ${source} → получено ${count} ${target}`] : [];
    });

    for (const key of ['power_to_book', 'book_to_coin']) {
        const books = counts[key];

        if (typeof books !== 'object' || books === null || Array.isArray(books)) {
            continue;
        }

        for (const [discipline, value] of Object.entries(books)) {
            const count = exchangeCount(value);
            if (count === 0) {
                continue;
            }

            const bookForm =
                count % 100 >= 11 && count % 100 <= 14
                    ? 'книг'
                    : count % 10 === 1
                      ? 'книга'
                      : count % 10 >= 2 && count % 10 <= 4
                        ? 'книги'
                        : 'книг';
            const bookLabel = `${bookForm} (${disciplineNames[discipline] ?? discipline})`;

            details.push(
                key === 'power_to_book'
                    ? `потрачено ${count * 5} Силы → получено ${count} ${bookLabel}`
                    : `потрачено ${count} ${bookLabel} → получено ${count} золота`,
            );
        }
    }

    return details;
}
