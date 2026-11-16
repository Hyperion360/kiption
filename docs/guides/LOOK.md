# Colors and Layout

Archive owners can change how the archive looks without touching a file.
Open **Colors and Layout** in the admin menu.

## Change Your Colors

Three colors drive the whole site:

- **Accent** colors links and buttons.
- **Background** is the page behind the text.
- **Text** is the story and page text.

Pick each one with the color box, or type a code such as `#1f4f42`. The
night palette is worked out from your three colors, so you choose one set
and both themes follow.

## Pick a Layout

Four choices shape the page. Each one is a short list, so nothing can
break the layout:

- **Reading width:** narrow, standard, or wide.
- **Text size:** small, standard, or large.
- **Density:** compact, standard, or roomy. This changes line height and the
  space around the page, not every small gap.
- **Header style:** standard, plain (no line under it), or tinted.

## Preview and Save

Two sample panes, Light and Night, show your choices. With scripting on they
update as you change a control. With scripting off, press Preview to see
them. Nothing changes on the site until you press Save, and visitors see the
new look right away.

## Reset

Reset to Default asks first, then removes your choices and returns the
built-in colors and layout.

## What Members See

Members can still pick paper, sepia, or night for themselves, and their own
reading width and size. Their choices win over yours. Sepia keeps its own
colors, so a member on sepia does not see your accent.

## Why Some Colors Are Refused

Text must be easy to read against its background. The archive measures the
contrast between two colors and refuses a save when text and background
are closer than 4.5 to 1, or when the accent and background are closer than
5 to 1. The message shows the number it found. Pick a darker text color or
a lighter background, and try again. Nothing is saved while a color is
refused.

## What You Cannot Do

You cannot add your own CSS or templates on this page. Custom code can break
the site or open it to attack, so the page offers only checked choices. If
you have file access to the server, a skin (see SKINS.md) is the place for
deeper changes.

## Command Line

The saved choices live in the database, and the archive builds a small
stylesheet from them in `app/site-look/`. The archive rebuilds that file on
its own if it goes missing. `bin/kip look:compile` rebuilds it on demand,
which helps after copying a database between servers.
