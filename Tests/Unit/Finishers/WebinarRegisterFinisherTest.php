<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Tests\Unit\Finishers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\FormGotowebinar\Finishers\WebinarRegisterFinisher;
use WapplerSystems\FormGotowebinar\GoTo\ApiException;

final class WebinarRegisterFinisherTest extends TestCase
{
    #[Test]
    public function registrantTakesKnownFieldsAndAnswersOnly(): void
    {
        $registrant = WebinarRegisterFinisher::buildRegistrant(
            'Ada',
            'Lovelace',
            'ada@example.com',
            'website',
            ['organization' => 'LINEAR', 'jobTitle' => '', 'notAGoToField' => 'x', 'phone' => ['0241', '123']],
            ['4711' => 'Revit', '4712' => ''],
        );
        self::assertSame([
            'firstName' => 'Ada',
            'lastName' => 'Lovelace',
            'email' => 'ada@example.com',
            'source' => 'website',
            'organization' => 'LINEAR',
            'phone' => '0241, 123',
            'responses' => [['questionKey' => '4711', 'responseText' => 'Revit']],
        ], $registrant);
    }

    public static function closedCases(): array
    {
        return [
            'registration closed' => [403, '{"description":"Registration is closed for this webinar"}', true],
            'full' => [400, '{"description":"Webinar is full"}', true],
            'unknown 403' => [403, '{"description":"Forbidden"}', false],
            'outage' => [503, '{"msg":"Registration closed"}', false],
        ];
    }

    #[Test]
    #[DataProvider('closedCases')]
    public function detectsClosedRegistration(int $status, string $body, bool $expected): void
    {
        self::assertSame($expected, WebinarRegisterFinisher::isRegistrationClosed(new ApiException('x', $status, $body)));
    }
}
