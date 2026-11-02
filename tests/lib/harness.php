<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The pin/check micro-harness the core repository's tests use, trimmed to the four
 * functions these tests need. No dependency on a test framework, and none on Shopclass:
 * every test here runs from a bare `php tests/<file>.php`.
 */

$GLOBALS['okCount']    = $GLOBALS['okCount'] ?? 0;
$GLOBALS['failCount']  = $GLOBALS['failCount'] ?? 0;
$GLOBALS['failLabels'] = $GLOBALS['failLabels'] ?? array();

function describe($v): string
{
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return 'bool(' . ($v ? 'true' : 'false') . ')';
    }
    if (is_int($v)) {
        return 'int(' . $v . ')';
    }
    if (is_string($v)) {
        return 'string("' . $v . '")';
    }
    if (is_array($v)) {
        return 'array(' . count($v) . ') ' . json_encode($v);
    }

    return gettype($v);
}

function report(string $label, bool $ok, string $expected, string $actual): void
{
    if ($ok) {
        $GLOBALS['okCount']++;
        echo "PASS  $label\n";

        return;
    }

    $GLOBALS['failCount']++;
    $GLOBALS['failLabels'][] = $label;
    echo "FAIL  $label\n        expected: $expected\n        actual:   $actual\n";
}

function pin(string $label, $expected, $actual): void
{
    report($label, $expected === $actual, describe($expected), describe($actual));
}

function check(string $label, bool $ok, string $detail = ''): void
{
    report($label, $ok, 'true', $ok ? 'true' : ('false' . ($detail !== '' ? " ($detail)" : '')));
}

function harness_section(string $title): void
{
    echo "\n== $title ==\n";
}

function harness_result(): int
{
    echo "\n----------------------------------------\n";
    echo "RESULT: {$GLOBALS['okCount']} passed, {$GLOBALS['failCount']} failed\n";
    foreach ($GLOBALS['failLabels'] as $l) {
        echo "  FAILED: $l\n";
    }

    return $GLOBALS['failCount'] === 0 ? 0 : 1;
}

/**
 * Path of a core class file from its 7.0 folder path, such as 'billing/CartPricer.php'. Uses
 * oc-includes/classes when the file is there, else the 7.0 folder oc-includes/osclass/classes.
 */
function core_class_file(string $core, string $rel): string
{
    $top   = strtok($rel, '/');
    $group = array('category' => 'listing/category', 'fields' => 'listing/fields', 'moderation' => 'listing/moderation',
        'auth' => 'user/auth', 'apikey' => 'user/apikey', 'privacy' => 'user/privacy', 'currency' => 'billing/currency',
        'push' => 'messaging/push', 'live' => 'messaging/live', 'image' => 'media/image');
    foreach (array('pages', 'comment', 'widgets', 'menu', 'seo') as $name) {
        $group[$name] = 'content/' . $name;
    }
    foreach (explode(' ', 'boot container event http routing cli database migration cache job mail logger security storage settings install upgrade exception base utility form language theme site model') as $name) {
        $group[$name] = 'core/' . $name;
    }
    $new = rtrim($core, '/') . '/oc-includes/classes/' . ($group[$top] ?? $top) . substr($rel, strlen($top));

    return is_file($new) ? $new : rtrim($core, '/') . '/oc-includes/osclass/classes/' . $rel;
}

// The typed events are plain classes: load them from a core checkout (SHOPCLASS_CORE, else the sibling one).
spl_autoload_register(static function (string $class): void {
    $core = getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/shopclass';
    $path = core_class_file($core, str_replace('\\', '/', substr($class, 12)) . '.php');
    if (strncmp($class, 'mindstellar\\', 12) === 0 && strncmp($class, 'mindstellar\\digitalgoods\\', 24) !== 0 && is_file($path)) {
        require $path;
    }
});
