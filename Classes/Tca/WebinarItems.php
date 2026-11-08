<?php

declare(strict_types=1);

namespace WapplerSystems\FormGotowebinar\Tca;

use WapplerSystems\FormGotowebinar\Service\WebinarProvider;

/**
 * Select items for tx_formgotowebinar_webinar.webinar_key: the upcoming
 * webinars of the connected GoTo account. A stored key that is no longer
 * upcoming stays selectable, so opening an old record does not show an
 * invalid value.
 */
class WebinarItems
{
    public function __construct(private readonly WebinarProvider $webinarProvider) {}

    /**
     * @param array<string, mixed> $parameters
     */
    public function addItems(array &$parameters): void
    {
        $current = (string)($parameters['row']['webinar_key'] ?? '');
        $found = false;
        foreach ($this->webinarProvider->upcomingWebinars() as $webinar) {
            $start = '';
            if ($webinar['startTime'] !== '') {
                try {
                    $start = (new \DateTimeImmutable($webinar['startTime']))
                        ->setTimezone(new \DateTimeZone($webinar['timeZone'] ?: 'Europe/Berlin'))
                        ->format('d.m.Y H:i') . ' · ';
                } catch (\Throwable) {
                }
            }
            $parameters['items'][] = ['label' => $start . $webinar['subject'], 'value' => $webinar['webinarKey']];
            $found = $found || $webinar['webinarKey'] === $current;
        }
        if ($current !== '' && !$found) {
            $parameters['items'][] = ['label' => $current . ' (not upcoming in GoTo)', 'value' => $current];
        }
    }
}
