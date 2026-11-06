<?php

/**
 * Small helper run inside the Dotclear container (SQLite database).
 *
 * Usage: php dotclear-db.php <database> <command> [argument]
 * - version <module>   : print installed version of a module (empty if not installed)
 * - theme <theme>      : use theme on default blog
 * - posturl            : print URL of last published post of default blog
 * - deprecated         : print deprecated logs (one per line, traces separated by " | ")
 */
declare(strict_types=1);

[$self, $database, $command, $argument] = $argv + ['', '', '', ''];

$db = new PDO('sqlite:' . $database);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

switch ($command) {
    case 'version':
        $sth = $db->prepare('SELECT version FROM dc_version WHERE module = ?');
        $sth->execute([$argument]);
        echo (string) $sth->fetchColumn();

        break;

    case 'theme':
        $db->prepare("DELETE FROM dc_setting WHERE setting_ns = 'system' AND setting_id = 'theme' AND blog_id = 'default'")->execute();
        $db->prepare("INSERT INTO dc_setting (setting_id, blog_id, setting_ns, setting_value, setting_type, setting_label) VALUES ('theme', 'default', 'system', ?, 'string', 'Blog theme')")->execute([$argument]);

        break;

    case 'posturl':
        echo (string) $db->query("SELECT post_url FROM dc_post WHERE blog_id = 'default' AND post_status = 1 AND post_type = 'post' ORDER BY post_dt DESC LIMIT 1")->fetchColumn();

        break;

    case 'deprecated':
        foreach ($db->query("SELECT log_msg FROM dc_log WHERE log_table = 'deprecated' ORDER BY log_id") ?: [] as $row) {
            echo str_replace(["\n", "\r"], ' | ', (string) $row['log_msg']) . "\n";
        }

        break;

    default:
        fwrite(STDERR, "Unknown command $command\n");
        exit(1);
}
