<?php
/* Swarm View – translation
 *
 * The source language is English. Other languages live in lang/<code>.json as
 * { "English text": "translation" }; placeholders are {0}, {1}, …
 * Language: plugin setting LANGUAGE (auto | en | de); "auto" follows Unraid's display language.
 */

/** Unraid's display language as a two-letter code ('' = English / unknown) */
function sv_unraid_lang(): string {
    $file = getenv('SV_DYNAMIX_CFG') ?: '/boot/config/plugins/dynamix/dynamix.cfg';
    $ini = @parse_ini_file($file, true);
    $loc = (string)($ini['display']['locale'] ?? '');
    return strtolower(substr($loc, 0, 2));
}

/** Active language code */
function sv_lang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;
    $set = function_exists('sv_cfg') ? (sv_cfg()['LANGUAGE'] ?? 'auto') : 'auto';
    $code = $set === 'auto' ? sv_unraid_lang() : $set;
    $lang = ($code !== '' && $code !== 'en' && is_file(__DIR__ . "/../lang/$code.json")) ? $code : 'en';
    return $lang;
}

/** Dictionary of the active language (English → target) */
function sv_lang_dict(): array {
    static $dict = null;
    if ($dict !== null) return $dict;
    $lang = sv_lang();
    $dict = $lang === 'en' ? [] : (json_decode((string)@file_get_contents(__DIR__ . "/../lang/$lang.json"), true) ?: []);
    return $dict;
}

/** Translate: sv_t('{0} is back on {1}.', $svc, $node) */
function sv_t(string $en, ...$args): string {
    $s = sv_lang_dict()[$en] ?? $en;
    foreach ($args as $i => $v) $s = str_replace('{' . $i . '}', (string)$v, $s);
    return $s;
}

/** Translate and HTML-escape (for pages) */
function sv_e(string $en, ...$args): string {
    return htmlspecialchars(sv_t($en, ...$args), ENT_QUOTES);
}

/** Singular/plural: sv_tn($n, '{0} service', '{0} services') */
function sv_tn(int $n, string $one, string $many): string {
    return sv_t($n === 1 ? $one : $many, $n);
}

/** <script> for the pages: dictionary + language code for the JavaScript */
function sv_lang_script(): string {
    return '<script>window.SwarmViewLangCode=' . json_encode(sv_lang()) . ';window.SwarmViewLang='
        . json_encode((object)sv_lang_dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
}
