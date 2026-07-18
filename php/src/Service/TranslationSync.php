<?php
declare(strict_types=1);

namespace Tds\Ext\WebsiteCms\Service;

use Tds\Ext\WebsiteCms\Domain\CmsRepository;

/**
 * Save-time DeepL sync for content blocks (a website-cms-scoped port of
 * tds-content-api's TranslationSync). When an admin saves a block in one
 * language, the counterpart-language block is auto-created/refreshed — but only
 * when it's absent or itself machine-made:
 *
 *   counterpart absent               → create it, machine_translated=1
 *   counterpart machine_translated=1 → overwrite with a fresh translation
 *   counterpart machine_translated=0 → hands off (manually authored)
 *
 * Only the human-copy leaves of the block JSON are translated (URLs/ids/icons
 * stay intact — see TranslatableJsonWalker). Writes go straight through the repo
 * (never the route) so the sync can't ping-pong; any DeepL failure only logs.
 */
final class TranslationSync
{
    public function __construct(
        private readonly CmsRepository $blocks,
        private readonly DeeplTranslator $translator,
        private readonly TranslatableJsonWalker $walker,
        private readonly bool $enabled,
    ) {
    }

    public static function fromEnv(CmsRepository $blocks, DeeplTranslator $translator): self
    {
        $flag = getenv('WEBSITE_AUTO_TRANSLATE');
        $enabled = $flag === false ? true : !in_array(strtolower((string) $flag), ['0', 'false', 'no', 'off'], true);
        return new self($blocks, $translator, new TranslatableJsonWalker(), $enabled);
    }

    public function active(): bool
    {
        return $this->enabled && $this->translator->configured();
    }

    /**
     * @param mixed $value the just-saved block value (decoded)
     * @return bool true when a counterpart block was actually written
     */
    public function afterSave(int $siteId, string $sectionKey, string $sourceLang, mixed $value): bool
    {
        if (!$this->active() || !is_array($value)) {
            return false;
        }
        $other = self::otherLang($sourceLang);

        $existing = $this->blocks->getBlockRow($siteId, $sectionKey, $other);
        if ($existing !== null && (int) ($existing['machine_translated'] ?? 0) === 0) {
            return false; // manually authored translation — never touched
        }

        $texts = $this->walker->collect($value);
        if ($texts === []) {
            return false;
        }
        $translated = $this->translator->translateTexts($texts, $other, $sourceLang);
        if ($translated === null) {
            error_log(sprintf(
                '[website-cms] auto-translation skipped for %s/%s → %s (DeepL unavailable)',
                $sourceLang,
                $sectionKey,
                $other,
            ));
            return false;
        }

        $localised = $this->walker->apply($value, $translated);
        $this->blocks->putBlock($siteId, $sectionKey, $other, json_encode($localised, JSON_THROW_ON_ERROR), true);
        return true;
    }

    public function afterDelete(int $siteId, string $sectionKey, string $sourceLang): void
    {
        if (!$this->enabled) {
            return;
        }
        $other = self::otherLang($sourceLang);
        $counterpart = $this->blocks->getBlockRow($siteId, $sectionKey, $other);
        if ($counterpart !== null && (int) ($counterpart['machine_translated'] ?? 0) === 1) {
            $this->blocks->deleteBlock($siteId, $sectionKey, $other);
        }
    }

    private static function otherLang(string $lang): string
    {
        return $lang === 'de' ? 'en' : 'de';
    }
}
