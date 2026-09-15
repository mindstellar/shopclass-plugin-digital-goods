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

/**
 * Accepting a file from a seller.
 *
 * The original decided whether an upload was allowed by looking at `$_FILES[…]['type']`,
 * which is a header the uploading client writes and can say anything. A PHP script
 * announced as `application/zip` passed, and was then written under its own name into a
 * directory the web server executes. That is the whole path from "attach a file to your
 * ad" to running code on the host.
 *
 * So the type is read from the bytes with fileinfo, the extension has to be one the admin
 * listed, and the two have to agree. The name the seller chose is kept only as a label.
 */
class Uploads
{
    /**
     * Extensions the admin allows, normalised.
     *
     * @return string[]
     */
    public static function allowedExtensions()
    {
        $raw = (string)osc_get_preference('allowed_ext', Plugin::PREF_SECTION);
        if (trim($raw) === '') {
            $raw = Plugin::DEFAULT_EXTENSIONS;
        }

        $out = array();
        foreach (explode(',', $raw) as $ext) {
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower(trim($ext)));
            if ($ext !== '') {
                $out[$ext] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Content types accepted for a given extension.
     *
     * Deliberately narrow and local to this plugin rather than read from core's mime map:
     * the question here is not "what might this extension be" but "what may be sold as a
     * download", and an entry that maps an extension to something the browser will run is
     * not wanted whatever core says about it.
     *
     * @return array<string,string[]>
     */
    public static function typeMap()
    {
        return array(
            'zip' => array('application/zip', 'application/x-zip-compressed', 'multipart/x-zip'),
            '7z'  => array('application/x-7z-compressed'),
            'gz'  => array('application/gzip', 'application/x-gzip'),
            'tgz' => array('application/gzip', 'application/x-gzip'),
            'rar' => array('application/vnd.rar', 'application/x-rar-compressed'),
            'pdf' => array('application/pdf'),
            'epub' => array('application/epub+zip'),
            'mp3' => array('audio/mpeg'),
            'wav' => array('audio/x-wav', 'audio/wav'),
            'mp4' => array('video/mp4'),
            'png' => array('image/png'),
            'jpg' => array('image/jpeg'),
            'svg' => array('image/svg+xml'),
            'txt' => array('text/plain'),
            'csv' => array('text/csv', 'text/plain'),
        );
    }

    /**
     * Check one uploaded file.
     *
     * @param string $tmpPath      the temporary path PHP wrote
     * @param string $originalName the name the browser sent
     * @param int    $size
     *
     * @return array{ok:bool,error:string,extension:string,type:string,name:string}
     */
    public static function inspect($tmpPath, $originalName, $size)
    {
        $fail = static fn ($message) => array(
            'ok' => false, 'error' => $message, 'extension' => '', 'type' => '', 'name' => '',
        );

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return $fail(__('The upload did not arrive intact.', 'digital-goods'));
        }

        $maxBytes = Plugin::maxSizeBytes();
        if ($size <= 0 || $size > $maxBytes) {
            return $fail(sprintf(
                __('Files must be %s or smaller.', 'digital-goods'),
                self::formatSize($maxBytes)
            ));
        }

        // The extension of the submitted name is a claim, checked against the allowlist —
        // it never reaches the filesystem.
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)));
        $allowed   = self::allowedExtensions();
        if ($extension === '' || !in_array($extension, $allowed, true)) {
            return $fail(sprintf(
                __('Only these file types are accepted: %s', 'digital-goods'),
                implode(', ', $allowed)
            ));
        }

        // What the file actually is.
        $type = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $type = (string)finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }
        if ($type === '') {
            return $fail(__('The file type could not be determined.', 'digital-goods'));
        }

        $map = self::typeMap();
        if (!isset($map[$extension]) || !in_array($type, $map[$extension], true)) {
            return $fail(sprintf(
                __('That file does not look like a %s file.', 'digital-goods'),
                strtoupper($extension)
            ));
        }

        return array(
            'ok'        => true,
            'error'     => '',
            'extension' => $extension,
            'type'      => $type,
            // Kept for display only. Stripped of any path so a name like
            // "../../evil" cannot be shown as, or mistaken for, a location.
            'name'      => self::safeLabel($originalName),
        );
    }

    /**
     * A byte count in units a person reads. Core has no equivalent helper.
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function formatSize($bytes)
    {
        $bytes = (int)$bytes;
        $units = array('B', 'KB', 'MB', 'GB');
        $unit  = 0;
        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return ($bytes >= 10 || $unit === 0)
            ? round($bytes) . ' ' . $units[$unit]
            : round($bytes, 1) . ' ' . $units[$unit];
    }

    /**
     * The submitted name reduced to something safe to store and display.
     *
     * @param string $name
     *
     * @return string
     */
    public static function safeLabel($name)
    {
        $name = basename(str_replace('\\', '/', (string)$name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        if ($name === '') {
            $name = 'download';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, 190) : substr($name, 0, 190);
    }
}
