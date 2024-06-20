<?php

// This file is part of Bileto.
// Copyright 2022-2024 Probesys
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Utils;

class Url
{
    public static function sanitizeDomain(string $domain): string
    {
        // idn_to_ascii allows to transform an unicode domain to an
        // ASCII representation
        // @see https://en.wikipedia.org/wiki/Punycode
        // It also lowercases the string.
        $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if ($domain === false) {
            $domain = '';
        }

        $domain = trim($domain);

        return $domain;
    }
}
