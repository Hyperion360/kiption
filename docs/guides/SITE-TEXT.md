# Site text and languages

Archive owners can change any wording the archive shows and add new
languages. Open **Site text** in the admin menu.

## Edit a string

1. Pick a group (for example Navigation or Settings) or use the search box.
2. Change the text and save.

The new wording is used straight away. To go back, clear the box and save;
the built-in text returns.

Some strings contain placeholders such as `{name}`. Keep them exactly as
they are: a missing or extra placeholder is rejected. Text is plain: the
less-than sign, control characters and line-break tricks are rejected, so
no markup can be added.

## Add a language

On the Languages page, enter a two or three letter code (for example `fr`)
and a name. Translate it in the editor. Strings you have not translated
fall back to the default language.

## Export and import

Export downloads one language as a JSON file of key and text pairs. Import
takes a file in the same shape. The file must be under 1 MB with at most
5000 entries. Nothing is saved unless every entry passes the safety checks.

## Default language

Site settings has a Default language choice. Visitors who pick their own
language keep it. You cannot remove the language that is the current default.

## Command line

`bin/kip text:compile` rebuilds the language files from the saved text. The
archive does this on its own the first time it runs, so you only need it
after copying a database between servers.
