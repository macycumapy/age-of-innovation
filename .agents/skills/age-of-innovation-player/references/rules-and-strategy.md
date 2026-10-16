# Rules and strategy

The current engine and live legal options take precedence over these notes and remembered board-game rules. Source paths are relative to the repository root.

## Economy and setup

Setup bundles homeland, faction, and round bonus. Evaluate the combination against terrain access, expansion routes, income, competencies/palaces, and upcoming round goals. Faction starts differ: Monks begin with a university, for example.

Coins and tools fund construction; scholars support knowledge/development; books have discipline-specific uses. Preserve the colors needed for a planned purchase before converting books to coins.

Only power in bowl III is spendable. Distinguish charging existing tokens from gaining new ones. Sacrifice reduces future capacity; justify it by an affordable immediate opportunity. Offered power may cost points: compare the actual charge with access to useful shared actions.

Power actions cost 3 for a bridge/scholar, 4 for tools/coins/one spade, 6 for two spades before modifiers. Illusionists pay one less. Ordinary conversions differ: a scholar costs 5 power, tool 3, coin 1, selected book 5 in the current finder. Use live options for quantities and eligibility.

Sources: `app/Domain/GameEngine/Economy/Enums/PowerAction.php`, `app/Domain/GameEngine/Economy/Services/ResourceConversionOptionFinder.php`, `app/Domain/GameEngine/Economy/Services/PlayerIncomeCalculator.php`.

## Buildings, towns, and networks

Upgrade tree: workshop → guild → palace or school; school → university. Workshop-to-guild base cost is two tools and six coins, or three coins beside an opponent. Compare net income after replacing a building, not both buildings' incomes added together.

Building power: workshop 1; guild/school/tower 2; palace/university 3; monument 4. Normally a town needs four connected buildings and power seven. A university reduces the building count to three; a monument to two. Annexes reduce the required count and add power. palace_08 reduces required power to six. Existing town membership can invalidate combinations.

Town connectivity differs from final-network connectivity. Ordinary towns use direct adjacency and owned bridges; exceptions such as palace_14 are explicit. Final networks use their own calculator including shipping. Do not assume shipping connects a town, or guess adjacency from coordinate strings.

Protect routes opponents can block. Check the independence of a second town before merging components into an existing town. Balance separate town opportunities against final network ranking.

Sources: `app/Domain/GameEngine/Board/Enums/BuildingType.php`, `app/Domain/GameEngine/Towns/Actions/FindEligibleTownHexesAction.php`, `app/Domain/GameEngine/Board/Services/LargestNetworkSizeCalculator.php`.

## Scoring

When the current tile rewards that goal, construction awards workshop 2, guild 3, school 4, palace/university 5 points. Other tile goals award spade use 2, knowledge step 1, town tile 5, development step 3, innovation 5. These are conditional round awards, not permanent rewards for those actions. Owned abilities can add rewards.

Round tiles also define science bonuses by discipline and level interval. Check payout thresholds and reward timing. Blessed use an effective knowledge level three higher for science bonuses. The additional sixth-round building goal is separate from final rankings.

Final network ranking awards 18/12/6; each knowledge discipline awards 8/4/2. Ties divide occupied-place awards, rounded down. Level-zero players are excluded from knowledge ranking. Two-player games can include neutral knowledge and a configured neutral territory score that occupies ranking places. Compare the entire field. Do not invent a final resource conversion from another game's rules.

Sources: `app/Domain/GameEngine/Scoring/Enums/RoundScoringTile.php`, `app/Domain/GameEngine/Scoring/Enums/FinalRoundScoringTile.php`, `app/Domain/GameEngine/Scoring/Actions/ApplyFinalScoringAction.php`, `app/Domain/GameEngine/Scoring/Actions/ApplyPassBonusesAction.php`.

## Planning

Early: favor income and expansion that fund future scoring. Count remaining income collections and retain an affordable next build. A rich economy without usable terrain can stall.

Middle: turn income into towns, competencies, and innovations while aligning construction with round goals. Shared actions, scarce components, and contested hexes can justify acting before the numerically highest immediate reward. A lower-point town tile may fund another profitable action.

Late: prioritize achievable points, pass rewards, and ranking swings. New income needs a remaining payout and a use. Calculate whether a network link or knowledge step actually overtakes a place.

Before passing, inspect unused special actions, reachable round goals, all owned pass bonuses, and available replacement round bonuses. Do not spend merely because a conversion is legal or delay passing with an unproductive action.

`StrategicPriorities::settlementValue()` scales a supplied settlement heuristic to 40% in rounds 1–2, 70% in round 3, and 100% later, except setup and town-scoring rounds. This is neither a point payout nor a universal prohibition on early towns.

Source: `app/Domain/Automation/Evaluation/Services/StrategicPriorities.php`.

## Strategic decision checks

Use these checks as planning aids, not as a fixed strategy. Verify mechanical details against the current player-visible state and legal options.

- **Check usable knowledge advancement before buying it.** Track the actual cap and unlock status of each discipline, the requirements for unlocking further advancement, and the attainable steps of a multi-step reward. Do not value a blocked step as points, income, or ranking progress. If advancement falls short of the estimate, correct the plan before repeating it; do not keep acquiring scholars without a useful outlet.
- **Plan town geometry during setup and early expansion.** Name candidate hexes, required buildings, power, bridges or annexes, and the remaining construction costs. Shipping access is not town connectivity. Check a second town's independence before connecting it to an existing town. Compare the timing and reward of a town with the income and round scoring sacrificed to obtain it; do not postpone all town planning until the middle game.
- **Budget the economy after upgrades.** An upgraded workshop no longer provides its previous income. Compare the resulting total income, remaining payouts, and resources needed for the next useful build. Keep an affordable terrain/building continuation rather than a theoretically strong economy stranded without tools or expansion access.
- **Price the long-term cost of sacrificing power.** Check remaining token capacity and whether future charging can still fund useful actions. Heavy early sacrifice needs a concrete payoff; access to an action alone is not enough reason to take it.
- **Compare complete scoring packages.** Include town rewards, unlocks, owned pass bonuses, innovation scaling, and final rankings without double-counting. For example, league_of_cities scales with town tiles; assess its timing against realistic additional towns and competing innovations, not just its current payout. Count actual connected network size rather than all owned buildings.
- **Compare alternatives before expensive commitments.** For a major upgrade, innovation, or resource conversion, consider a feasible competing line and the next few actions each enables. Distinguish verified costs/effects from estimates. After confirmation, check actual resource, income, knowledge, and connectivity changes instead of reporting the intended result as accomplished.
