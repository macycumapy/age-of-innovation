# Component lookup

Read only components relevant to the position. Summaries describe enum effects; current action implementations and live options determine costs, timing, and restrictions. Source paths are relative to the project root.

## Factions

Source: `app/Domain/GameEngine/PlayerAbilities/Enums/Faction.php`; description() also lists starting rewards.

| ID | Strategic ability |
| --- | --- |
| blessed | Effective science-bonus knowledge level +3. |
| felines | Town adds three distributed knowledge steps and selected book. |
| goblins | Each used spade gives two coins. |
| illusionists | Cheaper power actions that also award points. |
| inventors | Starts with a chosen competency and its stack rewards. |
| lizards | Town grants a free terraform/build opportunity. |
| moles | Tunnels and tool-funded bridges. |
| monks | Starts with university and competency instead of workshops. |
| navigators | River-bank workshop gives two points and a coin. |
| omar | Starts with neutral tower; income two coins and two power. |
| philosophers | Competencies give books; special action gives book. |
| psychics | Special action gives five power and additional action. |

## Competencies

Source: `app/Domain/GameEngine/Research/Enums/Competency.php`. Account for stack rewards and placement follow-ups.

| ID | Effect |
| --- | --- |
| competency_01 | Income tool and selected knowledge step. |
| competency_02 | Income three points and two coins. |
| competency_03 | Income selected book and one power. |
| competency_04 | Immediate tool, five points, two coins. |
| competency_05 | Terraform/build with two free spades. |
| competency_06 | Two annexes for town size and power. |
| competency_07 | Special action four power. |
| competency_08 | Pass two points per town tile. |
| competency_09 | Sending scholar gives two points. |
| competency_10 | Neutral tower; income two coins and two power. |
| competency_11 | Edge workshop construction gives three points. |
| competency_12 | Pass points equal to weakest knowledge level. |

## Innovations

Sources: `app/Domain/GameEngine/Research/Enums/Innovation.php`, `app/Domain/GameEngine/Research/Services/InnovationPurchaseCostCalculator.php`, `app/Domain/GameEngine/Research/Services/InnovationVictoryPointCalculator.php`.

| ID | Effect |
| --- | --- |
| deus_ex_machina | Book, step in all disciplines, special one-spade terraform. |
| trade_routes | Pass two points per guild. |
| professor | Special action scholar and three points. |
| sewage_system | Immediate two points per workshop. |
| architecture | Steps based on built building forms, plus ten points. |
| library | Points from sum of two best knowledge levels. |
| steam_engine | Scholar and free shipping/terraforming advancement. |
| league_of_cities | Immediate five points per town tile. |
| telecommunication | 8/12/18 points for 4/5/6+ settlement areas. |
| steel | 8/12/18 points for 1/2/3 bridges. |
| census | 8/12/18 points for 7–8/9–10/11+ buildings. |
| science | Immediate five points per school. |
| workshop | Neutral workshop; income three tools. |
| guild | Neutral guild; income five coins. |
| school | Neutral school and any competency. |
| university | Neutral university without competency; income two points. |
| palace | Two new power in bowl III, neutral palace without ability; income four power. |
| monument | Neutral monument and seven points. |

Do not count future buildings in an immediate acquisition award. Weigh delaying the purchase until a threshold against losing the component or the book payment opportunity.

## Palaces

Source: `app/Domain/GameEngine/PlayerAbilities/Enums/PalaceAbility.php`. Check `app/Domain/GameEngine/PlayerAbilities/Actions/ChoosePalaceAction.php` and follow-up actions for reward order.

| ID | Main effect |
| --- | --- |
| palace_01 | Income five power; special action two tools. |
| palace_02 | Special terraform/build with two free spades. |
| palace_03 | Income two power; school-to-guild action with three points and tool. |
| palace_04 | Income two power; free workshop-to-guild action. |
| palace_05 | Income four power; competency on acquisition. |
| palace_06 | Income two power and book; special action two knowledge steps. |
| palace_07 | Income four power; pass three points per school. |
| palace_08 | Income two power, two coins, tool; town threshold six power. |
| palace_09 | Scholar income; scholar-funded flight up to three cells gives five points. |
| palace_10 | Income six coins; acquisition twelve power and two books. |
| palace_11 | Tool income; town tile on acquisition. |
| palace_12 | Income eight power; workshop construction two points. |
| palace_13 | Special action three coins and book; guild construction three points. |
| palace_14 | Income six power; shipping advancement and special water-town connectivity. |
| palace_15 | Income six power; acquisition two spades, two bridges, two books. |
| palace_16 | Income two power and book; free homeland guild placement. |
| palace_17 | Income two power; palace construction ten points. |

palace_16 description calls its guild a “рынок”; its enum comment calls it a guild. Consult live placements rather than inventing a market building type.

## Round bonuses and town tiles

Sources: `app/Domain/GameEngine/PlayerAbilities/Enums/RoundBonus.php`, `app/Domain/GameEngine/Towns/Enums/TownTile.php`.

| Round bonus ID | Effect |
| --- | --- |
| river_workshop | Shipping +1; river-bank workshop construction two points. |
| send_scholar | Scholar income; scholar sent gives two points. |
| build_guild | Income three power; guild construction three points. |
| pass_palace_university | Tool income; pass four points per palace/university. |
| spade | Book income; special one-spade terraform. |
| bridge | Book income; special bridge action. |
| knowledge | Income two tools; special knowledge step. |
| pass_school | Income four coins; pass knowledge steps based on schools. |
| power_coins | Income four power and two coins. |
| coins | Income six coins. |

Town tiles: tools = four points + three tools; terraform = five points + two-spade terraform opportunity; books = five points + two selected books; coins = six points + six coins; knowledge = seven points + step in each discipline; power = eight points + eight power; scholar = eight points + scholar. Check availability and follow-up choices before valuing a reward.
