<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_recertify_upgrade($oldversion) {
    if ($oldversion < 2026073100) {
        upgrade_plugin_savepoint(true, 2026073100, 'local', 'recertify');
    }
    return true;
}
