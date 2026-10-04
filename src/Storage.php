<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

use mindstellar\storage\StorageManager;

/**
 * Where an attached file is kept.
 *
 * Every file is stored under a random key; the name the seller chose survives only as a
 * label in the database.
 *
 *   remote storage   the file goes to the bucket. A private bucket hands it over with a
 *                    short-lived signed URL; a public one is read and streamed by PHP.
 *   local storage    the file goes to a folder the web server does not serve,
 *                    oc-content/downloads/digital-goods/ (or DG_PRIVATE_PATH), and is
 *                    streamed by the download route, which applies the access rule.
 *
 * Releases before 2.1.0 kept local files in oc-content/uploads/digital-goods/, which the
 * web server serves. migrate() moves them; the key stays the same, only the folder changes.
 */
class Storage
{
    /** Key prefix inside whichever adapter or folder is in use. */
    public const PREFIX = 'digital-goods/';

    /** Bumped when stored files have to be moved; see migrate(). */
    public const LAYOUT = 2;

    /** How long a signed bucket URL stays valid, in seconds. */
    public const SIGNED_TTL = 300;

    /**
     * The remote adapter the site has made active, if any.
     *
     * @return \mindstellar\storage\StorageAdapter|null
     */
    public static function remote()
    {
        return StorageManager::instance()->remote();
    }

    /**
     * Whether new uploads stay out of public reach. Only a public bucket fails this: it
     * serves any key to anyone who has it.
     *
     * @return bool
     */
    public static function isPrivate()
    {
        $remote = self::remote();

        return $remote === null || !$remote->isPublic();
    }

    /**
     * The folder local files are kept in, with a trailing slash. Keys are relative to it.
     *
     * @return string
     */
    public static function privateRoot()
    {
        if (defined('DG_PRIVATE_PATH') && is_string(DG_PRIVATE_PATH) && DG_PRIVATE_PATH !== '') {
            return rtrim(DG_PRIVATE_PATH, '/\\') . '/';
        }

        return CONTENT_PATH . 'downloads/';
    }

    /**
     * The web-served folder releases before 2.1.0 used, with a trailing slash.
     *
     * @return string
     */
    public static function legacyRoot()
    {
        return UPLOADS_PATH;
    }

    /**
     * Whether a key has the shape newKey() makes. Anything else never becomes a path.
     *
     * @param string $key
     *
     * @return bool
     */
    public static function isKey($key)
    {
        return preg_match('#^digital-goods/\d+/[a-f0-9]{32}(\.[a-z0-9]+)?$#D', (string)$key) === 1;
    }

    /**
     * A storage key for a new upload.
     *
     * Random rather than derived: the extension comes from the allowlist the upload was
     * checked against, never from the submitted filename.
     *
     * @param int    $itemId
     * @param string $extension already validated against the allowlist
     *
     * @return string
     */
    public static function newKey($itemId, $extension)
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower((string)$extension));

        return self::PREFIX . (int)$itemId . '/' . bin2hex(random_bytes(16))
            . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * Store an uploaded file.
     *
     * @param string $localPath   the temporary upload path
     * @param string $key
     * @param string $contentType as determined by inspecting the file, not by the request
     *
     * @return bool
     */
    public static function put($localPath, $key, $contentType)
    {
        if (!self::isKey($key)) {
            return false;
        }

        $remote = self::remote();
        if ($remote !== null) {
            return $remote->put($localPath, $key, $contentType);
        }

        $target = self::privateRoot() . $key;
        if (!self::prepareDir(dirname($target))) {
            return false;
        }

        return @copy($localPath, $target);
    }

    /**
     * Remove a stored file from wherever it is.
     *
     * @param string $key
     *
     * @return bool whether anything was removed
     */
    public static function delete($key)
    {
        if (!self::isKey($key)) {
            return false;
        }

        $removed = false;
        foreach (array(self::privateRoot(), self::legacyRoot()) as $root) {
            $path = $root . $key;
            if (is_file($path) && !is_link($path) && @unlink($path)) {
                $removed = true;
                // Only succeeds once the listing's folder is empty.
                @rmdir(dirname($path));
            }
        }

        $remote = self::remote();
        if ($remote !== null && $remote->delete($key)) {
            $removed = true;
        }

        return $removed;
    }

    /**
     * The local path of a stored file, or '' when it is not on this disk.
     *
     * A file the migration has not moved yet is still found in the old folder, so its
     * download keeps working through the gate.
     *
     * @param string $key
     *
     * @return string
     */
    public static function localPath($key)
    {
        if (!self::isKey($key)) {
            return '';
        }

        foreach (array(self::privateRoot(), self::legacyRoot()) as $root) {
            $path = $root . $key;
            if (is_file($path) && !is_link($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * A signed URL for an object in a private bucket, or '' when files are not kept that way.
     *
     * @param string $key
     * @param string $filename the name the browser should save it under
     *
     * @return string
     */
    public static function signedUrl($key, $filename = '')
    {
        $remote = self::remote();
        if ($remote === null || $remote->isPublic()) {
            return '';
        }

        // downloadUrl() (Shopclass 6.4) also sets the saved name; presignedUrl() is older.
        if ($filename !== '' && method_exists($remote, 'downloadUrl')) {
            return (string)$remote->downloadUrl($key, self::SIGNED_TTL, $filename);
        }
        if (method_exists($remote, 'presignedUrl')) {
            return (string)$remote->presignedUrl($key);
        }

        return '';
    }

    /**
     * The bytes of a file in a public bucket. The adapter has no streaming read, so this
     * one path still holds the file in memory.
     *
     * @param string $key
     *
     * @return string|false
     */
    public static function readRemote($key)
    {
        $remote = self::remote();

        return $remote === null ? false : $remote->get($key);
    }

    /**
     * Move files left in the old web-served folder into the private one, then close the
     * old folder. Safe to run again and from overlapping requests.
     *
     * @return bool whether nothing is left to move
     */
    public static function migrate()
    {
        $oldBase = self::legacyRoot() . self::PREFIX;
        if (!is_dir($oldBase) || is_link(rtrim($oldBase, '/'))) {
            return true;
        }

        $done = true;
        foreach (glob($oldBase . '*', GLOB_ONLYDIR) ?: array() as $dir) {
            foreach (glob($dir . '/*') ?: array() as $old) {
                $key = self::PREFIX . basename($dir) . '/' . basename($old);
                if (!self::isKey($key) || !is_file($old) || is_link($old)) {
                    continue;
                }
                if (!self::moveOne($old, self::privateRoot() . $key)) {
                    $done = false;
                }
            }
            @rmdir($dir);
        }

        self::protect($oldBase);

        return $done;
    }

    /**
     * Move one file, falling back to copy-and-delete across filesystems.
     *
     * @param string $from
     * @param string $to
     *
     * @return bool
     */
    private static function moveOne($from, $to)
    {
        if (!self::prepareDir(dirname($to))) {
            return false;
        }

        // Moved already by an earlier or overlapping run: drop the copy left behind.
        if (is_file($to) && filesize($to) === filesize($from)) {
            return @unlink($from);
        }

        if (@rename($from, $to)) {
            return true;
        }

        $partial = $to . '.part';
        if (@copy($from, $partial) && filesize($partial) === filesize($from) && @rename($partial, $to)) {
            return @unlink($from);
        }
        @unlink($partial);

        return false;
    }

    /**
     * Make a folder under the private root, closed to the web.
     *
     * @param string $dir
     *
     * @return bool whether it exists and can be written
     */
    private static function prepareDir($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        self::protect(self::privateRoot() . self::PREFIX);

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Write an .htaccess that denies every request (Apache 2.4 and 2.2) and an empty
     * index.php. nginx needs its own rule; see the README. A file already there is kept.
     *
     * @param string $dir with a trailing slash
     *
     * @return void
     */
    public static function protect($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array(
            'index.php' => "<?php\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
        );
        foreach ($files as $name => $content) {
            if (!is_file($dir . $name)) {
                @file_put_contents($dir . $name, $content);
            }
        }
    }

    /**
     * Remove the plugin's folders once their files are gone. Only empty folders and the
     * two guard files protect() writes are removed.
     *
     * @return void
     */
    public static function removeFolders()
    {
        foreach (array(self::privateRoot(), self::legacyRoot()) as $root) {
            $base = $root . self::PREFIX;
            if (!is_dir($base) || is_link(rtrim($base, '/'))) {
                continue;
            }
            foreach (glob($base . '*', GLOB_ONLYDIR) ?: array() as $dir) {
                @rmdir($dir);
            }
            if (glob($base . '*', GLOB_ONLYDIR) === array()) {
                @unlink($base . 'index.php');
                @unlink($base . '.htaccess');
                @rmdir($base);
            }
        }
    }
}
