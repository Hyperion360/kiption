# Skins

A skin is a directory of view overrides. Create `app/skins/<name>/views/`,
put template files in it, and every file whose name matches one under the
app's own views wins; every name the skin does not carry falls through to
the app default. One switch (`skin` in config.php or the `/settings` board)
changes the site's look without forking the application.

Two skins ship: `classic` (compact type scale, denser list rhythm, its own
palette) and `manuscript` (serif-led, wide-measure reading). Both are
server-rendered templates: everything works with JavaScript disabled, and
no skin adds a script.

## Resolution order

For every template render and every layout, the framework's View checks in
this order and uses the first file that exists:

1. `app/skins/<skin>/views/<template>.php` (the skin)
2. `app/views/<template>.php` (the app default)
3. `app/Features/<Feature>/views/<template>.php` (feature views, when the
   template's first segment names a feature, e.g. `browse/index` resolves
   at `app/Features/Browse/views/index.php`)

Layouts resolve the same way, override-first: a skin's `layout.php` wraps
every page, including pages whose view template the skin does not override.
A miss anywhere falls through unchanged, so a skin may override exactly one
file if that is all it needs.

The seam is wired in both entrypoints after the settings overlay:

- `public/index.php`: `$config['views_override'] = \App\Skins::overrideDir($config);`
- `bin/kip`: the same line, so `pages:build` pre-renders skinned pages
  (`Builder::build()` constructs its own App from `$config` and the key
  propagates).

`App\Skins::overrideDir` returns the skin's views directory, or an empty
string (no override at all) when the skin is `default`, when the name is
malformed, or when `app/skins/<name>/views` does not exist. A skin name may
only contain `[a-z0-9-]`, enforced at the settings board and re-checked at
the seam, and the directory must resolve (realpath) under `app/skins`; a
deleted or misspelled skin therefore renders the app defaults, never an
error page.

## Choosing and switching

- Browser: `/settings`, the Skin select, Save. The select lists `default`
  plus every installed skin (a directory under `app/skins` that contains
  `views/`).
- Config file: `'skin' => 'classic'` in config.php.

A skin switch is a write to the `settings` table, and every board save
purges the whole static layer (the flag-board purge set), because a cached
page is a skinned page: pages cached under the old skin must not survive
the switch. The layer then refills on first visits, or immediately with
`php bin/kip pages:build`.

First run and config-file changes: editing config.php triggers no board
write and therefore no purge. On a fresh install (or after any config-file
skin change), run `php bin/kip pages:build` before taking traffic, or the
static layer serves default-skin pages until each one is visited and
re-cached.

## What is unskinned by construction

Two surfaces render before the skin seam exists, by design:

- The maintenance 503 page. MaintenanceGuard answers before the app boots.
- Static-cache HITs. The cached file is sent before PHP builds the App.
  (The cache layer itself purges on every board save, and entering
  maintenance purges it too, so neither surface serves stale skin choices;
  they just never wear one.)

## Where the stylesheet lives

`app/` is outside the web root; only `public/` is served. A skin's
templates live in `app/skins/<name>/views/` and its stylesheet lives at
`public/assets/skins/<name>.css`, linked by the skin's own `layout.php`
AFTER `/assets/reader.css`:

    <link rel="stylesheet" href="/assets/reader.css">
    <link rel="stylesheet" href="/assets/skins/classic.css">

Loading after the base sheet lets the skin override the custom-property
palette (`--bg`, `--ink`, `--accent`, ...) with the same selectors
reader.css uses, so the per-user theme slots (paper/sepia/night/auto) keep
working under the skin. Set reading-posture defaults (`--read-width`,
`--read-size`, `--read-lh`) on `:root`, never on a body class: the member
preference selectors (`html[data-width=...]` and friends) must keep winning
over the skin default.

## Safe-to-override views

Any view is overridable; not every view is safe to rewrite freely. Three
groups carry contracts that tests and the enhancement layer pin:

- `story/_chapter.php`: the `.chapter-unit` wrapper, the `h-entry` article
  with its `data-p-start`/`data-p-end`/`data-next-url` attributes, the
  visually-hidden byline/dates header, `.prose.e-content`, `p-name`, the
  read beacon `<img>`, and the `unit-state` `<template>` must survive
  byte-compatible, or infinite scroll, reading progress, and microformat
  consumers break. The manuscript skin's override shows the pattern:
  restyle the opening, keep the machine-read shape.
- `story/read.php`: the `data-js-module`/`data-*` wiring on `.reader` is
  the keyboard and infinite modules' contract (AppJsContractTest).
- `browse/_story_cards.php`: the `?fragment=1` endpoint renders it bare,
  so page and appended fragments must stay byte-identical. If a skin
  overrides it, the fragment inherits the override (same resolution
  order), which is the correct outcome; do not fork the card markup
  between the two.

Layouts and ordinary page views (`browse/index`, `browse/recent`,
`story/view`, `home/index`, `series/*`, `news/*`, `page/*`) carry no such
contracts: copy, edit, and the worst case is your own markup.

Two rules from the app's hard constraints apply to skins verbatim: no
inline `<script>` and no JavaScript dependency for anything the skin's
pages show, and never let a personalized or error response land in a cache
file (the cache layer enforces this itself; a skin cannot weaken it).

## A worked minimal example

Ship a skin that only recolors the site, overriding nothing but the
layout:

1. `mkdir -p app/skins/nightwriter/views public/assets/skins`
2. `cp app/views/layout.php app/skins/nightwriter/views/layout.php`, then
   edit the copy: add the stylesheet line after reader.css and a body hook
   class:

       <link rel="stylesheet" href="/assets/reader.css">
       <link rel="stylesheet" href="/assets/skins/nightwriter.css">
       <body class="skin-nightwriter">

3. Write `public/assets/skins/nightwriter.css`:

       :root{
         --bg:#101014; --ink:#d8d8de; --ink-2:#a7a7b0; --muted:#8b8b96;
         --line:#24242c; --line-strong:#36363f; --hover:#191920;
         --accent:#9db8e8; --accent-hover:#aec9f2; --on-accent:#101014;
         --visited:#b9a4cf; --track:#24242c; --surface:#17171d;
         --scrim:rgba(0,0,0,.55); --pill-bg:#1d2430; --cover-ink:#f1efe8;
         --danger:#f0856d; --danger-bg:#2e1d19; --danger-line:#5a3027;
         color-scheme:dark;
       }
       body.skin-nightwriter .page-head h1 { letter-spacing:.02em; }

4. Pick it: `/settings`, select `nightwriter`, Save (the static layer
   purges itself), or set `'skin' => 'nightwriter'` in config.php and run
   `php bin/kip pages:build`.

The new skin appears in the board's select automatically: discovery is the
directory tree, there is no registry to update.

## Limits (v1)

Skins are operator-level and site-wide. Per-user or per-member skin choice
is a follow-up, not a config away; the `skin` setting has no user scope.
The two shipped skins override layout plus two or three views each and
inherit every other view from the app defaults; treat them as the working
examples for your own.
