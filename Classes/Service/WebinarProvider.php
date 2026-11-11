<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use WapplerSystems\FormGotowebinar\GoTo\ApiException;
use WapplerSystems\FormGotowebinar\GoTo\Client;
use WapplerSystems\FormGotowebinar\GoTo\TokenStore;

/**
 * Joins the upcoming webinars from GoTo with the webinar records editors
 * maintain in TYPO3 (graphic, host, own text).
 *
 * GoTo is the source for what can be booked: a record whose webinar is
 * over, cancelled or full cannot be booked. The record only adds the
 * presentation. Ended webinars can still be listed (without a checkbox);
 * GoTo's list holds only future dates, so they come from the webinars
 * remembered from earlier fetches.
 */
class WebinarProvider
{
    public const TABLE = 'tx_formgotowebinar_webinar';
    private const CACHE_KEY = 'upcoming';
    /** Ended webinars are listed for this long. */
    private const KEEP_ENDED = '-12 months';

    public function __construct(
        private readonly Client $client,
        private readonly TokenStore $tokenStore,
        private readonly FrontendInterface $cache,
        private readonly ConnectionPool $connectionPool,
        private readonly FileRepository $fileRepository,
        private readonly Context $context,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Upcoming, bookable webinars from GoTo; cached, and on an API error the
     * last good list (without what has ended since).
     *
     * @return list<array<string, string>>
     */
    public function upcomingWebinars(): array
    {
        $webinars = $this->cache->get(self::CACHE_KEY);
        if (!is_array($webinars)) {
            try {
                $now = new \DateTimeImmutable();
                $webinars = $this->client->upcomingWebinars($now, $now->modify('+18 months'));
                $this->storeFetched($webinars);
                $this->cache->set(self::CACHE_KEY, $webinars, [], $this->cacheLifetime());
            } catch (ApiException $e) {
                $this->logger->error('form_gotowebinar: webinar list not fetched, using the last known list', [
                    'status' => $e->status,
                    'error' => $e->errorMessage() ?: $e->getMessage(),
                ]);
                $webinars = $this->tokenStore->lastWebinars() ?? [];
                // Short cache, so the API is asked again soon but not on every hit.
                $this->cache->set(self::CACHE_KEY, $webinars, [], 60);
            }
        }
        $unavailable = $this->unavailableKeys();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return array_values(array_filter(
            $webinars,
            static fn(array $w): bool => ($w['endTime'] ?? '') >= $now && !in_array($w['webinarKey'], $unavailable, true)
        ));
    }

    /**
     * Webinars that have taken place, oldest first.
     *
     * @return list<array<string, string>>
     */
    public function endedWebinars(): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return array_values(array_filter(
            $this->tokenStore->knownWebinars(),
            static fn(array $w): bool => ($w['endTime'] ?? '') < $now
        ));
    }

    /**
     * Keeps a freshly fetched list: as fallback for API errors and in the
     * memory the ended webinars are taken from.
     *
     * @param list<array<string, string>> $webinars
     */
    public function storeFetched(array $webinars): void
    {
        $this->tokenStore->storeLastWebinars($webinars);
        $this->tokenStore->storeKnownWebinars(self::remember(
            $this->tokenStore->knownWebinars(),
            $webinars,
            gmdate('Y-m-d\TH:i:s\Z'),
            gmdate('Y-m-d\TH:i:s\Z', (int)strtotime(self::KEEP_ENDED)),
        ));
    }

    /**
     * Adds a fetched list to the remembered webinars. A remembered webinar
     * that has not ended but is missing from GoTo's list was cancelled and
     * is dropped, so it never turns up as "taken place"; ended ones are
     * kept until $keepSince.
     *
     * @param list<array<string, string>> $known
     * @param list<array<string, string>> $fetched
     * @return list<array<string, string>> date order
     */
    public static function remember(array $known, array $fetched, string $now, string $keepSince): array
    {
        $byKey = [];
        foreach ($known as $webinar) {
            $end = $webinar['endTime'] ?? '';
            if ($end !== '' && $end < $now && $end >= $keepSince) {
                $byKey[$webinar['webinarKey']] = $webinar;
            }
        }
        foreach ($fetched as $webinar) {
            $byKey[$webinar['webinarKey']] = $webinar;
        }
        $webinars = array_values($byKey);
        usort($webinars, static fn(array $a, array $b): int => strcmp($a['startTime'], $b['startTime']));
        return $webinars;
    }

    /**
     * Takes a webinar off the page after GoTo refused a registration
     * because registration is closed or the webinar is full.
     */
    public function markUnavailable(string $webinarKey): void
    {
        $keys = $this->unavailableKeys();
        if (!in_array($webinarKey, $keys, true)) {
            $keys[] = $webinarKey;
            $this->cache->set('unavailable', $keys, [], 86400);
        }
    }

    public function flush(): void
    {
        $this->cache->flush();
    }

    /**
     * Tiles for the form element, in date order.
     *
     * @param int[] $storagePids folders holding the webinar records
     * @param bool $includeUnmanaged also show GoTo webinars without a record
     * @param string $subjectFilter unmanaged webinars only if their subject contains this
     * @param bool $includeEnded also list webinars that have taken place, not bookable
     * @return list<array<string, mixed>>
     */
    public function tiles(array $storagePids, bool $includeUnmanaged = true, string $subjectFilter = '', bool $includeEnded = false): array
    {
        $records = $storagePids !== [] ? $this->records($storagePids) : [];
        $webinars = $this->upcomingWebinars();
        if ($includeEnded) {
            $ended = array_map(static fn(array $w): array => $w + ['ended' => true], $this->endedWebinars());
            $webinars = array_merge($ended, $webinars);
        }
        return self::merge($webinars, $records, $includeUnmanaged, $subjectFilter);
    }

    /**
     * @param list<array<string, mixed>> $webinars GoTo webinars, date order; "ended" marks those that took place
     * @param list<array<string, mixed>> $records webinar records
     * @return list<array<string, mixed>>
     */
    public static function merge(array $webinars, array $records, bool $includeUnmanaged, string $subjectFilter): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $key = trim((string)($record['webinar_key'] ?? ''));
            if ($key !== '' && !isset($byKey[$key])) {
                $byKey[$key] = $record;
            }
        }

        $tiles = [];
        foreach ($webinars as $webinar) {
            $record = $byKey[$webinar['webinarKey']] ?? null;
            if ($record === null) {
                if (!$includeUnmanaged) {
                    continue;
                }
                if ($subjectFilter !== '' && mb_stripos($webinar['subject'], $subjectFilter) === false) {
                    continue;
                }
            }
            $timeZone = self::timeZone($webinar['timeZone'] ?? '');
            $tiles[] = [
                'key' => $webinar['webinarKey'],
                'managed' => $record !== null,
                'bookable' => empty($webinar['ended']),
                'title' => trim((string)($record['title'] ?? '')) ?: $webinar['subject'],
                'label' => trim((string)($record['label'] ?? '')),
                // Record text is RTE HTML, GoTo's description plain text.
                'descriptionHtml' => trim((string)($record['description'] ?? '')),
                'descriptionText' => $record === null || trim((string)($record['description'] ?? '')) === '' ? $webinar['description'] : '',
                'hostName' => trim((string)($record['host_name'] ?? '')),
                'image' => $record['_image'] ?? null,
                'hostImage' => $record['_host_image'] ?? null,
                'start' => self::date($webinar['startTime'], $timeZone),
                'end' => self::date($webinar['endTime'], $timeZone),
            ];
        }
        return $tiles;
    }

    /**
     * @param int[] $storagePids
     * @return list<array<string, mixed>>
     */
    private function records(array $storagePids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter(array_map('intval', $storagePids), \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->in('sys_language_uid', [0, -1]),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var LanguageAspect $languageAspect */
        $languageAspect = $this->context->getAspect('language');
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $this->context);

        $records = [];
        foreach ($rows as $row) {
            if ($languageAspect->getContentId() > 0) {
                $row = $pageRepository->getLanguageOverlay(self::TABLE, $row, $languageAspect);
                if (!is_array($row)) {
                    continue;
                }
            }
            $uid = (int)($row['_LOCALIZED_UID'] ?? $row['uid']);
            $row['_image'] = $this->firstFile($uid, 'image');
            $row['_host_image'] = $this->firstFile($uid, 'host_image');
            $records[] = $row;
        }
        return $records;
    }

    private function firstFile(int $uid, string $field): ?FileReference
    {
        $files = $this->fileRepository->findByRelation(self::TABLE, $field, $uid);
        return $files[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function unavailableKeys(): array
    {
        $keys = $this->cache->get('unavailable');
        return is_array($keys) ? $keys : [];
    }

    public function cacheLifetime(): int
    {
        try {
            $lifetime = (int)$this->extensionConfiguration->get('form_gotowebinar', 'cacheLifetime');
        } catch (\Throwable) {
            $lifetime = 0;
        }
        return $lifetime > 0 ? $lifetime : 600;
    }

    private static function timeZone(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name !== '' ? $name : 'Europe/Berlin');
        } catch (\Throwable) {
            return new \DateTimeZone('Europe/Berlin');
        }
    }

    private static function date(string $value, \DateTimeZone $timeZone): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone($timeZone);
        } catch (\Throwable) {
            return null;
        }
    }
}
