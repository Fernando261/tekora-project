<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class WithoutExternalContact implements ValidationRule
{
    private const MESSAGE = 'Por seguridad, no compartas números de teléfono, correos electrónicos ni enlaces de contacto. Mantén la conversación dentro de Tekora.';

    private const EMAIL = '~[\p{L}\p{N}][\p{L}\p{N}._%+\x27!-]*\h*@\h*(?:[\p{L}\p{N}][\p{L}\p{N}-]*\h*\.\h*)+[\p{L}]{2,63}\b~iu';

    private const SPOKEN_EMAIL = '~[\p{L}\p{N}][\p{L}\p{N}._%+-]*\h+arroba\h+[\p{L}\p{N}-]{2,}\h+punto\h+(?:com|mx|net|org|edu|io|info|es)\b~iu';

    private const URL_PREFIX = '(?<![\p{L}\p{N}_.@-])(?:https?://)?(?<host>(?:[a-z0-9-]+\.)+[a-z]{2,})(?::[0-9]{1,5})?/';

    private const PHONE_SEPARATOR = '(?:[ -]+| *[./] *)';

    // The distinct groupings avoid joining unrelated numbers across words, lines, dates or units.
    private const MEXICAN_NUMBER = '[2-9][0-9]{9}'
        .'|(?:\([2-9][0-9]\)'.self::PHONE_SEPARATOR.'?|[2-9][0-9]'.self::PHONE_SEPARATOR.')[0-9]{4}'.self::PHONE_SEPARATOR.'?[0-9]{4}'
        .'|(?:\([2-9][0-9]{2}\)'.self::PHONE_SEPARATOR.'?|[2-9][0-9]{2}'.self::PHONE_SEPARATOR.')[0-9]{3}'.self::PHONE_SEPARATOR.'?[0-9]{4}'
        .'|[2-9][0-9](?:'.self::PHONE_SEPARATOR.'[0-9]{2}){4}';

    private const IDENTIFIER_PREFIX = '\b(?:modelo|serie|sku|folio|referencia|pedido|c[oó]digo de seguimiento)(?:\h*[:#]\h*|\h+(?:es\h+)?)';

    private const CURRENCY_PREFIX = '(?:\p{Sc}\h*|\b(?:MXN|USD|EUR)\h+)';

    private const CONTACT_PREFIX = '\b(?:(?:tel(?:[eé]fono)?|cel(?:ular)?|m[oó]vil|whatsapp)(?:\h+de contacto)?\h*[:=]\h*(?:'.self::IDENTIFIER_PREFIX.')?'
        .'|(?:(?:mi|nuestro|su|tu|el)\h+(?:tel[eé]fono|celular|m[oó]vil|whatsapp)\h+es|(?:celular|whatsapp))\h+'
        .'|(?:ll[aá]mame|cont[aá]ctame|escr[ií]beme)\h+(?:(?:al|a|en|por)\h+)?(?:'.self::IDENTIFIER_PREFIX.')?)';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The existing string and max rules handle these cases.
        if (! is_string($value) || mb_strlen($value, 'UTF-8') > 2000) {
            return;
        }

        $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u',
            static fn (array $match): string => chr(mb_ord($match[0], 'UTF-8') - 0xFEE0), $value) ?? $value;
        $text = preg_replace('/\p{Cf}+/u', '', $text) ?? $text;
        $text = preg_replace('/\h+/u', ' ', $text) ?? $text;
        $text = strtr($text, ['‐' => '-', '‑' => '-', '‒' => '-', '–' => '-', '—' => '-', '−' => '-']);
        // Only interpret marked obfuscations as address punctuation.
        $addresses = preg_replace('/\h*[\[(]\h*(at|arroba)\h*[\])]\h*/iu', '@', $text) ?? $text;
        $addresses = preg_replace('/\h*[\[(]\h*(dot|punto)\h*[\])]\h*/iu', '.', $addresses) ?? $addresses;

        if (preg_match(self::EMAIL, $addresses) || preg_match(self::SPOKEN_EMAIL, $addresses) || $this->hasContactLink($addresses) || $this->hasPhone($text)) {
            $fail(self::MESSAGE);
        }
    }

    private function hasPhone(string $text): bool
    {
        $boundary = '(?<![\p{L}\p{N}_-])';
        $end = '(?![\p{L}\p{N}_-])';
        $national = '(?:'.self::MEXICAN_NUMBER.')';

        if (preg_match('~\b(?:tel|callto):\h*\+?[0-9(][0-9 ()./-]{5,}[0-9]~iu', $text)) {
            return true;
        }

        if (preg_match("~{$boundary}\+[1-9][0-9]{9,14}{$end}~u", $text)) {
            return true;
        }
        $international = '[1-9][0-9]{0,2}[ -]+(?:\([0-9]{1,4}\)[ -]*|[0-9]{1,4}[ -]+)[0-9]{2,4}(?:[ -]+[0-9]{2,4}){1,3}';
        preg_match_all("~{$boundary}\+{$international}{$end}~u", $text, $internationalNumbers);
        foreach ($internationalNumbers[0] as $number) {
            $length = strlen(preg_replace('/[^0-9]/', '', $number));
            if ($length >= 10 && $length <= 15) {
                return true;
            }
        }

        $separator = self::PHONE_SEPARATOR;
        preg_match_all("~{$boundary}(?:\+?52{$separator}?{$national}|{$national}){$end}~u", $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$number, $offset]) {
            if (! ctype_digit($number) || ! $this->isLabelledNonContactNumber($text, $number, $offset)) {
                return true;
            }
        }

        return false;
    }

    private function isLabelledNonContactNumber(string $text, string $number, int $offset): bool
    {
        $prefix = substr($text, 0, $offset);
        $suffix = substr($text, $offset + strlen($number));
        $contact = self::CONTACT_PREFIX;
        $identifier = self::IDENTIFIER_PREFIX;
        $currency = self::CURRENCY_PREFIX;

        if (preg_match("~{$contact}(?:{$currency})?\z~iu", $prefix)) {
            return false;
        }

        if (preg_match('~'.self::URL_PREFIX.'[^\s<>"\x27]*\z~iu', $prefix, $link, PREG_OFFSET_CAPTURE)) {
            [$url, $start] = $link[0];

            // Only a final numeric segment on a technical route is treated as an identifier.
            return ! preg_match("~{$contact}\z~iu", substr($prefix, 0, $start))
                && preg_match('~^https?://[^?#]+/(?:piezas|parts|series?|serial|sku|modelos?|folio|seguimiento|tracking)/\z~iu', $url)
                && preg_match('~^/?(?=\z|[\s<>"\x27,;!)]|\.(?=\z|\s))~u', $suffix);
        }

        // A bare 52 prefix can be part of an explicitly labelled identifier.
        return preg_match("~(?:{$identifier}|{$currency})\z~iu", $prefix)
            || preg_match('~^\h*(?:MXN|USD|EUR)\b~iu', $suffix)
            || (strlen($number) === 10 && preg_match('~^\h*(?:mm|cm|km|mAh|kWh|Hz|GB|TB|piezas|unidades)\b~iu', $suffix));
    }

    private function hasContactLink(string $text): bool
    {
        if (preg_match('~\b(?:tg|whatsapp|sgnl)://|\b(?:instagram|telegram|whatsapp|signal|discord)\h*[:=-]?\h*@[a-z0-9_.]{2,}~iu', $text)) {
            return true;
        }

        preg_match_all('~'.self::URL_PREFIX.'(?<path>[^\s<>"\x27]+)~iu', $text, $links, PREG_SET_ORDER);

        foreach ($links as $link) {
            $host = preg_replace('/^www\./', '', strtolower($link['host']));
            $path = $link['path'];
            $route = strtolower(ltrim(rawurldecode(substr($path, 0, strcspn($path, '?#'))), '/'));
            $first = explode('/', $route, 2)[0];

            $contact = match ($host) {
                'wa.me', 'chat.whatsapp.com', 't.me', 'telegram.me', 'telegram.dog',
                'm.me', 'signal.me', 'signal.group', 'discord.gg' => true,
                'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com' => in_array($first, ['send', 'message'], true),
                'instagram.com', 'm.instagram.com' => $this->isProfile($first, ['accounts', 'about', 'developer', 'explore', 'p', 'reel', 'reels', 'tv']),
                'facebook.com', 'm.facebook.com' => rtrim($route, '/.') !== 'privacy/policy'
                    && $this->isProfile($first, ['about', 'business', 'groups', 'help', 'marketplace', 'reel', 'share', 'sharer', 'watch']),
                'ig.me' => str_starts_with($route, 'm/'),
                'messenger.com' => str_starts_with($route, 't/'),
                'discord.com' => str_starts_with($route, 'users/') || str_starts_with($route, 'invite/') || str_starts_with($route, 'channels/'),
                'linkedin.com' => str_starts_with($route, 'in/'),
                'twitter.com', 'x.com' => ! str_contains($route, '/status/') && $this->isProfile($first, ['about', 'explore', 'help', 'home', 'i', 'intent', 'search', 'share']),
                default => false,
            };

            if ($contact) {
                return true;
            }
        }

        return false;
    }

    private function isProfile(string $segment, array $publicSections): bool
    {
        return ! in_array($segment, $publicSections, true) && preg_match('/^[a-z0-9_.-]+$/D', $segment) === 1;
    }
}
