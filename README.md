# README

> Update DotAddict (DA) repository contributions from github repository

These workflows allow you to update your Dotclear modules (plugins or themes)
from your github modules repositories to your Dotaddict repository account.

## GENERAL

* You must have a valid DA console account
* These workflows must be in same user repository as the ones to update
* Modules repository must have same name as module
* Modules releases must have their versions as tag prefixed with "v"
* Modules releases must have attach zip file named type-module.zip

## SECRETS

These workflows required following secrets:

* DA_USERNAME : DA user console login
* DA_PASSWORD : DA user console password
* TELEGRAM_ID : Telegram chat id
* TELEGRAM_TOKEN : Telegram bot token
* USER_TOKEN : Github user token with rights to access all modules repositories

## WORKFLOWS

Workflow is chunk in 3 parts:

* dalaunch.yml : Scheduled run updates
* daread.yml : Get user DA contributions and launch related update
* daupdate.yml : Check and update DA contribution

dalaunch.yml starts every day and launch daread.yml for plugins and for themes, daread.yml launch daupdate.yml for each DA modules found.

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
  or the given version. `_define.php` and `CHANGELOG.md` (commits since last release) are updated and committed as "Release x".
* **release** on a commit "Release x" pushed on default branch (or after release button) : checks and tests must pass,
  commit version must match `_define.php` version, then `_define.php` and `dcstore.xml` are updated
  and a release is created with the module zip.
* **branches cleanup** on push to default branch : branches merged into default branch are deleted.
* **Telegram messages** : release published or failed, release preparation failed, branches deleted.

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
