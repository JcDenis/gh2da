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

## LICENSE

Copyright Jean-Christian Paul Denis

AGPL-v3 <https://www.gnu.org/licenses/agpl-3.0.html>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along with this program.  If not, see <https://www.gnu.org/licenses/>.

## CONTRIBUTORS

* Jean-Christian Denis (author)

You are welcome to contribute to this code.
