<?php

namespace Omnilab\Gbif;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * GBIF's taxonomy (the Catalogue of Life eXtended Release since June 2026):
 * taxa by key, legacy Backbone number, NCBI id or scientific name; the
 * occurrences of a place and a period - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent, as GBIF asks: whom it writes to before it slows a caller down
 *     ncbi: true                       # read a taxon's NCBI id (one call more)
 *     language: 'eng'                  # the common name a search result keeps, ISO 639-2
 *     radius_km: 10                    # the circle around a Point, for occurrences() when the query gives none
 *     checklist: '7ddf754f-d193-4cc9-b351-99906754a03b'   # the checklist answered from
 *     throttle: 0                      # seconds between two calls: GBIF publishes no figure, and slows a caller down with 429
 *     base_uri: 'https://api.gbif.org/'
 */
final class GbifSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'gbif',
            'omnilab.required_options' => [],
            'ncbi' => true,
            'language' => 'eng',
            'radius_km' => 10.0,
            'checklist' => GbifSource::CHECKLIST,
            'throttle' => 0.0,
            'base_uri' => GbifSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new GbifSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('checklist'),
            (bool) $c->get('ncbi'),
            (string) $c->get('language'),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri', GbifSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
