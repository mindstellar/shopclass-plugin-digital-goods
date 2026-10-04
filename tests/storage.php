<?php
/*
 * This file is part of the Digital Goods plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Where files are kept: new local files go to the private folder, never under uploads,
 * and files left in uploads by older releases are moved there and the old folder closed.
 *
 * Usage:  php tests/storage.php
 */

namespace mindstellar\storage {
    class StorageManager
    {
        /** @var object|null */
        public static $remote = null;

        public static function instance(): self
        {
            return new self();
        }

        public function remote()
        {
            return self::$remote;
        }
    }

    class FakeBucket
    {
        public $public;
        public $objects = array();

        public function __construct(bool $public)
        {
            $this->public = $public;
        }

        public function put(string $localPath, string $key, string $contentType): bool
        {
            $this->objects[$key] = file_get_contents($localPath);

            return true;
        }

        public function get(string $key)
        {
            return $this->objects[$key] ?? false;
        }

        public function delete(string $key): bool
        {
            $had = isset($this->objects[$key]);
            unset($this->objects[$key]);

            return $had;
        }

        public function isPublic(): bool
        {
            return $this->public;
        }

        public function presignedUrl(string $key): string
        {
            return 'https://bucket.test/' . $key . '?sig=1';
        }

        public function downloadUrl(string $key, int $ttl, string $filename): string
        {
            return 'https://bucket.test/' . $key . '?sig=1&ttl=' . $ttl . '&name=' . rawurlencode($filename);
        }
    }
}

namespace {
    use mindstellar\digitalgoods\Storage;
    use mindstellar\storage\FakeBucket;
    use mindstellar\storage\StorageManager;

    define('ABS_PATH', dirname(__DIR__) . '/');
    $root = sys_get_temp_dir() . '/dg_storage_' . getmypid();
    define('CONTENT_PATH', $root . '/oc-content/');
    define('UPLOADS_PATH', CONTENT_PATH . 'uploads/');
    mkdir(UPLOADS_PATH, 0755, true);

    require_once ABS_PATH . 'src/Storage.php';
    require_once __DIR__ . '/lib/harness.php';

    $src = tempnam(sys_get_temp_dir(), 'dg_src_');
    file_put_contents($src, 'PAID CONTENT');

    harness_section('keys');

    $key = Storage::newKey(7, 'zip');
    check('a new key has the expected shape', Storage::isKey($key), $key);
    pin('a key cannot climb out of the folder', false, Storage::isKey('digital-goods/7/../../../config.php'));
    pin('nor carry a trailing newline', false, Storage::isKey($key . "\n"));
    pin('nor name another folder', false, Storage::isKey('backups/' . basename($key)));

    harness_section('local storage writes outside the uploads folder');

    check('put succeeds', Storage::put($src, $key, 'application/zip'));
    pin('the file is in the private folder', CONTENT_PATH . 'downloads/' . $key, Storage::localPath($key));
    check('nothing is written under uploads', !is_dir(UPLOADS_PATH . 'digital-goods'));
    check('the private folder carries a deny-all .htaccess',
        strpos((string)@file_get_contents(CONTENT_PATH . 'downloads/digital-goods/.htaccess'), 'Require all denied') !== false);
    check('and an empty index.php', is_file(CONTENT_PATH . 'downloads/digital-goods/index.php'));
    pin('a bad key is refused', false, Storage::put($src, '../evil.php', 'text/plain'));
    pin('local storage counts as private', true, Storage::isPrivate());
    pin('and hands out no signed URL', '', Storage::signedUrl($key, 'a.zip'));

    check('delete removes the file', Storage::delete($key));
    pin('it is gone', '', Storage::localPath($key));
    check('and so is the listing folder, now empty', !is_dir(CONTENT_PATH . 'downloads/digital-goods/7'));

    harness_section('migration from the uploads folder');

    $oldKeys = array(Storage::newKey(1, 'pdf'), Storage::newKey(1, 'zip'), Storage::newKey(2, ''));
    foreach ($oldKeys as $k) {
        @mkdir(dirname(UPLOADS_PATH . $k), 0755, true);
        file_put_contents(UPLOADS_PATH . $k, 'old ' . $k);
    }
    file_put_contents(UPLOADS_PATH . 'digital-goods/1/notes.txt', 'not ours');

    pin('an unmoved file is still found, in the old folder', UPLOADS_PATH . $oldKeys[0], Storage::localPath($oldKeys[0]));

    pin('migrate reports everything moved', true, Storage::migrate());
    foreach ($oldKeys as $i => $k) {
        pin("file $i is in the private folder", CONTENT_PATH . 'downloads/' . $k, Storage::localPath($k));
        pin("file $i kept its bytes", 'old ' . $k, file_get_contents(CONTENT_PATH . 'downloads/' . $k));
        check("file $i is gone from uploads", !file_exists(UPLOADS_PATH . $k));
    }
    check('a file that is not a stored key is left alone', is_file(UPLOADS_PATH . 'digital-goods/1/notes.txt'));
    check('an emptied listing folder is removed', !is_dir(UPLOADS_PATH . 'digital-goods/2'));
    check('the old folder is closed with a deny-all .htaccess',
        strpos((string)@file_get_contents(UPLOADS_PATH . 'digital-goods/.htaccess'), 'Require all denied') !== false);

    pin('a second run changes nothing and still succeeds', true, Storage::migrate());
    pin('the files are where the first run put them', CONTENT_PATH . 'downloads/' . $oldKeys[1], Storage::localPath($oldKeys[1]));

    // An overlapping request copied the file already but had not removed the old one.
    $dup = Storage::newKey(3, 'zip');
    foreach (array(UPLOADS_PATH, CONTENT_PATH . 'downloads/') as $base) {
        @mkdir(dirname($base . $dup), 0755, true);
        file_put_contents($base . $dup, 'same');
    }
    pin('a file already moved is not moved twice', true, Storage::migrate());
    check('its leftover in uploads is removed', !file_exists(UPLOADS_PATH . $dup));
    pin('the moved copy is intact', 'same', file_get_contents(CONTENT_PATH . 'downloads/' . $dup));

    harness_section('remote storage');

    StorageManager::$remote = $private = new FakeBucket(false);
    $rk = Storage::newKey(9, 'pdf');
    check('put goes to the bucket', Storage::put($src, $rk, 'application/pdf') && isset($private->objects[$rk]));
    pin('nothing is written to disk', '', Storage::localPath($rk));
    pin('a private bucket counts as private', true, Storage::isPrivate());
    check('a private bucket hands out a signed URL carrying the name',
        strpos(Storage::signedUrl($rk, 'Guide.pdf'), 'name=Guide.pdf') !== false);
    pin('the signed URL lasts five minutes', true, strpos(Storage::signedUrl($rk, 'x'), 'ttl=300') !== false);

    StorageManager::$remote = $public = new FakeBucket(true);
    $public->objects[$rk] = 'BYTES';
    pin('a public bucket does not count as private', false, Storage::isPrivate());
    pin('and never gets a signed URL, which would show the key', '', Storage::signedUrl($rk, 'Guide.pdf'));
    pin('its bytes are read through the adapter', 'BYTES', Storage::readRemote($rk));
    check('delete reaches the bucket', Storage::delete($rk) && !isset($public->objects[$rk]));

    harness_section('DG_PRIVATE_PATH and uninstall');

    StorageManager::$remote = null;
    Storage::delete($oldKeys[0]);
    Storage::delete($oldKeys[1]);
    Storage::delete($oldKeys[2]);
    Storage::delete($dup);
    unlink(UPLOADS_PATH . 'digital-goods/1/notes.txt');
    Storage::removeFolders();
    check('uninstall removes the private folder', !is_dir(CONTENT_PATH . 'downloads/digital-goods'));
    check('and the old one', !is_dir(UPLOADS_PATH . 'digital-goods'));

    $outside = $root . '/private-data';
    define('DG_PRIVATE_PATH', $outside);
    pin('DG_PRIVATE_PATH moves the private root', $outside . '/', Storage::privateRoot());
    $ok = Storage::newKey(4, 'zip');
    Storage::put($src, $ok, 'application/zip');
    pin('and new files land there', $outside . '/' . $ok, Storage::localPath($ok));

    exec('rm -rf ' . escapeshellarg($root));
    unlink($src);

    exit(harness_result());
}
