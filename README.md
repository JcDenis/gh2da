# README

> Update Dotclear repository contributions from github repository

These workflows allow you to update your Dotclear modules (plugins or themes)
from your github modules repositories to your account on the Dotclear repository
(https://dotclear.org/, or https://repo.dotclear.watch/ as test and fallback repository).

## GENERAL

* You must have a valid Dotclear repository account
* These workflows must be in same user repository as the ones to update
* Modules repository must have same name as module
* Modules releases must have their versions as tag prefixed with "v"
* Modules releases must have attach zip file named type-module.zip

## SECRETS

These workflows required following secrets:

* DA_USERNAME : Dotclear repository user login
* DA_PASSWORD : Dotclear repository user password
* TELEGRAM_ID : Telegram chat id
* TELEGRAM_TOKEN : Telegram bot token
* USER_TOKEN : Github user token with rights to access all modules repositories

## WORKFLOWS

Workflow is chunk in 3 parts:

* dalaunch.yml : Scheduled run updates
* daread.yml : Get user contributions on the Dotclear repository and launch related update
* daupdate.yml : Check and update a contribution on the Dotclear repository

dalaunch.yml starts every day and launch daread.yml for plugins and for themes, daread.yml launch daupdate.yml for each module found on the repository.
updatedc.yml (dotclear.org) and updatewatch.yml (repo.dotclear.watch) start dalaunch.yml every day for each repository.

New modules are added automatically from `categories.txt`: each line gives a module and one or more words
(`<repository> <word> [<word>...]`). When a module of this list is not yet on the repository and has a release zip of
the type read, daread.yml searches its words, accents and case aside, in the titles of the categories of the target
repository (API endpoint `categories`) and launches daupdate.yml with the version `0` and the first category found.
Category titles differ from one repository to another, so give a word for each one (ex: `structure design`: Structure on
dotclear.org, Design… on repo.dotclear.watch). A module without category found is not submitted and is listed in the
summary of the run; `-` instead of words, or no line, means the module is never submitted as new.

The input `new_modules` of dalaunch.yml (or daread.yml) adds modules by hand: a list of `module_id:category_id`
separated by commas, the category ID being the one of the target repository; it takes precedence over `categories.txt`.
The input `dry_run` only lists in the summary of the run the new modules that would be submitted, with their category,
without submitting them (existing modules are still checked and updated).

The repository user needs the permission to submit new modules by API (or to be super admin), and a new module is
always reviewed by the repository administrators. Once on the repository, a module keeps its category: updates send no
category, the category of its current version is kept, even when changed by an administrator.

Only modules of public repositories are sent to the Dotclear repository: daread.yml and daupdate.yml both skip
a module whose repository is private (or can not be read), with a line in the run summary. A private module still gets
its checks, tests, release and zip on GitHub from its own workflow.

daupdate.yml also sends, when found:

* the license, recognized from the `LICENSE` file of the zip (GNU GPL, LGPL, AGPL, MIT, Apache) and supported by the repository;
* the tags, from the GitHub topics of the module (except `dotclear`, `plugin`, `theme`, `dotclear-plugin`, `dotclear-theme`)
  that match a tag of the repository, accents aside (topic `developpement` for tag `développement`).

Topics of the modules are listed in `topics.txt` and applied by the workflow `dotclear-settings.yml`.
A new module needs a line in `topics.txt` and in `categories.txt`. The workflow `dotclear-new-modules.yml` (every day)
finds the modules repositories (public, not archived, with `.github/workflows/dotclear.yml`) missing from these files and
opens a pull request (branch `new-modules`) that adds them: current topics of the repository, and category words guessed
from them (`-` when none, and always for a theme). Check and merge it: the module is then submitted as new by the next
run of dalaunch.yml. A Telegram message tells when the pull request changes.

## DOTCLEAR MODULE WORKFLOW

Reusable workflow `.github/workflows/dotclear-module.yml` does for each Dotclear module (plugin or theme):

* **checks** on push, pull request and weekly (monday) : PHP lint and PHPStan (level max with deprecation rules)
  against the latest release of the Dotclear version required in `_define.php` (Dotclear master if not yet released).
  Required plugins and plugins used in code are taken from same owner repositories, then from franck-paul ones.
* **tests** after checks : a Dotclear site is started with the [docker-dotclear](https://github.com/JcDenis/docker-dotclear) image
  (`latest`, or `dev` when the module or one of its dependencies needs a not yet released Dotclear), the module and its dependencies are installed,
  then admin pages (dashboard, settings, posts, media, widgets, module page and configuration...) and public pages
  (home, post, archive, search, feeds, 404) are browsed. A page fails on HTTP status >= 500 or PHP error,
  and PHP errors of server logs from module files fail the test (others are only warnings).
  Extra pages can be listed in module file `.github/dotclear-test.txt`, one per line,
  like `admin:index.php?process=Plugin&p=myModule&part=list` or `public:my-url/page`.
  Summary is shown in the run page. Scripts are in `scripts/`.
* **release button** : Actions > Dotclear module > Run workflow > check "Prepare and publish a release".
  Next version is today for date versions (2026.11.05), else increment after first dot (2.4.1 -> 2.5),
  or the given version. Versions are 1 to 3 numbers separated by dots (Dotclear repository), so one release a day
  for date versions. `_define.php` (version and date), `dcstore.xml` and `CHANGELOG.md` (commits since last release, except those starting with `[cosmetic]` or `[ci]`) are updated
  and committed in a single commit "Release x": the release adds no other commit.
* **release** on a commit "Release x" pushed on default branch (or after release button) : checks and tests must pass,
  commit version must match `_define.php` version, then, for a commit pushed by hand, `_define.php` (date) and `dcstore.xml`
  are updated in a single commit "[ci] Update _define.php and dcstore.xml for release",
  and a release is created with the module zip (without the `docs/` folder and the `CLAUDE.md` and `CONCEPTION.md` files of the module, kept for the repository only).
  The release message gives the module description, its changes (entry of `CHANGELOG.md`), requirements (`_define.php`),
  how to install it, and links to the README, the French user guide (`docs/fr/utilisation.md`) if any, issues and full changelog.
* **branches cleanup** on push to default branch : branches merged into default branch are deleted.
* **Telegram messages** : release published or failed, release preparation failed, branches deleted.

**Local test in one command**, without Docker: `scripts/dotclear-local.php` runs the same checks and tests from a module directory
(PHP lint, PHPStan, fresh Dotclear on SQLite served by the PHP built-in server, pages browsed with `dotclear-test.php`,
module enabled, PHP errors of server log). It only needs PHP (with curl, pdo_sqlite, mbstring, intl) and git,
everything else (Dotclear, dependencies, PHPStan) comes from GitHub and is cached in `~/.cache/gh2da` (or `$GH2DA_CACHE`).

    cd myModule && php ../gh2da/scripts/dotclear-local.php
    php gh2da/scripts/dotclear-local.php myModule --ref=master      # all steps on Dotclear master
    php gh2da/scripts/dotclear-local.php myModule --skip=phpstan --serve --port=8080   # then keep the site running

Like CI, PHPStan runs on the Dotclear version required in `_define.php` and pages on the latest stable release
(master if required). Admin login: `admin` / `Dotclear-Local-2026`. Options: `php scripts/dotclear-local.php --help`.

Each module only needs the small caller workflow `templates/dotclear.yml` copied to `.github/workflows/dotclear.yml`
and secrets BOT_GPG_KEY, BOT_GPG_PASS, TELEGRAM_ID, TELEGRAM_TOKEN.

Workflow `.github/workflows/dotclear-digest.yml` sends every monday a Telegram summary of all modules
(weekly checks results and releases of the week).

Workflow `.github/workflows/dotclear-settings.yml` applies same settings to all modules repositories
(topics, delete branch on merge, no projects, no empty wiki), and `.github/workflows/dotclear-community.yml`
publishes community health files of `templates/community/` (issue and pull request templates, contributing, security)
to the owner `.github` repository. Both need secret USER_TOKEN.

Dependabot keeps actions of these workflows up to date. Workflow `.github/workflows/dependabot-automerge.yml`
merges Dependabot patch and minor updates once the other checks of the pull request succeeded,
major updates are kept open and listed in the weekly digest. Other repositories use it with the caller
`templates/dependabot-automerge.yml` copied to `.github/workflows/dependabot-automerge.yml`.

## CONVENTIONS

[`CONVENTIONS.md`](https://github.com/JcDenis/gh2da/blob/master/CONVENTIONS.md) holds the shared conventions of the modules
(code, administration, translations, tests, commits, releases, documentation), for humans and AI assistants.
Each module points to it from its own short `CLAUDE.md` (model in `templates/CLAUDE.md`).
Other authors can use it as a base.

## DOTCLEAR CORE REFERENCE

[`dotclear/`](https://github.com/JcDenis/gh2da/blob/master/dotclear/README.md) holds a reference of the Dotclear core
for modules authors and AI assistants: architecture, module anatomy and main APIs (`README.md`), API changes between
versions (`CHANGES.md`, from 2.39 to the development version) and the list of behaviors (`BEHAVIORS.md`).
`scripts/dotclear-api.php` (public API dump of a Dotclear tree, to diff two versions) and `scripts/dotclear-behaviors.php`
(regenerates `BEHAVIORS.md`) keep it up to date.

`dotclear/rules.json` lists, for tools that check modules, what breaks or is deprecated from Dotclear 2.39
to the next Dotclear version (`master`), version by version (see [`RULES.md`](https://github.com/JcDenis/gh2da/blob/master/dotclear/RULES.md)).
The workflow `dotclear-rules.yml` regenerates it every day with `scripts/dotclear-rules.php`, plus the manual rules of
`dotclear/rules-manual.json`, and commits it when the rules change. `scripts/dotclear-scan.php` checks modules against these
rules (class `scripts/DotclearRulesScanner.php`, standalone).

## LICENSE

Copyright Jean-Christian Paul Denis

AGPL-v3 <https://www.gnu.org/licenses/agpl-3.0.html>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along with this program.  If not, see <https://www.gnu.org/licenses/>.

## CONTRIBUTORS

* Jean-Christian Denis (author)
* Claude (AI assistant by Anthropic)

You are welcome to contribute to this code.
