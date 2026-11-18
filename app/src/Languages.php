<?php // app/src/Languages.php
namespace App;

/** The story language choices: IETF-style codes the browse filter and the
 *  story form both accept, with each language's own name beside the English one. */
final class Languages
{
    public const DEFAULT = 'en';
    private const LIST = [
        'en' => 'English', 'es' => 'Spanish (Español)', 'fr' => 'French (Français)', 'de' => 'German (Deutsch)',
        'pt' => 'Portuguese (Português)', 'pt-BR' => 'Portuguese, Brazil (Português do Brasil)', 'it' => 'Italian (Italiano)',
        'nl' => 'Dutch (Nederlands)', 'sv' => 'Swedish (Svenska)', 'no' => 'Norwegian (Norsk)', 'da' => 'Danish (Dansk)',
        'fi' => 'Finnish (Suomi)', 'is' => 'Icelandic (Íslenska)', 'pl' => 'Polish (Polski)', 'cs' => 'Czech (Čeština)',
        'sk' => 'Slovak (Slovenčina)', 'hu' => 'Hungarian (Magyar)', 'ro' => 'Romanian (Română)', 'bg' => 'Bulgarian (Български)',
        'ru' => 'Russian (Русский)', 'uk' => 'Ukrainian (Українська)', 'el' => 'Greek (Ελληνικά)', 'tr' => 'Turkish (Türkçe)',
        'he' => 'Hebrew (עברית)', 'ar' => 'Arabic (العربية)', 'fa' => 'Persian (فارسی)', 'hi' => 'Hindi (हिन्दी)',
        'bn' => 'Bengali (বাংলা)', 'ur' => 'Urdu (اردو)', 'ta' => 'Tamil (தமிழ்)', 'th' => 'Thai (ไทย)',
        'vi' => 'Vietnamese (Tiếng Việt)', 'id' => 'Indonesian (Bahasa Indonesia)', 'ms' => 'Malay (Bahasa Melayu)',
        'tl' => 'Filipino (Tagalog)', 'zh' => 'Chinese (中文)', 'ja' => 'Japanese (日本語)', 'ko' => 'Korean (한국어)',
        'ca' => 'Catalan (Català)', 'eu' => 'Basque (Euskara)', 'gl' => 'Galician (Galego)', 'ga' => 'Irish (Gaeilge)',
        'cy' => 'Welsh (Cymraeg)', 'la' => 'Latin (Latina)', 'eo' => 'Esperanto', 'sw' => 'Swahili (Kiswahili)',
    ];

    /** @return array<string,string> code => label; a stored code outside the list keeps its own entry so an edit never loses it. */
    public static function options(string $current = ''): array
    {
        $list = self::LIST;
        if ($current !== '' && !isset($list[$current])) $list[$current] = $current;
        return $list;
    }
}
