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

Agenti používají skills, MCP a pluginy projektu (TDD, debugging, IDE/DB nástroje) a čtou `CLAUDE.md`; použité skills ukáže `/orch:stats`. Tým roste s obtížností: jednoduchý = 1 worker + 1 reviewer, běžný = + planner, těžký = až 2 plannery, 4 workeři a 3 reviewři (různé pohledy). Stropy: `"team": {"planners": 2, "workers": 4, "reviewers": 3}` v `.claude/orch.json`.

Agenti necommitují — po doběhnutí si změny projdi (`git diff`) a commitni sám.

**Přehled a údržba:**
| příkaz | co dělá |
|---|---|
| `/orch:stats [dny]` | tento projekt: tokeny a odhad USD podle agenta a modelu, **nejdražší tahy**, běhy (s modely), verdikty review, soubory, pojistka (výchozí 7 dní) |
| `/orch:global [dny]` | celý počítač: kolik místa zabírá `~/.claude` (přepisy, pluginy, historie souborů) a tokeny + USD podle projektu, agenta a modelu, nejdražší tahy přes všechny projekty |
| `/orch:prune [dny]` | uvolní místo: přepisy sezení nepoužité N dní (výchozí 30, min 7) přesune do `~/.claude/orch-trash` — nejdřív náhled; `/orch:prune --restore-last` vrátí poslední dávku. Pojmenovaná sezení a sezení úloh se nikdy nepřesouvají, krátká nepojmenovaná (1-2 prompty) jdou pryč po 1 dni. Automaticky 1× denně při startu pro nepojmenovaná (`prune_days`, výchozí 30, 0 = vypnuto). Vlastní úklid Claude Code maže i pojmenovaná sezení — zvyš `cleanupPeriodDays` (např. 3650) |
| `/orch:budget` | kolik projekt stál dnes a tento týden proti limitu a jak limit nastavit |
| `/orch:cleanup` | ukáže dočasné soubory po agentech (`tmp_*`, `debug_*`, `*.bak` …) a po potvrzení je přesune do koše |
| `/orch:restore [soubor]` | bez argumentu vypíše koš, se souborem ho vrátí zpět |
| `/orch:notify-test` | pošle zkušební notifikaci na Discord / WhatsApp |
| `/orch:version` (`/orch:v`) | jakou verzi orch běží toto sezení, jaká je nainstalovaná a nejnovější na GitHubu, a co udělat, když se liší |
| `/orch:help [příkaz]` | tahle nápověda |

**Běží samo (hooky, 0 tokenů):**
- Upozornění na aktualizaci — když je nainstalovaná novější verze orch, než jakou načetlo otevřené sezení, první zpráva v sezení jednou připomene `/reload-plugins`.
- Pojistka — zablokuje `rm -rf /`, `git push --force`, `git reset --hard`, `DROP TABLE`, `DELETE` bez `WHERE`, `curl … | sh`. Výjimky: `guard.allow` v `.claude/orch.json`.
- Úklid na konci sezení — dočasné soubory, které nejsou v gitu, jdou do `.claude/orch/trash/` (maže se po 7 dnech).
- Testy po odpovědi — jen když je v `.claude/orch.json` nastaven `testCommand` a máš neuložené změny.
- Notifikace — po doběhnutí `/orch:*`, dlouhém tahu nebo když Claude čeká na povolení.
- Nápověda ke sezením — při startu připomene pojmenované sezení projektu (`claude --resume <id>`); vypne `"sessionHint": false` v `.claude/orch.json`.
- Auto-prune — 1× denně při startu přesune nepoužitá nepojmenovaná sezení (`prune_days`, výchozí 30, 0 = vypnuto) do `~/.claude/orch-trash`; pojmenovaná, úlohová a běžící sezení se nikdy nemažou; paměť projektu (`memory/`) se přesune jen když ve složce projektu nezůstane žádné sezení.
- Rozpočet — když projekt přesáhne denní/týdenní limit v USD, pošle jednu zprávu za den/týden a ukáže varování u dalšího promptu.

**Nastavení:**
- Aktualizace: `claude plugin marketplace update claude-orch` a `claude plugin update orch@claude-orch`; v už otevřených sezeních pak `/reload-plugins`. Verzi ověříš přes `/orch:version`.
- Notifikace a rozpočet pro všechny projekty: `/plugin` → `orch` → Configure (Discord webhook URL, WhatsApp číslo + apikey, `budget_day_usd`, `budget_week_usd`).
- Projekt: `.claude/orch.json` (`testCommand`, `notify`, `guard`, `models`, `team`, `budget`, `trashDays`, `prices`).
- `.claude/` patří do `.gitignore`, pokud ho nechceš sdílet s týmem.
