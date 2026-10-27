<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Switching the plugin on ticks the shop's Digital Goods category when there is one and
 * nothing is chosen yet.
 *
 * Usage:  php tests/install.php
 */

declare(strict_types=1);

use mindstellar\digitalgoods\Plugin;

define('ABS_PATH', dirname(__DIR__) . '/');
require_once ABS_PATH . 'src/Plugin.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['dgi'] = array('selected' => array(), 'category' => array(), 'added' => array());

final class PluginCategory
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function listSelected($plugin): array
    {
        return $GLOBALS['dgi']['selected'];
    }
}

final class Category
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function findBySlug($slug)
    {
        return $slug === 'digital-goods' ? $GLOBALS['dgi']['category'] : array();
    }
}

final class Plugins
{
    public static function addToCategoryPlugin($ids, $name): void
    {
        $GLOBALS['dgi']['added'][] = array($ids, $name);
    }
}

$run = static function (array $selected, $category): array {
    $GLOBALS['dgi'] = array('selected' => $selected, 'category' => $category, 'added' => array());
    Plugin::tickDefaultCategory();

    return $GLOBALS['dgi']['added'];
};

harness_section('the default category');
pin('ticks the Digital Goods category', array(array(array(9), 'digital-goods')), $run(array(), array('pk_i_id' => '9')));
pin('leaves a site without it alone', array(), $run(array(), array()));
pin('leaves an earlier choice alone', array(), $run(array('3'), array('pk_i_id' => '9')));

exit(harness_result());
