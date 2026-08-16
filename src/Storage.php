<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\digitalgoods;

use mindstellar\storage\StorageManager;

/**
 * Where an attached file is kept, and how it is handed to a buyer.
 *
 * The original wrote uploads into oc-content/uploads/digitalgoods/ under a name built
 * from the uploader's own filename. Three things followed from that, and all three are
 * why this class exists:
 *
 *  - the directory is served by the web server, so the paid file was fetchable directly
 *    by URL — the download script, its access check and its counter were all optional;
 *  - the name came from the request, so an upload called `x.php` was written as `x.php`
 *    into a directory the server executes;
 *  - the download script then built the path it read from the query string, so the bytes
 *    served were chosen by the caller rather than by the database row.
 *
 * Here the bytes go to core's storage layer under a random key, and the name the seller
 * chose survives only as a label in the database. Nothing about the stored location is
 * derived from anything a caller sends.
 *
 * Delivery depends on what the site has configured, which the storage layer already
 * models:
 *
 *   remote and private  the adapter issues a short-lived signed URL and the buyer is
 *                       redirected to it — the bytes never pass through PHP.
 *   anything else       the file is streamed by the download route, which is also the
 *                       only thing that can apply the access rule.
 */
class Storage
{
    /** Key prefix inside whichever adapter is in use. */
    public const PREFIX = 'digital-goods/';

    /**
     * The adapter to use: a remote one when the site has configured it, otherwise local.
     *
     * @return \mindstellar\storage\StorageAdapter|null
     */
    public static function adapter()
    {
        $manager = StorageManager::instance();

        return $manager->remote() ?: $manager->adapter('local');
    }

    /**
     * Whether the configured destination keeps the file out of public reach on its own.
     *
     * Only a remote adapter serving signed URLs does. Local storage lives under the
     * uploads directory and reports itself public, which is the case the warning on the
     * settings screen is about.
     *
     * @return bool
     */
    public static function isPrivate()
    {
        $adapter = self::adapter();

        return $adapter !== null && $adapter->isRemote() && !$adapter->isPublic();
    }

    /**
     * A storage key for a new upload.
     *
     * Random rather than derived: the extension is kept only because some object stores
     * infer a content type from it, and it is taken from the allowlist the upload was
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
     * Move an uploaded file into storage.
     *
     * @param string $localPath   the temporary upload path
     * @param string $key
     * @param string $contentType as determined by inspecting the file, not by the request
     *
     * @return bool
     */
    public static function put($localPath, $key, $contentType)
    {
        $adapter = self::adapter();

        return $adapter !== null && $adapter->put($localPath, $key, $contentType);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public static function delete($key)
    {
        $adapter = self::adapter();

        return $adapter !== null && $adapter->delete($key);
    }

    /**
     * A signed URL for a private remote object, or '' when that is not how this site
     * stores files.
     *
     * @param string $key
     *
     * @return string
     */
    public static function signedUrl($key)
    {
        $adapter = self::adapter();
        if ($adapter === null || !method_exists($adapter, 'presignedUrl')) {
            return '';
        }

        return (string)$adapter->presignedUrl($key);
    }

    /**
     * The file's bytes, for the streaming path.
     *
     * @param string $key
     *
     * @return string|false
     */
    public static function read($key)
    {
        $adapter = self::adapter();

        return $adapter === null ? false : $adapter->get($key);
    }
}
