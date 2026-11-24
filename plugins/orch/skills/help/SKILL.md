---
name: help
description: List all orch commands and what to use when.
argument-hint: "[command name]"
disable-model-invocation: true
---
Argument: "$ARGUMENTS"

Reply in the user's language. Do not run any tools.
- Empty argument: show the overview below as-is (translate if needed).
- A command name (e.g. `run`, `/orch:bugfix`): show only that command's section from the overview plus its "When" line, max 6 lines.

## orch — příkazy

**Začni tady (jednou na projekt):**
- `/orch:init` — vytvoří `.claude/orch.json` (zjistí příkaz na testy) a zakáže agentům číst `vendor/`, `node_modules/`, lock soubory. Nejdřív ukáže náhled.

**Práce na kódu (agenti):**
| příkaz | kdy použít | příklad |
|---|---|---|
| `/orch:run <úkol>` | běžná feature / změna | `/orch:run přidej export do CSV na stránce objednávek` |
| `/orch:bugfix <chyba>` | máš chybovou hlášku, padající test nebo popis bugu | `/orch:bugfix TypeError v OrderService::total() při prázdném košíku` |
| `/orch:backend <úkol>` | změna s databází / dotazy / výkonem — navíc kontrola N+1, indexů, cache | `/orch:backend endpoint se seznamem faktur s filtrem podle data` |
| `/orch:parallel <úkol>` | velký úkol, který jde rozdělit na nezávislé části; **vyžaduje čistý git strom** (commitni předtím) | `/orch:parallel přidej API pro produkty, kategorie a štítky` |

Model se vybírá podle obtížnosti úkolu: jednoduchý → haiku, běžný → sonnet, těžký (návrh přes víc modulů, bezpečnost, migrace, nejasná příčina) → planner na opus. Když review najde kritické chyby, opravu dělá model o stupeň silnější. Natvrdo to nastavíš v `.claude/orch.json`: `"models": {"worker": "sonnet"}`.

Agenti necommitují — po doběhnutí si změny projdi (`git diff`) a commitni sám.

**Přehled a údržba:**
| příkaz | co dělá |
|---|---|
| `/orch:stats [dny]` | tento projekt: tokeny a odhad USD podle agenta a modelu, **nejdražší tahy**, běhy (s modely), verdikty review, soubory, pojistka (výchozí 7 dní) |
| `/orch:global [dny]` | celý počítač: kolik místa zabírá `~/.claude` (přepisy, pluginy, historie souborů) a tokeny + USD podle projektu, agenta a modelu, nejdražší tahy přes všechny projekty |
| `/orch:prune [dny]` | uvolní místo: přepisy sezení nepoužité N dní (výchozí 30, min 7) přesune do `~/.claude/orch-trash` — nejdřív náhled; `/orch:prune --restore-last` vrátí poslední dávku |
| `/orch:budget` | kolik projekt stál dnes a tento týden proti limitu a jak limit nastavit |
| `/orch:cleanup` | ukáže dočasné soubory po agentech (`tmp_*`, `debug_*`, `*.bak` …) a po potvrzení je přesune do koše |
| `/orch:restore [soubor]` | bez argumentu vypíše koš, se souborem ho vrátí zpět |
| `/orch:notify-test` | pošle zkušební notifikaci na Discord / WhatsApp |
| `/orch:help [příkaz]` | tahle nápověda |

**Běží samo (hooky, 0 tokenů):**
- Pojistka — zablokuje `rm -rf /`, `git push --force`, `git reset --hard`, `DROP TABLE`, `DELETE` bez `WHERE`, `curl … | sh`. Výjimky: `guard.allow` v `.claude/orch.json`.
- Úklid na konci sezení — dočasné soubory, které nejsou v gitu, jdou do `.claude/orch/trash/` (maže se po 7 dnech).
- Testy po odpovědi — jen když je v `.claude/orch.json` nastaven `testCommand` a máš neuložené změny.
- Notifikace — po doběhnutí `/orch:*`, dlouhém tahu nebo když Claude čeká na povolení.
- Rozpočet — když projekt přesáhne denní/týdenní limit v USD, pošle jednu zprávu za den/týden a ukáže varování u dalšího promptu.

**Nastavení:**
- Notifikace a rozpočet pro všechny projekty: `/plugin` → `orch` → Configure (Discord webhook URL, WhatsApp číslo + apikey, `budget_day_usd`, `budget_week_usd`).
- Projekt: `.claude/orch.json` (`testCommand`, `notify`, `guard`, `models`, `budget`, `trashDays`, `prices`).
- `.claude/` patří do `.gitignore`, pokud ho nechceš sdílet s týmem.
