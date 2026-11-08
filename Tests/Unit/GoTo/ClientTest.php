<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Tests\Unit\GoTo;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\FormGotowebinar\Command\ConnectCommand;
use WapplerSystems\FormGotowebinar\GoTo\ApiException;
use WapplerSystems\FormGotowebinar\GoTo\Client;

final class ClientTest extends TestCase
{
    #[Test]
    public function normalizeTakesNextSessionOfASeries(): void
    {
        $webinar = Client::normalizeWebinar([
            'webinarKey' => '9223372036854775899',
            'subject' => ' Reihe ',
            'times' => [
                ['startTime' => '2020-01-01T09:00:00Z', 'endTime' => '2020-01-01T10:00:00Z'],
                ['startTime' => '2099-01-01T09:00:00Z', 'endTime' => '2099-01-01T10:00:00Z'],
            ],
        ]);
        self::assertSame('9223372036854775899', $webinar['webinarKey']);
        self::assertSame('Reihe', $webinar['subject']);
        self::assertSame('2099-01-01T09:00:00Z', $webinar['startTime']);
        self::assertNull(Client::normalizeWebinar(['subject' => 'no key']));
    }

    #[Test]
    public function duplicateRegistrantBodyKeepsBigKeysAsStrings(): void
    {
        $e = new ApiException('dup', 409, '{"registrantKey":9223372036854775899,"joinUrl":"https://x"}');
        self::assertTrue($e->isDuplicateRegistrant());
        self::assertSame('9223372036854775899', (string)$e->decodedBody()['registrantKey']);
    }

    #[Test]
    public function connectAcceptsCodeOrRedirectUrl(): void
    {
        self::assertSame('abc', ConnectCommand::extractCode('https://www.linear.eu/?code=abc&state=x'));
        self::assertSame('abc', ConnectCommand::extractCode(' abc '));
    }
}
