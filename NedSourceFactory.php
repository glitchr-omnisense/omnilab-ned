<?php

namespace Omnilab\Ned;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * NED, the NASA/IPAC Extragalactic Database: galaxies, quasars, clusters
 * and the other objects beyond our galaxy by any of their names, their
 * type, position and redshift - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0                      # seconds between two calls: NED publishes no rate
 *     base_uri: 'https://ned.ipac.caltech.edu/'
 */
final class NedSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'ned',
            'omnilab.required_options' => [],
            'throttle' => 0.0,
            'base_uri' => NedSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new NedSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', NedSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
