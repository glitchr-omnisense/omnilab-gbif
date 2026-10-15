<?php

namespace Omnilab\Gbif\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Gbif\GbifSource;
use Omnilab\Gbif\GbifSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.gbif.org on 2026-10-15, every call naming the
 * Catalogue of Life eXtended Release (checklist 7ddf754f-...): GBIF's v2
 * name match by key (4CGXP, Panthera leo; 5K5L6, a synonym), by legacy
 * number (scientificNameID=gbif:5219404), by name (Panthera leo, Felis leo
 * - a synonym -, "lion" - none -, "Panthera xyzzz" - a higher rank only),
 * an unknown key; the checklist's v1 records of 4CGXP and 5K5L6; the
 * records related to their legacy numbers in the ENA taxonomy (NCBI's ids);
 * the ENA record of NCBI's 9689; a search for "Panthera leo", two pages of
 * three.
 */
final class GbifSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            unset($query['checklistKey']);
            $dataset = match ($query['datasetKey'] ?? null) {
                GbifSource::CHECKLIST => 'xr',
                GbifSource::ENA => 'ena',
                default => null,
            };
            unset($query['datasetKey']);
            $this->calls[] = ltrim($path, '/').($dataset ? ' '.$dataset : '').($query ? ' '.urldecode(http_build_query($query)) : '');
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                '/v2/species/match' === $path => match ($query['usageKey'] ?? $query['scientificNameID'] ?? $query['scientificName'] ?? null) {
                    '4CGXP' => 'match-4CGXP.json',
                    '5K5L6' => 'match-5K5L6.json',
                    'gbif:5219404' => 'match-legacy-5219404.json',
                    'Panthera leo' => 'match-name-panthera-leo.json',
                    'Felis leo' => 'match-name-felis-leo.json',
                    'lion' => 'match-name-lion.json',
                    'Panthera xyzzz' => 'match-name-higherrank.json',
                    default => 'match-unknown.json',
                },
                '/v1/species' === $path && 'ena' === $dataset => 'ena-9689.json',
                '/v1/species' === $path => 'record-'.$query['sourceId'].'.json',
                '/v1/species/5219404/related' === $path => 'related-5219404.json',
                '/v1/species/5219413/related' === $path => 'related-5219413.json',
                '/v1/species/search' === $path => isset($query['offset']) ? 'search-panthera-leo-2.json' : 'search-panthera-leo-1.json',
                default => null,
            };

            return null === $fixture || !is_file(__DIR__.'/Fixtures/'.$fixture)
                ? new MockResponse('{"offset":0,"limit":20,"endOfRecords":true,"results":[]}')
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new GbifSourceFactory($http))->create($options);
    }

    public function testTheLionByItsKeyInThreeCalls(): void
    {
        $lion = $this->source()->entity('4CGXP');

        self::assertSame('gbif:4CGXP', $lion->id->key());
        self::assertSame('Panthera leo', $lion->label);
        self::assertSame(Domain::TAXONOMY, $lion->domain);
        self::assertSame('species', $lion->kind);
        self::assertSame(['African Lion'], $lion->synonyms);
        self::assertSame('Panthera leo', $lion->value('taxon_name'));
        self::assertSame('(Linnaeus, 1758)', $lion->value('taxon_authority'));
        self::assertSame('accepted', $lion->value('taxonomic_status'));
        self::assertSame('zoological', $lion->value('nomenclatural_code'));
        self::assertSame('Vulnerable (VU)', $lion->value('iucn_status'));
        self::assertSame('Syst. Nat., 10th ed. vol.1 p.41', $lion->value('published_in'));
        self::assertSame(['Eukaryota', 'Animalia', 'Chordata', 'Mammalia', 'Carnivora', 'Felidae', 'Panthera'], [$lion->value('domain'), $lion->value('kingdom'), $lion->value('phylum'), $lion->value('class'), $lion->value('order'), $lion->value('family'), $lion->value('genus')]);
        self::assertSame('Eukaryota > Animalia > Chordata > Vertebrata > Gnathostomata > Osteichthyes > Tetrapoda > Mammalia > Theria > Eutheria > Carnivora > Feliformia > Felidae > Pantherinae > Panthera', $lion->value('lineage'));
        self::assertSame('gbif', $lion->property('iucn_status')->source);
        self::assertSame('https://www.gbif.org/taxon/4CGXP', $lion->url);
        self::assertSame(['match usageKey=4CGXP', 'v1/species xr sourceId=4CGXP', 'v1/species/5219404/related ena'], array_map(static fn (string $c) => str_replace('v2/species/', '', $c), $this->calls));
    }

    public function testItsLegacyNumberAndItsNcbiIdAreCrossReferencesItsParentRelated(): void
    {
        $lion = $this->source()->entity(Identifier::of(Scheme::GBIF, '4CGXP'));

        self::assertSame(['gbif:4CGXP', 'gbif:5219404', 'ncbi.taxon:9689'], $lion->identifiers()->keys());
        self::assertSame(['gbif:6DBT'], $lion->related('parent')->keys(), 'Panthera');
        self::assertSame(['gbif:4CGXP', 'gbif:5219404'], $this->source(null, ['ncbi' => false])->entity('4CGXP')->identifiers()->keys(), 'no NCBI id asked: two calls');
    }

    public function testTheSameLionByItsLegacyNumberItsNcbiIdOrItsName(): void
    {
        foreach (['5219404', 'gbif:5219404', 'https://www.gbif.org/species/5219404', 'https://www.gbif.org/taxon/4CGXP', 'Panthera leo', Identifier::of(Scheme::NCBI_TAXON, '9689'), 'taxonomy:9689'] as $id) {
            $this->calls = [];
            self::assertSame('gbif:4CGXP', $this->source()->entity($id)?->id->key(), (string) $id);
        }
        $this->calls = [];
        $this->source()->entity('5219404');
        self::assertSame('v2/species/match scientificNameID=gbif:5219404', $this->calls[0], 'GBIF\'s way from a legacy number to the new key');
        $this->calls = [];
        $this->source()->entity('taxonomy:9689');
        self::assertSame('v1/species ena sourceId=9689', $this->calls[0], 'NCBI\'s id through the ENA taxonomy, then its legacy number');
    }

    public function testASynonymNameGivesTheAcceptedTaxonASynonymKeyItself(): void
    {
        $lion = $this->source()->entity('Felis leo');
        self::assertSame('gbif:4CGXP', $lion?->id->key());
        self::assertContains('Felis leo', $lion->synonyms);

        $synonym = $this->source()->entity('5K5L6');
        self::assertSame('Panthera leo kamptzi', $synonym->label);
        self::assertSame('synonym', $synonym->value('taxonomic_status'));
        self::assertSame('Panthera leo leo (Linnaeus, 1758)', $synonym->value('accepted_name'));
        self::assertSame(['gbif:5K5L8'], $synonym->related('accepted')->keys());
        self::assertCount(0, $synonym->related('parent'), 'a synonym\'s classification is its accepted taxon\'s');
        self::assertSame(['gbif:5K5L6', 'gbif:5219413'], $synonym->identifiers()->keys(), 'no NCBI id in ENA for it');
    }

    public function testNoMatchIsNull(): void
    {
        self::assertNull($this->source()->entity('lion'), 'a common name is no scientific name');
        self::assertNull($this->source()->entity('Panthera xyzzz'), 'GBIF matches Animalia only: a higher rank is not the name asked');
        self::assertNull($this->source()->entity('ZZZZZ'));
        self::assertNull($this->source()->entity(''));
    }

    public function testWhatItDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::of(Scheme::UNIPROT, 'P68871'), Identifier::wikidata('Q140'), Identifier::of(Scheme::INATURALIST, '41964'), 'CHEBI:27732', 'Q140'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchPageByOffset(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('Panthera leo', limit: 3));

        self::assertSame(['gbif:4CGXP', 'gbif:5K5L6', 'gbif:5K5LD'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame(['Panthera leo', 'Panthera leo kamptzi', 'Panthera leo persica'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame([17, '3'], [$page->total, $page->next]);
        self::assertSame(['African Lion', 'Lion', 'Lioness'], $page->first()->synonyms, 'its English common names');
        self::assertSame('gbif:5219404', $page->first()->identifiers()->all(Scheme::GBIF)[1]->key(), 'its legacy number');
        self::assertSame('Felidae', $page->first()->value('family'));

        $second = $source->search(new Query('Panthera leo', limit: 3, cursor: $page->next));
        self::assertSame(['gbif:5K5L8', 'gbif:5K5LC', 'gbif:5K5L9'], array_map(static fn ($e) => $e->id->key(), $second->items));
        self::assertSame(['v1/species/search xr q=Panthera leo&limit=3', 'v1/species/search xr q=Panthera leo&limit=3&offset=3'], $this->calls);
        self::assertSame(['Amur Lion', 'Asian Lion', 'Asiatic Lion'], array_slice($second->first()->synonyms, 0, 3));
        self::assertSame([], $source->search(new Query('Panthera leo', Domain::CHEMISTRY))->items, 'nothing of another domain');
        self::assertCount(2, $this->calls);
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse('', ['http_code' => 503]))->entity('4CGXP');
            self::fail('a 503 is an UnavailableException');
        } catch (UnavailableException $e) {
            self::assertSame(503, $e->status);
        }
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->search(new Query('Panthera'));
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
