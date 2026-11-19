# Dotclear core reference

Reference of the Dotclear core for AI assistants (and humans) working on Dotclear modules,
written from the core sources of the tags **2.39.3**, **2.40.1** and of **master** (2.41-dev, commit `9a31cac`, 2026-11-19).

It complements [`CONVENTIONS.md`](https://github.com/JcDenis/gh2da/blob/master/CONVENTIONS.md) (how modules are written)
with how the core works and what changed between versions. Read online, like CONVENTIONS.md (`curl -s https://raw.githubusercontent.com/JcDenis/gh2da/master/dotclear/README.md`):

* this file: architecture, module anatomy, main APIs, pitfalls;
* [`CHANGES.md`](https://github.com/JcDenis/gh2da/blob/master/dotclear/CHANGES.md): API changes 2.39 → 2.40 → 2.41-dev, deprecations, removals;
* [`BEHAVIORS.md`](https://github.com/JcDenis/gh2da/blob/master/dotclear/BEHAVIORS.md): all behaviors called by the core and distributed plugins, with arguments
  (generated from 2.40.1; master has the same list).

The sources always win over this file: when a signature matters, check it in the core at the version required by the module.

## Getting the sources

* Official repository: <https://codeberg.org/Dotclear/dotclear> (issues and PR). Read-only mirror with all tags:
  <https://github.com/dotclear/dotclear> (use it from a workspace that cannot reach Codeberg).
* Branches: `master` is the next version (`release.json` → `release_version`, `2.41-dev` today);
  each stable version gets a maintenance branch and tags `x.y.0`, `x.y.1`… (2.40 released 2026-11-13, 2.40.1 2026-11-17).
* `CHANGELOG` at the root of the core lists every version (no section for the dev version until its release).
* Public API of a tree and differences between two trees:
  `php scripts/dotclear-api.php /path/to/dotclear > a.txt` (see [Tools](#tools)).
* The `AGENTS.md` file of the core is a joke (digits of pi): ignore it.

```bash
git clone --filter=blob:none https://github.com/dotclear/dotclear.git
git -C dotclear checkout 2.40.1      # or master
```

## Architecture

### Entry points and utilities

`src/App.php` (`final class App extends Core`) is the application and the service locator. Each entry point builds it
with a *utility* and a *process*: `admin/index.php` → `new App('Backend', ...)`, `index.php` of a blog → `Frontend`,
plus `Install` and `Upgrade` (also CLI). `App::task()->run()` loads the config (`inc/config.php`), the database,
the blog, the modules, then runs the utility and its process.

* Contexts: `App::task()->checkContext('BACKEND' | 'FRONTEND' | 'INSTALL' | 'UPGRADE' | 'MODULE' | 'PLUGIN_CONFIG' | 'THEME_CONFIG')`.
* `App::config()`: read-only configuration (`dotclearRoot()`, `varRoot()`, `cacheRoot()`, `pluginsRoot()`, `adminUrl()`,
  `devMode()`, `debugMode()`, `modern()` (`DC_MODERN`), `cliMode()`, `release('...')`, `tplsets()` (2.40)…).
* Fatal errors go through `App::fault()` (`Core/Fault.php`); module loading errors are ignored silently since 2.39.

### Services (`App::xxx()`)

Every core service is an interface of `src/Interface/Core/` resolved by a container (`Helper/Container/Factories`),
so a service can be replaced. Static accessors of `Core.php`:

| Accessor | Role |
|---|---|
| `auth()` | current user, permissions (`check()`, `isSuperAdmin()`, `makePermissions()`, `userID()`, `getInfo()`, `prefs()`) |
| `behavior()` | behaviors (`addBehavior()`, `addBehaviors()`, `callBehavior()`) |
| `blog()` | current blog: settings, posts, categories, comments (`getPosts()`, `addPost()`, `updPost()`, `getCategories()`…) |
| `blogs()`, `users()` | blogs and users management |
| `blogSettings()`, `blogWorkspace()` | blog settings factories (use `App::blog()->settings()` or `My::settings()`) |
| `userPreferences()`, `userWorkspace()` | user preferences factories (use `App::auth()->prefs()` or `My::prefs()`) |
| `con()`, `db()` | database connection (`App::con()->prefix()`, `openCursor()`, `select()`), `App::db()->structure()` |
| `config()`, `task()` | configuration, run context |
| `categories()`, `meta()`, `media()`, `postMedia()`, `postTypes()`, `trackback()` | content helpers |
| `error()` | errors of the current request (`add()`, `flag()`, `count()`) |
| `filter()`, `formater()` | HTML/wiki filters, text formaters (editors) |
| `lang()`, `lexical()` | language and locale-aware sorting |
| `log()`, `deprecated()` | logs table, deprecation log (`App::deprecated()->set($replacement, $since, $upto)`) |
| `nonce()`, `session()`, `credential()` | form nonce, session, stored credentials (WebAuthn, OAuth2…) |
| `plugins()`, `themes()` | modules (`getDefine($id)`, `moduleExists()`, `loadNsClass()`) |
| `rest()` | REST services (`addFunction()`) |
| `status()` | statuses of posts, comments, blogs, users (`App::status()->post()`…) |
| `url()` | public URL handlers (`register()`, `getURLFor()`, `getType()`, `isType()`, `isHome()`, `p404()`) |
| `version()` | versions table of the core and modules (`getVersion($id)`, `setVersion()`, `newerVersion()`) |
| `cache()`, `fileserver()`, `notice()` | cache folders, `?pf=`/`?tf=`/`?vf=` files, notices table |
| `backend()`, `frontend()`, `install()`, `upgrade()` | utilities, see below (only in their context) |

`App::backend()` (`Core/Backend/Utility`): `url()` (admin URLs, `get('admin.plugin', ['p' => $id])`), `page()`
(the `Page` helpers, prefer it to static `Page::` calls), `notices()`, `menus()`, `favorites()`, `combos()`, `listing()`,
`filter()`, `action()`, `helper()`, `resources()` (help), `userPref()`, `themeConfig()`, `modulesList()`, `themesList()`,
`mediaPage()`, `auth()` (backend auth, WebAuthn, passkey verification in 2.41), `isPopup()` (2.40).

`App::frontend()` (`Core/Frontend/Utility`): `context()` (`Ctx`, template context: `posts`, `categories`, `comments`, stacks…),
`template()` (`Tpl`, the template engine), `getPageNumber()`, `xmlrpc()`.

### Folders of the core

| Folder | Content |
|---|---|
| `src/Core/` | services implementation; `Backend/` (admin utility: pages, lists, filters, actions, menus, notices, auth), `Frontend/` (public utility: `Ctx`, `Tpl`), `Install/`, `Upgrade/` (incl. `GrowUp/GrowUp_x_y_lt.php` migrations) |
| `src/Process/` | core admin, install and upgrade pages, one class per page (`Process/Backend/Posts.php`…) |
| `src/Module/` | modules: `Modules`, `Plugins`, `Themes`, `ModuleDefine`, `MyModule`/`MyPlugin`/`MyTheme`, stores |
| `src/Database/` | `Cursor`, `Record`/`MetaRecord`/`StaticRecord`, `Structure`/`Table`, `Statement/*Statement` |
| `src/Schema/` | database drivers (Mysqli, Mysqlimb4, PgSQL, PdoSqlite) and the core database structure |
| `src/Helper/` | framework: `Html/Form/*` components, `Html`, `HtmlFilter`, `Template/`, `Network/` (`Http`, `HttpClient`, `UrlHandler`, `Feed`, `Mail`), `File/` (`Files`, `Path`, `Zip`, `Image`, media manager), `Date`, `Text`, `L10n`, `Crypt`, `Otp`, `QRCode`, `OAuth2/`, `WebAuthn/`, `Process/TraitProcess`, `Container/` |
| `src/Interface/` | interfaces of the services and helpers |
| `src/Exception/` | typed exceptions (`BadRequestException`, `NotFoundException`, `UnauthorizedException`…) |
| `inc/` | `config.php` (installation), public prepend, default templates (`inc/public/default-templates/{mustek,dotty,glaz}`), legacy JS |
| `admin/` | admin entry point, JS (`admin/js/common.js`: `dotclear.*` helpers, `dotclear.jsonServicesGet/Post`), CSS, images |
| `plugins/`, `themes/` | distributed modules (list in `release.json`); `dcProxyV1`/`dcProxyV2` relay old APIs when `DC_MODERN` is off |

## Anatomy of a module

```
myModule/
  _define.php        registerModule(name, desc, author, version, properties)
  src/My.php         class My extends MyPlugin (or MyTheme)
  src/Prepend.php    always loaded (URL handlers, REST, behaviors used everywhere)
  src/Install.php    install/update (database structure, settings, migrations)
  src/Backend.php    admin side (menu, favorites, admin behaviors)
  src/Manage.php     main admin page (index.php?process=Plugin&p=myModule)
  src/Config.php     configuration page of the module (from the modules list)
  src/Frontend.php   public side (template tags, public behaviors, widgets)
  src/Uninstall.php  actions for the Uninstaller plugin (optional)
  locales/, icon.svg, icon-dark.svg, README.md, CHANGELOG.md, LICENSE
```

* Namespace `Dotclear\Plugin\<id>` (or `Dotclear\Theme\<id>`), autoloaded from `src/`.
* `_define.php` properties: `permissions` (`'My'` delegates to `My::checkCustomContext()`, `null` = super admin only, or a permissions string),
  `type` (`plugin`/`theme`), `priority`, `requires` (`[['core', '2.40'], ['otherModule', '1.0']]`), `settings`
  (where the settings are: `'self' => ''`, `'blog' => '#params.id'`, `'pref' => '#user-options.id'`), `standalone_config`,
  `support`, `details`, `repository` (store URL), `date`; themes: `parent`, `tplset`, widget formats.
* Each `src/` process class uses `Dotclear\Helper\Process\TraitProcess`: `init()` sets the status with
  `self::status(My::checkContext(My::BACKEND))`, `process()` returns `false` when the status is off, `render()` prints.
  The core calls `init()` then `process()` (then `render()` for pages) through `Modules::loadNsClass()`.
* `My` contexts: `INSTALL`, `PREPEND`, `FRONTEND`, `BACKEND`, `MANAGE`, `CONFIG`, `MENU`, `WIDGETS`, `UNINSTALL`, `MODULE`.
  Default permissions: `BACKEND` usage or content admin, `MANAGE`/`MENU`/`WIDGETS` admin of the blog, `INSTALL`/`CONFIG`/`UNINSTALL` super admin;
  override in `My::checkCustomContext(int $context): ?bool` (return `null` to keep the default).
* `My` helpers: `id()`, `name()`, `path()`, `settings()` (blog workspace named after the module), `prefs()` (user workspace),
  `manageUrl()`, `redirect()`, `hiddenFields()` / `parsedHiddenFields()`, `fileURL()`, `cssLoad()`, `jsLoad()`,
  `icons()` / `icon()` (`icon.svg` + `icon-dark.svg`), `addBackendMenuItem(Menus::MENU_PLUGINS|MENU_BLOG|MENU_SYSTEM)`, `l10n()`.
* Example of reference: the distributed plugin `blogroll` (own table, admin page with list, actions, widgets, template tags, URL handler).

## Main APIs

### Database

```php
use Dotclear\Database\Statement\SelectStatement;

$sql = new SelectStatement();
$rs  = $sql
    ->columns(['link_id', 'link_title'])
    ->from(App::con()->prefix() . 'link')
    ->where('blog_id = ' . $sql->quote(App::blog()->id()))
    ->and('link_status = ' . Link::ONLINE)
    ->order('link_position ASC')
    ->select();             // ?MetaRecord

while ($rs instanceof MetaRecord && $rs->fetch()) {
    $id = $rs->intField('link_id');     // typed readers since 2.39
}

$cur = App::con()->openCursor(App::con()->prefix() . 'link');
$cur->setStrField('link_title', $title);    // typed setters since 2.40
$cur->insert();                              // or $cur->update('WHERE link_id = ' . $id)
```

* Statements: `SelectStatement`, `UpdateStatement` (`->update($cur)`), `InsertStatement`, `DeleteStatement`, `JoinStatement`,
  `TruncateStatement`, `DropStatement`. Helpers on all: `quote()`, `escape()`, `in()` (with `sanitizeIn()`, 2.39), `like()`, `between()`
  and `caseWhen()` (2.40), `isNull()`, `count()`, `max()`, `andGroup()`/`orGroup()`, `inSelect()`.
* `MetaRecord`: `fetch()`, `isEmpty()`, `count()`, `strField()`/`intField()`/`boolField()` (2.39; `strField()` returns `''` for a number with SQLite),
  `extend()` for static helpers, `toStatic()`, `MetaRecord::newFromArray()`.
* Structure (in `Install.php`): `$s = App::db()->structure(); $s->table('name')->field(...)->primary(...)->index(...)->reference(...);`
  then `App::db()->structure()->synchronize($s)`. Since 2.39 `table()` returns the already registered table; `$s->name` is deprecated.
* Never write SQL that depends on the driver: use the statement helpers, keep table names in lower case.

### Settings and preferences

* Blog: `My::settings()->getStr('key')` (also `getInt()`, `getBool()`, `getFloat()`, `get()`), `->put('key', $value, 'string', 'label', true, false)`;
  other workspace: `App::blog()->settings()->get('system')`.
* User: `My::prefs()` / `App::auth()->prefs()->get('interface')`, same methods. The old user `options` field is deprecated (2.40): use preferences.

### Admin pages

* Page skeleton: `App::backend()->page()->openModule(My::name(), $head)`, `breadcrumb([...])`, `App::backend()->notices()->getNotices()`,
  forms built with `Helper/Html/Form` components (`(new Form('id'))->method('post')->action(...)->fields([...])->render()`,
  never escaped automatically: `Html::escapeHTML()`), nonce with `App::nonce()->formNonce()` (added by `My::hiddenFields()`),
  `App::backend()->page()->helpBlock(My::id())`, `closeModule()`.
* Notices: `App::backend()->notices()->addSuccessNotice()`, `addWarningNotice()`, `addErrorNotice()` (redirect after POST, notices survive),
  `addNewNotice(new Notice(...))` (2.39). Errors of the request: `App::error()->add()`.
* Lists: a `Listing` subclass (`display()`), filters with `Filters` and `UserPrefFilter` (mandatory since 2.39, sort/order/nb stored in user preferences),
  actions on selected items with an `Actions` subclass (`addAction()`, `getIDs()`, `getRS()`), behaviors `adminFiltersListsV3`, `adminColumnsListsV2`.
* JS: `page()->jsJson('id', $data)` + `dotclear.getData('id')`, `jsLoad()`, `jsConfirmClose()`, `jsPageTabs()`; REST calls from JS with
  `dotclear.jsonServicesGet('fn', onSuccess, params)` / `jsonServicesPost`, served by `App::rest()->addFunction('fn', callable(array $get, array $post): array)`.
* Popup pages: `App::backend()->isPopup()` (2.40, replaces `App::backend()->popup`).

### Public side

* URL handlers: `App::url()->register('type', 'url', '^url/(.+)$', Handler::method(...))` in `Prepend.php`; in the handler,
  `App::url()::serveDocument('template.html')` or `App::url()::p404()`; current type `App::url()->getType()` / `isType()` (2.39).
* Template tags: `App::frontend()->template()->addValue('Tag', fn (ArrayObject $attr): string => '<?= ... ?>')`,
  `addBlock('Tag', fn (ArrayObject $attr, string $content): string)`. Callbacks return **PHP code** compiled into the template cache.
  Template files of a module: one per template set (`mustek`, `dotty`, and `glaz` since 2.39) in `default-templates/<set>/`.
* Context: `App::frontend()->context()` (`Ctx`); content filters: behavior `coreContentFilterV2` (2.40).
* Public sessions are handled by dedicated plugins (FrontendSession…), never by a module.

### Widgets (plugin `widgets`)

* Behaviors `initWidgets` (`WidgetsStack $widgets`) and `initDefaultWidgets`, registered in `Backend.php` and `Frontend.php`.
* `$widgets->create('id', __('Name'), Class::render(...), null, __('Description'))->addTitle(__('Title'))->setting('name', __('Label'), $default, 'text'|'textarea'|'check'|'radio'|'combo'|'color'|'email'|'number', $options)->addHomeOnly()->addContentOnly()->addClass()->addOffline()`.
* 2.40: the 5th argument of `setting()` is the options array (no more variadic `opts`), `checkHomeOnly($type)` takes one argument,
  typed accessors `getTitle()`, `getHomeOnly()`, `getContentOnly()`, `getClass()`, `getOffline()`, `getNotOnArchive()` and setters, `addNotOnArchive()`.
* Render: `$widget->renderDiv($widget->getContentOnly(), 'my-class ' . $widget->getClass(), '', $content)` after `$widget->checkHomeOnly(App::url()->getType())`.

### Permissions

* Constants on `App::auth()::PERMISSION_*`: `SUPERADMIN`, `ADMIN`, `CONTENT_ADMIN`, `USAGE`, `PUBLISH`, `DELETE`, `CATEGORIES`, `MEDIA_ADMIN`, `MEDIA`.
* `App::auth()->check(App::auth()->makePermissions([...]), App::blog()->id())`; new permission: `App::auth()->setPermissionType('name', __('label'))` in `Backend.php`.
* Frontend code (REST, connectors, URL handlers) runs without the admin checks: check permissions in the code.

### Install and migrations

* `Install::init()` uses `My::checkContext(My::INSTALL)` (super admin and newer version only). Read the installed version with
  `App::version()->getVersion(My::id())` and migrate with `version_compare()`; the core stores the new version after `process()`.
* Core migrations live in `src/Core/Upgrade/GrowUp/GrowUp_x_y_lt.php` (run when upgrading from a lower version;
  the last ones are `GrowUp_2_38_lt` in 2.40 and `GrowUp_2_41_lt` in 2.41-dev).

## Pitfalls

* `DC_MODERN` (2.39, `inc/config.php`): when true, no legacy code is loaded (old classes, `$GLOBALS`, deprecated JS). Code and test as if it were true.
  In 2.41-dev the deprecated `dcCore` and `dcUtils` classes are removed: `dcCore::app()` no longer exists at all.
* PHPStan stubs: the core ships `phpstan.bootstrap` and stubs (`stubs/`) used by the module workflow; `MetaRecord` extensions need the stub of the core.
* SQLite returns native int/float, MySQL/PostgreSQL strings: read with typed helpers, compare as integers.
* `Http::head()` sent an invalid status header outside CGI (fixed in 2.41-dev): with `php -S`, JSON responses could be empty.
* Compiled templates are included with `@include`: PHP errors of public pages are hidden.
* `Notices`: times are stored in UTC since 2.40.1 (they were shifted by the user time zone).
* Zip archives inflated in the media manager: files matching the media exclusion pattern (PHP, HTML, JS…) are deleted after extraction
  (security fix 2.40.1). Media file extensions are checked case insensitively since 2.40 (`x.PHP.jpg`).
* The latest stable version is listed in `versions.xml` of download.dotclear.org, never in `releases.xml` (previous versions).

## Tools

* `scripts/dotclear-api.php <tree>`: public API (classes, public/protected methods with signatures, public constants) of `src/` and `plugins/*/src/`,
  one line per symbol, sorted; `diff` two dumps to see API changes between versions.
* `scripts/dotclear-behaviors.php <tree> [version]`: Markdown table of the behaviors (regenerates `BEHAVIORS.md`).

```bash
git -C dotclear archive 2.40.1 src plugins inc themes admin | (mkdir -p /tmp/dc-2.40.1 && tar -x -C /tmp/dc-2.40.1)
php scripts/dotclear-api.php /tmp/dc-2.40.1 > /tmp/api-2.40.1.txt
php scripts/dotclear-behaviors.php /tmp/dc-2.40.1 2.40.1 > dotclear/BEHAVIORS.md
```

When a new Dotclear version is released, update `CHANGES.md` (from the core `CHANGELOG` and the API diff), regenerate `BEHAVIORS.md`
and the version line at the top of this file.
