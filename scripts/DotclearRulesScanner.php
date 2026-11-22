<?php

/**
 * Check the code of a Dotclear module (plugin or theme) against the compatibility rules of the next Dotclear version.
 *
 * Rules come from `dotclear/rules.json` (see dotclear/RULES.md). The scanner reads PHP files with the tokenizer
 * and JavaScript files with the patterns of the rules; no code of the module is run.
 *
 * Standalone on purpose: no dependency on Dotclear, so it can run in a CI, from the command line
 * (scripts/dotclear-scan.php) or inside DotclearWatch.
 *
 * Usage:
 *
 *     $scanner = DotclearRulesScanner::fromFile('rules.json', '2.40.1');
 *     $report  = $scanner->scan('/path/to/module');
 *
 * Each finding: file, line, id, type, symbol, level (alert, notice), rule_level (level of the rule), replace, note,
 * match (sure, probable), context (import, extends, implements, use, new, static, call, override, property, string, behavior, js),
 * guarded (true when the file already checks for the element or the Dotclear version: alerts are lowered to notices).
 */
declare(strict_types=1);

final class DotclearRulesScanner
{
    public const ALERT  = 'alert';
    public const NOTICE = 'notice';

    /**
     * Directories never scanned.
     *
     * @var array<int, string>
     */
    public const EXCLUDE = ['vendor', 'node_modules', '.git', '.github', 'docs', 'tests'];

    /**
     * Header of the rules file (stable, next, commit, php_min).
     *
     * @var array<string, string>
     */
    private array $header = [];

    /** @var array<string, array<string, mixed>> Class rules by lower case name */
    private array $classes = [];

    /** @var array<string, array<string, array<string, mixed>>> Member rules by lower case class, then by key (m:name, c:NAME, p:name) */
    private array $members = [];

    /** @var array<string, array<int, array<string, mixed>>> Method rules found by their name alone (unique), by lower case name */
    private array $unique = [];

    /** @var array<string, array<string, mixed>> Function rules by lower case name */
    private array $functions = [];

    /** @var array<string, array<string, mixed>> Behavior rules by name */
    private array $behaviors = [];

    /** @var array<int, array<string, mixed>> JS rules with a pattern */
    private array $js = [];

    /** @var array<string, true> Lower case names (full and short) of all PHP symbols, for guards */
    private array $names = [];

    /** @var array<string, string> Parent of each class of the module, lower case */
    private array $parents = [];

    /** @var array<string, true> Methods declared by the module, lower case */
    private array $declared = [];

    /**
     * @param   array<string, mixed>    $rules      The decoded rules file
     * @param   string                  $version    The current Dotclear version: rules already applied to it are skipped
     */
    public function __construct(array $rules, string $version = '')
    {
        foreach ((array) ($rules['dotclear'] ?? []) as $k => $v) {
            $this->header[(string) $k] = (string) $v;
        }
        foreach ((array) ($rules['rules'] ?? []) as $rule) {
            if (!is_array($rule) || empty($rule['symbol']) || empty($rule['type']) || !empty($rule['disabled'])) {
                continue;
            }
            $since = (string) ($rule['since'] ?? '');
            if ($version !== '' && $since !== '' && version_compare($since, $version, '<=')) {
                continue;
            }
            $this->addRule($rule);
        }
    }

    /**
     * Create a scanner from a rules file (path or URL).
     */
    public static function fromFile(string $path, string $version = ''): self
    {
        $json = json_decode((string) @file_get_contents($path), true);
        if (!is_array($json)) {
            throw new RuntimeException('Can not read rules from ' . $path);
        }

        return new self($json, $version);
    }

    /**
     * Get the header of the rules (stable, next, commit, php_min).
     *
     * @return  array<string, string>
     */
    public function header(): array
    {
        return $this->header;
    }

    /**
     * Check a PHP version against the minimum PHP version of the next Dotclear version.
     *
     * @return  array<string, mixed>|null   A finding, or null if the version is enough
     */
    public function checkPhp(string $version = PHP_VERSION): ?array
    {
        $min = $this->header['php_min'] ?? '';
        if ($min === '' || version_compare($version, $min, '>=')) {
            return null;
        }

        return [
            'file'       => '',
            'line'       => 0,
            'id'         => 'php:' . $min,
            'type'       => 'php',
            'symbol'     => 'PHP ' . $version,
            'level'      => self::ALERT,
            'rule_level' => self::ALERT,
            'replace'    => 'PHP ' . $min,
            'note'       => 'Dotclear ' . ($this->header['next'] ?? '') . ' requires PHP ' . $min . ' or later',
            'match'      => 'sure',
            'context'    => 'php',
            'guarded'    => false,
        ];
    }

    /**
     * Scan a module directory.
     *
     * @param   array<int, string>  $exclude    Directories to skip (names, at any depth)
     *
     * @return  array{level: string, files: int, alerts: int, notices: int, findings: array<int, array<string, mixed>>}
     */
    public function scan(string $dir, array $exclude = self::EXCLUDE): array
    {
        $dir   = rtrim($dir, '/\\');
        $files = [];
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    fn (SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), $exclude, true))
                )
            );
            foreach ($it as $f) {
                $path = (string) $f;
                if (str_ends_with($path, '.php') || str_ends_with($path, '.js')) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        // First pass: classes of the module and their parents
        $this->parents  = [];
        $this->declared = [];
        foreach ($files as $path) {
            if (str_ends_with($path, '.php')) {
                $this->collectParents((string) file_get_contents($path));
            }
        }

        $findings = [];
        foreach ($files as $path) {
            $rel  = substr($path, strlen($dir) + 1);
            $code = (string) file_get_contents($path);
            $found = str_ends_with($path, '.php') ? $this->scanPhp($code, $rel) : $this->scanJs($code, $rel);
            array_push($findings, ...$found);
        }

        return $this->report($findings, count($files));
    }

    /**
     * Build a report from findings.
     *
     * @param   array<int, array<string, mixed>>    $findings
     *
     * @return  array{level: string, files: int, alerts: int, notices: int, findings: array<int, array<string, mixed>>}
     */
    public function report(array $findings, int $files = 0): array
    {
        $alerts  = count(array_filter($findings, fn ($f): bool => $f['level'] === self::ALERT));
        $notices = count($findings) - $alerts;

        return [
            'level'    => $alerts > 0 ? self::ALERT : ($notices > 0 ? self::NOTICE : 'ok'),
            'files'    => $files,
            'alerts'   => $alerts,
            'notices'  => $notices,
            'findings' => $findings,
        ];
    }

    /**
     * Scan the code of a JavaScript file.
     *
     * @return  array<int, array<string, mixed>>
     */
    public function scanJs(string $code, string $file): array
    {
        $findings = [];
        foreach ($this->js as $rule) {
            if (@preg_match_all('/' . str_replace('/', '\/', (string) $rule['pattern']) . '/', $code, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$text, $offset]) {
                    $line            = substr_count(substr($code, 0, (int) $offset), "\n") + 1;
                    $findings[$line . $rule['id']] = $this->finding($rule, $file, $line, 'sure', 'js');
                }
            }
        }

        return array_values($findings);
    }

    /**
     * Scan the code of a PHP file.
     *
     * @return  array<int, array<string, mixed>>
     */
    public function scanPhp(string $code, string $file): array
    {
        $sig = $this->tokens($code);
        $n   = count($sig);

        $ns       = '';
        $uses     = [];   // class imports: lower case alias => name
        $fuses    = [];   // function imports
        $classes  = [];   // stack of [name, parent, depth]
        $braces   = 0;
        $parens   = 0;
        $behavior = -1;   // parens depth of an addBehavior() call
        $guarded  = false;
        $findings = [];

        $add = function (array $rule, int $line, string $match, string $context) use (&$findings, $file): void {
            $findings[$line . '|' . $rule['id']] ??= $this->finding($rule, $file, $line, $match, $context);
        };
        $current = function () use (&$classes): array {
            return $classes === [] ? ['', ''] : end($classes);
        };

        for ($i = 0; $i < $n; $i++) {
            [$id, $text, $line] = $sig[$i];
            $prev = $sig[$i - 1][0] ?? null;
            $next = $sig[$i + 1][0] ?? null;

            // Braces and parentheses
            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braces++;

                continue;
            }
            if ($id === '}') {
                $braces--;
                while ($classes !== [] && $braces < end($classes)[2]) {
                    array_pop($classes);
                }

                continue;
            }
            if ($id === '(') {
                $parens++;

                continue;
            }
            if ($id === ')') {
                $parens--;
                if ($behavior >= 0 && $parens < $behavior) {
                    $behavior = -1;
                }

                continue;
            }

            switch ($id) {
                case T_NAMESPACE:
                    if (in_array($next, [T_STRING, T_NAME_QUALIFIED], true)) {
                        $ns    = $sig[$i + 1][1];
                        $uses  = [];
                        $fuses = [];
                        $i++;
                    }

                    break;

                case T_USE:
                    if ($prev === ')') {
                        // closure use (...)
                        break;
                    }
                    [$cname] = $current();
                    if ($classes !== [] && $braces === end($classes)[2]) {
                        // Traits
                        for ($j = $i + 1; $j < $n && $sig[$j][0] !== ';' && $sig[$j][0] !== '{'; $j++) {
                            if ($this->isName($sig[$j][0])) {
                                $this->checkClass($this->resolve($sig[$j][1], $ns, $uses), $sig[$j][2], 'use', $add);
                            }
                        }
                        $i = $j - 1;

                        break;
                    }
                    if ($braces > 0 && $classes === []) {
                        break;
                    }
                    $i = $this->readImports($sig, $i, $uses, $fuses, $add);

                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    if ($prev === T_DOUBLE_COLON) {
                        break;
                    }
                    $anonymous = $prev === T_NEW;
                    $name      = '';
                    $j         = $i + 1;
                    if (!$anonymous && ($sig[$j][0] ?? null) === T_STRING) {
                        $name = $ns === '' ? $sig[$j][1] : $ns . '\\' . $sig[$j][1];
                        $j++;
                    }
                    if ($anonymous && ($sig[$j][0] ?? null) === '(') {
                        // skip constructor arguments of an anonymous class
                        for ($depth = 0; $j < $n; $j++) {
                            if ($sig[$j][0] === '(') {
                                $depth++;
                            } elseif ($sig[$j][0] === ')' && --$depth === 0) {
                                $j++;

                                break;
                            }
                        }
                    }
                    $parent  = '';
                    $context = '';
                    for (; $j < $n && $sig[$j][0] !== '{'; $j++) {
                        if ($sig[$j][0] === T_EXTENDS) {
                            $context = 'extends';
                        } elseif ($sig[$j][0] === T_IMPLEMENTS) {
                            $context = 'implements';
                        } elseif ($this->isName($sig[$j][0]) && $context !== '') {
                            $resolved = $this->resolve($sig[$j][1], $ns, $uses);
                            if ($context === 'extends' && $parent === '') {
                                $parent = $resolved;
                            }
                            $this->checkClass($resolved, $sig[$j][2], $context, $add);
                        }
                    }
                    if ($j < $n) {
                        $classes[] = [$name, $parent, $braces + 1];
                        $braces++;
                    }
                    $i = $j;

                    break;

                case T_FUNCTION:
                    // Method declaration overriding a core method with a rule
                    if ($classes !== [] && $braces === end($classes)[2]) {
                        $j = $i + 1;
                        if (($sig[$j][0] ?? null) === '&') {
                            $j++;
                        }
                        if (($sig[$j][0] ?? null) === T_STRING) {
                            [$cname, $cparent] = $current();
                            foreach ($this->chain($cname, $cparent, false) as $class) {
                                if ($rule = $this->members[$class]['m:' . strtolower($sig[$j][1])] ?? null) {
                                    $add($rule, $sig[$j][2], 'sure', 'override');
                                }
                            }
                            $i = $j;
                        }
                    }

                    break;

                case T_DOUBLE_COLON:
                    $left  = $sig[$i - 1] ?? null;
                    $right = $sig[$i + 1] ?? null;
                    if ($left === null || $right === null) {
                        break;
                    }
                    [$cname, $cparent] = $current();
                    $lname = strtolower($left[1]);
                    if ($left[0] === T_STATIC || $lname === 'self' || $lname === 'static') {
                        $chain = $this->chain($cname, $cparent);
                    } elseif ($lname === 'parent') {
                        $chain = $this->chain($cparent, '');
                    } elseif ($this->isName($left[0])) {
                        $chain = $this->chain($this->resolve($left[1], $ns, $uses), '');
                    } else {
                        break;
                    }
                    if ($right[0] === T_STRING) {
                        $key = ($sig[$i + 2][0] ?? null) === '(' ? 'm:' . strtolower($right[1]) : 'c:' . $right[1];
                    } elseif ($right[0] === T_VARIABLE) {
                        $key = 'p:' . strtolower(substr($right[1], 1));
                    } else {
                        break;
                    }
                    foreach ($chain as $class) {
                        if ($rule = $this->members[$class][$key] ?? null) {
                            $add($rule, $right[2], 'sure', 'static');

                            break;
                        }
                    }
                    $i++;

                    break;

                case T_OBJECT_OPERATOR:
                case T_NULLSAFE_OBJECT_OPERATOR:
                    $right = $sig[$i + 1] ?? null;
                    if ($right === null || $right[0] !== T_STRING) {
                        break;
                    }
                    $lname  = strtolower($right[1]);
                    $call   = ($sig[$i + 2][0] ?? null) === '(';
                    $isThis = ($sig[$i - 1][0] ?? null) === T_VARIABLE && $sig[$i - 1][1] === '$this';
                    if ($call && in_array($lname, ['addbehavior', 'addbehaviors', 'addbehaviour', 'addbehaviours'], true)) {
                        $behavior = $parens + 1;
                    }
                    $found = false;
                    if ($isThis) {
                        [$cname, $cparent] = $current();
                        foreach ($this->chain($cname, $cparent, false) as $class) {
                            if ($rule = $this->members[$class][($call ? 'm:' : 'p:') . $lname] ?? null) {
                                $add($rule, $right[2], 'sure', $call ? 'call' : 'property');
                                $found = true;

                                break;
                            }
                        }
                    }
                    // By name alone: not for $this (a method of the class or its parents) nor a method declared by the module
                    if (!$found && $call && !$isThis && !isset($this->declared[$lname])) {
                        foreach ($this->unique[$lname] ?? [] as $rule) {
                            $add($rule, $right[2], 'probable', 'call');
                        }
                    }
                    $i++;

                    break;

                case T_CONSTANT_ENCAPSED_STRING:
                    $value = stripcslashes(substr($text, 1, -1));
                    if ($behavior >= 0 && ($rule = $this->behaviors[$value] ?? null)) {
                        $add($rule, $line, 'sure', 'behavior');
                    } elseif (str_contains($value, '\\') && ($rule = $this->classes[strtolower(ltrim($value, '\\'))] ?? null)) {
                        // Only qualified names: a short one ('form') is often a plain word
                        $add($rule, $line, 'probable', 'string');
                    }

                    break;

                default:
                    if (!$this->isName($id)) {
                        break;
                    }
                    if (in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_GOTO], true)) {
                        break;
                    }
                    $lname = strtolower($text);
                    if ($next === '(' && $prev !== T_NEW) {
                        // Guards
                        if (in_array($lname, ['class_exists', 'interface_exists', 'method_exists', 'property_exists', 'function_exists', 'defined', 'version_compare', '\\class_exists', '\\method_exists', '\\version_compare'], true)) {
                            $guarded = $guarded || $this->isGuard($sig, $i + 1);
                        }
                        // Behaviors registered with a function call (old code)
                        if (in_array($lname, ['addbehavior', 'addbehaviors'], true)) {
                            $behavior = $parens + 1;
                        }
                        // Function call
                        foreach ($this->resolveFunction($text, $ns, $fuses) as $fname) {
                            if ($rule = $this->functions[$fname] ?? null) {
                                $add($rule, $line, 'sure', 'call');

                                break;
                            }
                        }

                        break;
                    }
                    if (in_array($lname, ['self', 'static', 'parent', 'true', 'false', 'null'], true)) {
                        break;
                    }
                    $this->checkClass($this->resolve($text, $ns, $uses), $line, $prev === T_NEW ? 'new' : ($next === T_DOUBLE_COLON ? 'static' : 'use'), $add);
            }
        }

        // A class and one of its members on the same line: keep the member when it says what to use instead, else the class
        foreach ($findings as $key => $f) {
            if ($f['type'] !== 'class') {
                continue;
            }
            foreach ($findings as $k => $m) {
                if ($m['line'] === $f['line'] && $m['type'] !== 'class' && stripos($m['symbol'], $f['symbol'] . '::') === 0) {
                    if ($m['replace'] !== '') {
                        unset($findings[$key]);

                        continue 2;
                    }
                    unset($findings[$k]);
                }
            }
        }

        $findings = array_values($findings);
        if ($guarded) {
            foreach ($findings as $k => $f) {
                if ($f['level'] === self::ALERT) {
                    $findings[$k]['level']   = self::NOTICE;
                    $findings[$k]['guarded'] = true;
                }
            }
        }

        return $findings;
    }

    /**
     * Add a rule to the indexes.
     *
     * @param   array<string, mixed>    $rule
     */
    private function addRule(array $rule): void
    {
        $symbol = ltrim((string) $rule['symbol'], '\\');
        switch ($rule['type']) {
            case 'class':
                $this->classes[strtolower($symbol)] = $rule;
                $this->names[strtolower($symbol)]   = true;
                $this->names[strtolower($this->short($symbol))] = true;

                break;

            case 'method':
            case 'const':
            case 'property':
                if (!str_contains($symbol, '::')) {
                    break;
                }
                [$class, $member] = explode('::', $symbol, 2);
                $key              = match ($rule['type']) {
                    'method'   => 'm:' . strtolower($member),
                    'const'    => 'c:' . $member,
                    default    => 'p:' . strtolower(ltrim($member, '$')),
                };
                $this->members[strtolower($class)][$key] = $rule;
                if ($rule['type'] === 'method' && !empty($rule['unique'])) {
                    $this->unique[strtolower($member)][] = $rule;
                }
                $this->names[strtolower($class)] = true;
                $this->names[strtolower(ltrim($member, '$'))] = true;

                break;

            case 'function':
                $this->functions[strtolower($symbol)]            = $rule;
                $this->names[strtolower($symbol)]                = true;
                $this->names[strtolower($this->short($symbol))] = true;

                break;

            case 'behavior':
                $this->behaviors[$symbol] = $rule;

                break;

            case 'js':
                if (!empty($rule['pattern']) && @preg_match('/' . str_replace('/', '\/', (string) $rule['pattern']) . '/', '') !== false) {
                    $this->js[] = $rule;
                }

                break;
        }
    }

    /**
     * Build a finding.
     *
     * @param   array<string, mixed>    $rule
     *
     * @return  array<string, mixed>
     */
    private function finding(array $rule, string $file, int $line, string $match, string $context): array
    {
        $level = ($rule['level'] ?? self::ALERT) === self::NOTICE ? self::NOTICE : self::ALERT;

        return [
            'file'       => str_replace('\\', '/', $file),
            'line'       => $line,
            'id'         => (string) $rule['id'],
            'type'       => (string) $rule['type'],
            'symbol'     => ltrim((string) $rule['symbol'], '\\'),
            'level'      => $level,
            'rule_level' => $level,
            'replace'    => (string) ($rule['replace'] ?? ''),
            'note'       => (string) ($rule['note'] ?? ''),
            'match'      => $match,
            'context'    => $context,
            'guarded'    => false,
        ];
    }

    /**
     * Check a resolved class name against the class rules.
     */
    private function checkClass(string $name, int $line, string $context, callable $add): void
    {
        if ($rule = $this->classes[strtolower($name)] ?? null) {
            $add($rule, $line, 'sure', $context);
        }
    }

    /**
     * Get the significant tokens of a PHP code: [id, text, line], id is the token constant or the character.
     *
     * @return  array<int, array{0: int|string, 1: string, 2: int}>
     */
    private function tokens(string $code): array
    {
        $sig  = [];
        $line = 1;
        try {
            $tokens = token_get_all($code);
        } catch (Throwable) {
            return [];
        }
        foreach ($tokens as $t) {
            if (is_array($t)) {
                $line = $t[2];
                if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    $sig[] = [$t[0], $t[1], $t[2]];
                }
                $line += substr_count($t[1], "\n");
            } else {
                $sig[] = [$t, $t, $line];
            }
        }

        return $sig;
    }

    /**
     * Is a token a (possibly qualified) name.
     */
    private function isName(int|string|null $id): bool
    {
        return in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
    }

    /**
     * Resolve a class name read in a file.
     *
     * @param   array<string, string>   $uses
     */
    private function resolve(string $name, string $ns, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($ns . '\\' . substr($name, 10), '\\');
        }
        $parts = explode('\\', $name, 2);
        if (isset($uses[strtolower($parts[0])])) {
            return $uses[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }
        $full = $ns === '' ? $name : $ns . '\\' . $name;
        // Legacy global classes (dcCore…) used without import from a namespace: PHP fails, report them all the same
        if ($ns !== '' && !isset($parts[1]) && !isset($this->classes[strtolower($full)]) && isset($this->classes[strtolower($name)])) {
            return $name;
        }

        return $full;
    }

    /**
     * Get the candidate full names of a called function.
     *
     * @param   array<string, string>   $fuses
     *
     * @return  array<int, string>  Lower case names, in the order PHP tries them
     */
    private function resolveFunction(string $name, string $ns, array $fuses): array
    {
        if (str_starts_with($name, '\\')) {
            return [strtolower(substr($name, 1))];
        }
        if (isset($fuses[strtolower($name)])) {
            return [strtolower($fuses[strtolower($name)])];
        }
        if (str_contains($name, '\\')) {
            return [strtolower($ns === '' ? $name : $ns . '\\' . $name)];
        }

        return $ns === '' ? [strtolower($name)] : [strtolower($ns . '\\' . $name), strtolower($name)];
    }

    /**
     * Read a use statement (imports) and check imported classes.
     *
     * @param   array<int, array{0: int|string, 1: string, 2: int}>     $sig
     * @param   array<string, string>                                   $uses
     * @param   array<string, string>                                   $fuses
     *
     * @return  int     Index of the end of the statement
     */
    private function readImports(array $sig, int $i, array &$uses, array &$fuses, callable $add): int
    {
        $n    = count($sig);
        $kind = 'class';
        $j    = $i + 1;
        if (($sig[$j][0] ?? null) === T_FUNCTION) {
            $kind = 'function';
            $j++;
        } elseif (($sig[$j][0] ?? null) === T_CONST) {
            $kind = 'const';
            $j++;
        }
        $prefix = '';
        $name   = '';
        $alias  = '';
        $line   = $sig[$i][2];
        $flush  = function () use (&$name, &$alias, &$prefix, &$kind, &$uses, &$fuses, &$line, $add): void {
            if ($name === '') {
                return;
            }
            $full = ltrim($prefix . $name, '\\');
            $key  = strtolower($alias !== '' ? $alias : $this->short($full));
            if ($kind === 'class') {
                $uses[$key] = $full;
                $this->checkClass($full, $line, 'import', $add);
            } elseif ($kind === 'function') {
                $fuses[$key] = $full;
                if ($rule = $this->functions[strtolower($full)] ?? null) {
                    $add($rule, $line, 'sure', 'import');
                }
            }
            $name  = '';
            $alias = '';
        };
        for (; $j < $n && $sig[$j][0] !== ';'; $j++) {
            [$id, $text, $l] = $sig[$j];
            if ($id === '{') {
                // group use: prefix\{A, B}
                $prefix = rtrim($name, '\\') . '\\';
                $name   = '';
            } elseif ($id === ',' || $id === '}') {
                $flush();
            } elseif ($id === T_AS) {
                $j++;
                $alias = $sig[$j][1] ?? '';
            } elseif ($id === T_FUNCTION) {
                $kind = 'function';
            } elseif ($id === T_CONST) {
                $kind = 'const';
            } elseif ($this->isName($id) || $id === T_NS_SEPARATOR) {
                $name .= $text;
                $line = $l;
            }
        }
        $flush();

        return $j;
    }

    /**
     * Does a guard call (class_exists, method_exists, version_compare…) check an element of the rules or the Dotclear version.
     *
     * @param   array<int, array{0: int|string, 1: string, 2: int}>     $sig
     */
    private function isGuard(array $sig, int $open): bool
    {
        $n = count($sig);
        for ($j = $open, $depth = 0; $j < $n; $j++) {
            [$id, $text] = $sig[$j];
            if ($id === '(') {
                $depth++;
            } elseif ($id === ')' && --$depth === 0) {
                break;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = strtolower(ltrim(stripcslashes(substr($text, 1, -1)), '\\'));
                if (isset($this->names[$value]) || isset($this->names[strtolower($this->short($value))])) {
                    return true;
                }
            } elseif (in_array(strtolower($text), ['dotclearversion', 'dc_version'], true)) {
                return true;
            } elseif ($id === T_STRING && strtolower($text) === 'class' && ($sig[$j - 1][0] ?? null) === T_DOUBLE_COLON) {
                // class_exists(Foo::class)
                if (isset($this->names[strtolower($this->short($sig[$j - 2][1] ?? ''))])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Collect the classes of a PHP file and their parents (first pass of scan()).
     */
    private function collectParents(string $code): void
    {
        $sig  = $this->tokens($code);
        $n    = count($sig);
        $ns   = '';
        $uses = [];
        $none = [];
        $skip = function (): void {
        };
        for ($i = 0; $i < $n; $i++) {
            $id = $sig[$i][0];
            if ($id === T_NAMESPACE && in_array($sig[$i + 1][0] ?? null, [T_STRING, T_NAME_QUALIFIED], true)) {
                $ns   = $sig[$i + 1][1];
                $uses = [];
            } elseif ($id === T_USE && ($sig[$i - 1][0] ?? null) !== ')') {
                $i = $this->readImports($sig, $i, $uses, $none, $skip);
            } elseif ($id === T_FUNCTION && ($sig[$i + 1][0] ?? null) === T_STRING) {
                $this->declared[strtolower($sig[$i + 1][1])] = true;
            } elseif ($id === T_CLASS && ($sig[$i + 1][0] ?? null) === T_STRING && !in_array($sig[$i - 1][0] ?? null, [T_DOUBLE_COLON, T_NEW], true)) {
                $name = $ns === '' ? $sig[$i + 1][1] : $ns . '\\' . $sig[$i + 1][1];
                if (($sig[$i + 2][0] ?? null) === T_EXTENDS && $this->isName($sig[$i + 3][0] ?? null)) {
                    $this->parents[strtolower($name)] = strtolower($this->resolve($sig[$i + 3][1], $ns, $uses));
                }
            }
        }
    }

    /**
     * Get a class and its known parents, lower case.
     *
     * @return  array<int, string>
     */
    private function chain(string $class, string $parent, bool $self = true): array
    {
        $chain = [];
        $class = strtolower($class);
        $next  = $parent !== '' ? strtolower($parent) : ($this->parents[$class] ?? '');
        if ($self && $class !== '') {
            $chain[] = $class;
        }
        while ($next !== '' && !in_array($next, $chain, true) && $next !== $class) {
            $chain[] = $next;
            $next    = $this->parents[$next] ?? '';
        }

        return $chain;
    }

    /**
     * Get the short name of a class or function.
     */
    private function short(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
