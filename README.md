Update DA repository contributions from github repository

* These workflows must be in same user repository as the ones to update
* Modules repository must have same name as module
* Modules releases must have their versions as tag
* Modules releases must have attach zip file named type-module.zip

These workflows required following secrets:

* DA_USERNAME : DA user console login
* DA_PASSWORD : DA user console password
* TELEGRAM_ID : Telegram chat id
* TELEGRAM_TOKEN : Telegram bot token
* USER_TOKEN : Github user token with rights to access all modules repositories

Workflows:

* dalaunch.yml : Sheduled run updates
* daread.yml : Get user DA contributions and launch related update
* daupdate.yml : Check and update DA contribution

