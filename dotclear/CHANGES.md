# Dotclear core changes for modules

What changed in the core API between **2.39**, **2.40** and **master** (2.41-dev), seen from a module.
Sources: the core `CHANGELOG`, the commits, and a diff of `scripts/dotclear-api.php` dumps of the tags 2.39.3, 2.40.1 and master.
Most recent first. See [`README.md`](https://github.com/JcDenis/gh2da/blob/master/dotclear/README.md) for the architecture.

A module follows the Dotclear version required in its `_define.php`: use the new API of that version, nothing newer
(see CONVENTIONS.md, Code).

---

## 2.41-dev (master)

State of `master` on 2026-11-19 (commit `9a31cac`), 2.40 being released on 2026-11-13. No CHANGELOG section yet.

### Breaking

* **`dcCore` and `dcUtils` removed** (`inc/core/class.dc.core.php`, `class.dc.utils.php`, deprecated since 2.28; deleted on upgrade by `GrowUp_2_41_lt`).
  All the legacy aliases go with them, even with `DC_MODERN` off: `dcCore::app()->blog`, `->auth`, `->con`, `->ctx`, `->tpl`, `->media`,
  `->themes`, `->lang`, `->public`, `->wiki`, `->wiki2xhtml`, `->widgets`… Use `App::blog()`, `App::auth()`, `App::con()`,
  `App::frontend()->context()`, `App::frontend()->template()`, `App::media()`, `App::themes()`, `App::lang()`, `App::frontend()`,
  `App::filter()->wiki()`, `Dotclear\Plugin\widgets\Widgets::$widgets`.
* JS: the core no longer uses the jQuery plugin `$.fn.toggleWithLegend` (deprecated, in `admin/js/legacy.js`): use `dotclear.toggleWithLegend(element, target, options)`.

### New

* **Passkey (WebAuthn) instead of the password** to confirm sensitive admin actions (from the user's branch `webauthn-verify`):
  `App::backend()->auth()` gets `canVerify()`, `setVerifyToken()`, `checkVerifyToken()`, `hasVerifyToken()`, `checkPassword(string $pwd)`
  (password **or** valid passkey token), constants `VERIFY_FIELD` (`webauthn_verify`) and `VERIFY_TTL` (300 s);
  `App::backend()->page()->jsWebAuthnVerify(string ...$password_fields)` adds the "Use my passkey" button next to password fields;
  REST function `webAuthnVerification`; `Core\Backend\Auth\WebAuthn::prepareVerify()` / `processVerify()`;
  `Helper\WebAuthn\Data\Store::delChallenge()`, `CHALLENGE_TTL`, `isChallengeExpired()`, `WebAuthn::getAuthenticatedCredential()`.
  A module asking the password again should use `checkPassword()` and `jsWebAuthnVerify()`.
* Uninstaller: new cleaner `Credentials` (table `credential`), from the user's branch `uninstaller-credentials`.

### Refactoring without API change

* `Core\Frontend\Tpl` is split into traits (`TplArchivesTrait`, `TplBlogTrait`, `TplCategoriesTrait`, `TplCommentsTrait`, `TplEntriesTrait`,
  `TplL10nTrait`, `TplLanguagesTrait`, `TplLoopTrait`, `TplPaginationTrait`, `TplPingsTrait`, `TplSystemTrait`): the methods stay on `Tpl`,
  look for them in the trait files.

### Fixes only in master (not in 2.40.1)

* `Http::head()` sends `<protocol> <code> <message>` (was invalid outside CGI: empty JSON responses with `php -S`).
* Custom port kept in redirections; admin URL with trailing slash after a CLI install; user defined mail function exception;
  duplicate ids of optional list columns in user preferences; media item without file (back to the media manager instead of an error 500);
  `Otp` rejects invalid base32 characters.

---

## 2.40.1 (2026-11-17)

Fixes only, no API change. Worth knowing for modules:

* Admin search, hidden fields of selected entries of actions (TypeError), admin notices time (stored in UTC, no more user time zone shift).
* Widgets: empty text fields when the default value is numeric, `homeonly` setting.
* SQLite: `TRUNCATE`, `NULL` default values read as text (broke `Structure::synchronize()`), flat export of numbers.
* Default unselected columns in user preferences, selection of the current item in select lists, OAuth2 redirect URL check on IP/localhost.
* Security: authenticated RCE through zip archives inflated in the media manager (excluded files are now deleted after extraction).

---

## 2.40 (2026-11-13)

PHP 8.2+ (8.3 to 8.5 compliant).

### Breaking

* Widgets: `WidgetsElement::setting($name, $title, $value, $type = 'text', array $options = [])`: the options (radio, combo) are the 5th argument,
  the old trailing `opts` argument (6th) is removed. `WidgetsElement::checkHomeOnly(string $type)`: 2nd and 3rd arguments removed.
* `XmlTag`: `nodes()`, `node()`, `name()` renamed `getNodes()`, `getNode(int $index)`, `getName()` (conflict with `__set()`); `__get()` added.
* `ModuleDefine::updLocked()` and `ModulesInterface::MODULE_FILE_LOCKED` removed (no more locked themes update).
* `App::frontend()->search_count` is an `int`.
* Typos fixed in public names: `Update::ERR_FILES_UNWRITALBE` → `ERR_FILES_UNWRITABLE`, `WebAuthn\Data\Credential::CreateDate()` → `createDate()`,
  simpleMenu `MenuItem` argument `$descripion` → `$description`.
* `XmlRpc\Request` properties `method` and `args` are no longer public.

### New API

* `Cursor` typed accessors: `strField()`, `intField()`, `boolField()`, `floatField()` and `setStrField()`, `setIntField()`, `setBoolField()`, `setFloatField()`.
* `SqlStatement::between($field, $min, $max)` and `caseWhen(array $when, ?string $else, ?string $as)`.
* `Core\Content` (`ContentInterface`: `getFormat()`, `getContent()`, `setContent()`) for the new behavior `coreContentFilterV2`.
* `PostType` getters: `getType()`, `getAdminUrl()`, `getListAdminUrl()`, `getPublicUrl()`, `getLabel()`, `getIcon()`, `getIconDark()`.
* `App::config()->tplsets()`: available template sets. Template value `{{tpl:BlogLanguageDirection}}` (`dir=""` of `<html>`).
* Backend: `App::backend()->isPopup()` / `setPopup()`, `Process\Backend\MediaItem::isPopup()`, `Notices::getNoticesCount()`,
  `Page::open()` / `openPopup()` last argument `int $notices` (number of notices shown in the page title).
* Form helpers: `Aside`, `Dialog`, `Nav`, `Datalist`; attributes `command`/`commandfor` (`Btn`), `target`/`rel` (`Link`),
  `aria-current`, `aria-describedby`, `aria-details`, `aria-disabled`, `aria-hidden`, `aria-label`, `aria-labelledby`;
  `Input` 3rd argument `bool $renderLabel`; `Option` accepts `?string $text` and `string|int $value`.
* Widgets: `getTitle()`/`setTitle()`, `getHomeOnly()`/`setHomeOnly()`, `getContentOnly()`/`setContentOnly()`, `getClass()`/`setClass()`,
  `getOffline()`/`setOffline()`, `getNotOnArchive()`/`setNotOnArchive()`, `addNotOnArchive()`, `checkNotOnArchive()`.
* simpleMenu `MenuItemEdit` (for `adminSimpleMenuBeforeEditV2`), maintenance `MaintenanceTask::check()`,
  antispam `SpamFilters::STATUS_HAM` / `STATUS_SPAM`, themeEditor `getParent()`.
* Template engine: constructor argument for the debug mode.
* Translations: cache of loaded translation files.

### New behaviors (old ones deprecated)

| Use | Instead of | Argument |
|---|---|---|
| `coreContentFilterV2` | `coreContentFilter` | `string $context, Content[] $contents` |
| `adminPostsSortbyComboV2`, `adminCommentsSortbyComboV2`, `adminBlogsSortbyComboV2`, `adminUsersSortbyComboV2` | same without V2 | `ArrayObject<string, string>` |
| `adminSimpleMenuBeforeEditV2` | `adminSimpleMenuBeforeEdit` | `MenuItemEdit` |
| `adminPageNotificationErrorV2` | `adminPageNotificationError` | `Error` |

### Deprecated

* User table `user_options` field: use user preferences.
* `App::backend()->popup`: use `App::backend()->isPopup()`.
* The behaviors of the table above.

### Security (behavior of the core)

* Trackbacks/pingbacks filter local IPs; zip content checked before extraction; flat export no longer uses a public temporary folder;
  some maintenance tasks restricted to super admins; media extensions checked case insensitively; recovery keys without `md5()`/`uniqid()`.

---

## 2.39 (2026-08-13, last fix 2.39.3 on 2026-08-25)

PHP 8.2+. Base of the API required by CONVENTIONS.md (`App::`, `MyPlugin`, `TraitProcess`, Form components).

### Breaking

* `Structure::table()` registers a table only if it is not already registered (and returns it); `$structure->name` is deprecated.
* Legacy form functions (`form::`) no longer accept an associative array of options; the `form` class is deprecated (use `Helper/Html/Form`).
* `__top_behaviors` and `__shutdown` globals ignored.
* `Filters::userOptions()` deprecated and returns `null`; the `UserPrefFilter` class is mandatory for user preferences filters (sort by, order, nb).
* `UrlHandler->type` no longer public: `getType()`; new `isType(string|array $type)`.
* JS: Codemirror instances in `dotclear.codemirror`; `metaEditor` → `dotclear.MetaEditor`; `jsToolBar` & co → `dotclear.ToolBar`…
* `Record->index()` returns an `int` (no more `null`).

### New API

* `DC_MODERN` constant (`inc/config.php`, `App::config()->modern()`): no legacy code loaded when true.
* `MetaRecord::strField()`, `intField()`, `boolField()`; workspaces `getStr()`, `getInt()`, `getBool()`, `getFloat()` (blog settings and user preferences).
* Contexts `PLUGIN_CONFIG` and `THEME_CONFIG`; `Listing::count()`, `rs_count` is an `int`; `SqlStatement::sanitizeIn()` (spelled "satinizeIn" in the CHANGELOG).
* Menus and icons: `MenuItem`, `Icon` (`My::icon()`), favorites `Favorite::menuIcon()` / `dashboardIcon()`.
* Notices: `Core\Backend\Notice` class, `Notices::addNewNotice(Notice $notice)`, behavior `adminPageNotificationV2`.
* Filters: `UserPrefFilter`, behavior `adminFiltersListsV3`.
* `jsLoad()` gets a `defer` argument; popover strings (`hint`, `auto`, `manual`).
* Template set `glaz` (modules with templates should provide theirs); stores read from `release.json`.
* `UserPreferences::createFromUser()` throws on an empty user id.

### Deprecated

* `Notices::addNotice()` → `addNewNotice()`; `Favorite::smallIcon()`/`largeIcon()` → `menuIcon()`/`dashboardIcon()`;
  `Auth::getOption()`/`getOptions()` (options migrated to user preferences, the tags plugin too);
  `ConnectionInterface::escape()` → `escapeStr()`; `$GLOBALS['_l10n']`; global JS `metaEditor` and `jsToolBar`;
  behaviors `adminPageNotification` (→ V2) and `adminFiltersListsV2` (→ V3).

### Fixes in 2.39.1 to 2.39.3

* Widgets (feed reader titles, active category, subtitles, error 500 without widgets), install button of the stores, empty entry URL not recomposed,
  sub-categories setting, media legend, missing `HTTP_USER_AGENT`, HTTP requests with port 0.
