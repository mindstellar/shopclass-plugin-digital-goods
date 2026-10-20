<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2013 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

/**
 * Settings, install and uninstall.
 */
class Plugin
{
    public const PREF_SECTION = 'digital_goods';
    /** The Settings group the screens sit in: Shop on a shop site, Content on a classifieds one. */
    public static function settingsGroup(): string
    {
        return function_exists('osc_site_mode') && osc_site_mode() === 'shop' ? 'shop' : 'content';
    }

    public const DEFAULT_EXTENSIONS = 'zip,7z,gz,pdf,epub';
    public const DEFAULT_MAX_FILES = 3;
    public const DEFAULT_MAX_MB = 32;
    public const SHORT_NAME = 'digital-goods';

    /**
     * The ids of the categories the plugin is switched on for.
     *
     * @return int[]
     */
    public static function categoryIds()
    {
        $dao = method_exists('PluginCategory', 'getInstance')
            ? \PluginCategory::getInstance()
            : \PluginCategory::newInstance();

        return array_map('intval', (array)$dao->listSelected(self::SHORT_NAME));
    }

    /**
     * How many files one listing may carry.
     *
     * @return int
     */
    public static function maxFiles()
    {
        $value = (int)osc_get_preference('max_files', self::PREF_SECTION);

        return max(1, min(20, $value ?: self::DEFAULT_MAX_FILES));
    }

    /**
     * The per-file ceiling, in bytes.
     *
     * Bounded by what PHP will actually accept as well as by the setting: a limit above
     * upload_max_filesize is a promise the server will not keep, and the upload fails
     * with nothing useful said about why.
     *
     * @return int
     */
    public static function maxSizeBytes()
    {
        $configured = (int)osc_get_preference('max_mb', self::PREF_SECTION);
        $configured = max(1, min(2048, $configured ?: self::DEFAULT_MAX_MB)) * 1024 * 1024;

        $phpLimit = self::iniBytes(ini_get('upload_max_filesize'));
        $postCap  = self::iniBytes(ini_get('post_max_size'));
        foreach (array($phpLimit, $postCap) as $limit) {
            if ($limit > 0 && $limit < $configured) {
                $configured = $limit;
            }
        }

        return $configured;
    }

    /**
     * Read a php.ini shorthand size ("8M", "512K") as bytes.
     *
     * @param string $value
     *
     * @return int
     */
    public static function iniBytes($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int)$value;

        switch ($unit) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * @return void
     */
    public static function install()
    {
        Files::install();

        osc_set_preference('max_files', (string)self::DEFAULT_MAX_FILES, self::PREF_SECTION, 'INTEGER');
        osc_set_preference('max_mb', (string)self::DEFAULT_MAX_MB, self::PREF_SECTION, 'INTEGER');
        osc_set_preference('allowed_ext', self::DEFAULT_EXTENSIONS, self::PREF_SECTION, 'STRING');
        osc_set_preference('access', Access::REGISTERED, self::PREF_SECTION, 'STRING');
        // Off by default: switching a plugin on should not start charging for something
        // that was free a moment earlier.
        osc_set_preference('require_purchase', '0', self::PREF_SECTION, 'INTEGER');
        osc_set_preference('price_credits', '5', self::PREF_SECTION, 'INTEGER');
        osc_set_preference('layout', (string)Storage::LAYOUT, self::PREF_SECTION, 'INTEGER');
        osc_reset_preferences();
    }

    /**
     * Bring stored files up to the current layout. Runs on init; a no-op once done.
     *
     * A run that could not move every file leaves the setting alone, so the next request
     * tries again.
     *
     * @return void
     */
    public static function upgrade()
    {
        if ((int)osc_get_preference('layout', self::PREF_SECTION) >= Storage::LAYOUT) {
            return;
        }

        if (Storage::migrate()) {
            osc_set_preference('layout', (string)Storage::LAYOUT, self::PREF_SECTION, 'INTEGER');
            osc_reset_preferences();
        }
    }

    /**
     * Remove the stored files before the rows that name them — the rows are the only
     * record of where the files are, so dropping the table first would strand every one
     * of them in the bucket.
     *
     * @return void
     */
    public static function uninstall()
    {
        $rows = osc_db_table(Files::table())->select('s_key')->get();
        foreach ($rows as $row) {
            if (isset($row['s_key'])) {
                Storage::delete((string)$row['s_key']);
            }
        }

        Storage::removeFolders();
        Files::uninstall();

        // The whole section, not a list of the keys this version happens to know about: a
        // setting dropped by some later version would otherwise be left behind for good,
        // with nothing that ever looks at it again.
        \Preference::newInstance()->delete(array('s_section' => self::PREF_SECTION));
        osc_reset_preferences();
    }
}
