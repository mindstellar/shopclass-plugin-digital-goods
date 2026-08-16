<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2013 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

use Params;

/**
 * Settings, install and uninstall.
 */
class Plugin
{
    public const PREF_SECTION = 'digital_goods';
    public const DEFAULT_EXTENSIONS = 'zip,7z,gz,pdf,epub';
    public const DEFAULT_MAX_FILES = 3;
    public const DEFAULT_MAX_MB = 32;

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
        osc_reset_preferences();
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

        Files::uninstall();

        foreach (array('max_files', 'max_mb', 'allowed_ext', 'access', 'require_purchase', 'price_credits') as $key) {
            \Preference::newInstance()->delete(
                array('s_section' => self::PREF_SECTION, 's_name' => $key)
            );
        }
        osc_reset_preferences();
    }

    /**
     * Persist the settings. Runs on init_admin, before the panel prints anything, so the
     * redirect after saving is still possible.
     *
     * @return void
     */
    public static function handleAdminPost()
    {
        if (Params::getParamString('dg_action') !== 'save') {
            return;
        }

        osc_csrf_check();

        $access = Params::getParamString('access');
        if (!in_array($access, Access::rules(), true)) {
            $access = Access::REGISTERED;
        }

        $extensions = array();
        foreach (explode(',', Params::getParamString('allowed_ext')) as $ext) {
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower(trim($ext)));
            // Only extensions this plugin knows a content type for: one it cannot check
            // the bytes against would be accepted on the strength of its name alone.
            if ($ext !== '' && isset(Uploads::typeMap()[$ext])) {
                $extensions[$ext] = true;
            }
        }
        if ($extensions === array()) {
            $extensions = array_flip(explode(',', self::DEFAULT_EXTENSIONS));
        }

        osc_set_preference('max_files', (string)max(1, min(20, Params::getParamInt('max_files'))), self::PREF_SECTION, 'INTEGER');
        osc_set_preference('max_mb', (string)max(1, min(2048, Params::getParamInt('max_mb'))), self::PREF_SECTION, 'INTEGER');
        osc_set_preference('allowed_ext', implode(',', array_keys($extensions)), self::PREF_SECTION, 'STRING');
        osc_set_preference('access', $access, self::PREF_SECTION, 'STRING');

        // Only writable where billing exists; otherwise the stored value stays 0 and the
        // charge can never be switched on by editing the form.
        if (Billing::available()) {
            osc_set_preference(
                'require_purchase',
                Params::getParam('require_purchase') !== '' ? '1' : '0',
                self::PREF_SECTION,
                'INTEGER'
            );
            osc_set_preference(
                'price_credits',
                (string)max(1, Params::getParamInt('price_credits') ?: 5),
                self::PREF_SECTION,
                'INTEGER'
            );
        }
        osc_reset_preferences();

        osc_add_flash_ok_message(__('Settings saved', 'digital-goods'), 'admin');
        osc_redirect_to(osc_admin_render_plugin_url(osc_plugin_folder(DG_PLUGIN_FILE) . 'admin/settings.php'));
    }
}
