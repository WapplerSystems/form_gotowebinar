<?php

declare(strict_types=1);

namespace WapplerSystems\GotoWebinar\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\GotoWebinar\Service\WebinarProvider;

final class WebinarProviderTest extends TestCase
{
    private const WEBINARS = [
        ['webinarKey' => '1', 'subject' => 'LINEAR@WORK 01', 'description' => 'GoTo text 1', 'startTime' => '2026-11-18T09:00:00Z', 'endTime' => '2026-11-18T10:00:00Z', 'timeZone' => 'Europe/Berlin', 'registrationUrl' => ''],
        ['webinarKey' => '99', 'subject' => 'Interne Schulung', 'description' => '', 'startTime' => '2026-11-25T09:00:00Z', 'endTime' => '2026-11-25T10:00:00Z', 'timeZone' => 'Europe/Berlin', 'registrationUrl' => ''],
        ['webinarKey' => '2', 'subject' => 'LINEAR@WORK 02', 'description' => 'GoTo text 2', 'startTime' => '2026-12-02T09:00:00Z', 'endTime' => '2026-12-02T10:00:00Z', 'timeZone' => 'Europe/Berlin', 'registrationUrl' => ''],
    ];

    #[Test]
    public function recordAddsPresentationAndGoToKeepsDateOrder(): void
    {
        $records = [
            ['webinar_key' => '2', 'title' => '', 'label' => 'WEBINAR 02 / 13', 'description' => '<p>Eigener Text</p>', 'host_name' => 'Christian Streller'],
        ];
        $tiles = WebinarProvider::merge(self::WEBINARS, $records, true, '');

        self::assertSame(['1', '99', '2'], array_column($tiles, 'key'));
        self::assertSame('LINEAR@WORK 02', $tiles[2]['title'], 'empty record title falls back to GoTo');
        self::assertSame('<p>Eigener Text</p>', $tiles[2]['descriptionHtml']);
        self::assertSame('', $tiles[2]['descriptionText'], 'GoTo text only when the record has none');
        self::assertSame('GoTo text 1', $tiles[0]['descriptionText']);
        self::assertSame('10:00', $tiles[0]['start']->format('H:i'), 'shown in the webinar time zone');
        self::assertTrue($tiles[2]['managed']);
        self::assertFalse($tiles[0]['managed']);
    }

    #[Test]
    public function recordWithoutUpcomingWebinarIsNotShown(): void
    {
        $records = [['webinar_key' => '7', 'title' => 'Vorbei', 'label' => '', 'description' => '', 'host_name' => '']];
        self::assertSame([], WebinarProvider::merge([], $records, true, ''));
    }

    #[Test]
    public function unmanagedWebinarsCanBeFilteredOrSuppressed(): void
    {
        self::assertSame(['1', '2'], array_column(WebinarProvider::merge(self::WEBINARS, [], true, 'linear@work'), 'key'));
        $records = [['webinar_key' => '1', 'title' => '', 'label' => '', 'description' => '', 'host_name' => '']];
        self::assertSame(['1'], array_column(WebinarProvider::merge(self::WEBINARS, $records, false, ''), 'key'));
    }
}
