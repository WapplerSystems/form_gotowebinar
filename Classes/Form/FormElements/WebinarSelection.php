<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Form\FormElements;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Domain\Model\FormElements\GenericOptionableFormElement;
use WapplerSystems\FormGotowebinar\Service\WebinarProvider;

/**
 * Multi checkbox whose options are the upcoming GoTo webinars.
 *
 * Properties (form definition):
 *   storagePid        folder(s) with the webinar records, comma-separated
 *   includeUnmanaged  also offer GoTo webinars without a record (default true)
 *   subjectFilter     unmanaged webinars only if the subject contains this
 *   showEnded         also list webinars that have taken place, without
 *                     checkbox (default false)
 *   showSelectAll     button above the list that ticks every webinar and
 *                     moves on to the personal details (default false)
 *
 * The options are built on first access, not in initializeFormElement():
 * at that point the form factory has not yet applied the properties above.
 * "tiles" carries the full data for the template, "options" the
 * key => title map the rest of EXT:form (summary, mails) works with; it
 * holds only bookable webinars, as does what the finisher accepts.
 */
class WebinarSelection extends GenericOptionableFormElement
{
    /** @var list<array<string, mixed>>|null */
    private ?array $tiles = null;

    public function getProperties(): array
    {
        $properties = parent::getProperties();
        $tiles = $this->tiles($properties);
        $options = [];
        foreach ($tiles as $tile) {
            if ($tile['bookable']) {
                $options[$tile['key']] = $tile['title'];
            }
        }
        $properties['options'] = $options;
        $properties['tiles'] = $tiles;
        $properties['bookableCount'] = count($options);
        return $properties;
    }

    /**
     * @param array<string, mixed> $properties
     * @return list<array<string, mixed>>
     */
    private function tiles(array $properties): array
    {
        if ($this->tiles === null) {
            $storagePids = GeneralUtility::intExplode(',', (string)($properties['storagePid'] ?? ''), true);
            $provider = GeneralUtility::makeInstance(WebinarProvider::class);
            $this->tiles = $provider->tiles(
                array_values(array_filter($storagePids)),
                (bool)($properties['includeUnmanaged'] ?? true),
                trim((string)($properties['subjectFilter'] ?? '')),
                (bool)($properties['showEnded'] ?? false),
            );
            // The tiles end up in the page cache. Without a limit an ended,
            // full or newly added webinar would show only after a cache
            // flush (page cache lifetime is a year by default).
            $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
            $collector = $request instanceof ServerRequestInterface ? $request->getAttribute('frontend.cache.collector') : null;
            if ($collector instanceof CacheDataCollector) {
                $collector->addCacheTags(new CacheTag('tx_formgotowebinar', $provider->cacheLifetime()));
            }
        }
        return $this->tiles;
    }
}
