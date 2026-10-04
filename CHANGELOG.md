# Changelog

All notable changes to Kiption are documented here. Formats follow Keep a Changelog; versions are semver. Entries arrive as `changelog.d/<slug>.md` fragments and are combined into dated sections at release time by `bin/release`; this initial Unreleased section is the one hand-written exception (it predates fragment adoption).

## [Unreleased]

### Added
- `bin/kip db:blank`: fresh-install bootstrap data (ratings ladder, starter tags, one category, five-link nav) without demo content.
- Powered-by footer behind the `powered_by` config switch.
- Full archive feature set through milestone 14 (see README for the tour); eFiction 3.5.5 migration path (exporter, importer, legacy redirects).
