<?php

namespace Omnilab\Gbif\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Gbif\GbifSource;
use Omnilab\Gbif\GbifSourceFactory;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\OccurrenceQuery;
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
 * three. The occurrence search: the lions (4CGXP) placed and dated within
 * 100 km of Skukuza (-24.99, 31.59) in 2025, two pages of three of the 412;
 * two preserved specimens of lions, dated by the day; the record of the
 * first occurrence (5007681734).
 */
final class GbifSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var list<string> the URLs asked, whole */
    private array $urls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $this->urls[] = rawurldecode($url);
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
                '/v1/occurrence/search' === $path => match (true) {
                    isset($query['basisOfRecord']) => 'occurrences-specimens.json',
                    '-24.99,31.59,100km' !== ($query['geoDistance'] ?? null) => null,
                    isset($query['offset']) => 'occurrences-skukuza-2.json',
                    default => 'occurrences-skukuza-1.json',
                },
                '/v1/occurrence/5007681734' === $path => 'occurrence-5007681734.json',
                str_starts_with($path, '/v1/occurrence/') => 'not-found',
                default => null,
            };

            if ('not-found' === $fixture) {
                return new MockResponse('', ['http_code' => 404]);
            }

            return null === $fixture || !is_file(__DIR__.'/Fixtures/'.$fixture)
                ? new MockResponse('{"offset":0,"limit":20,"endOfRecords":true,"count":0,"results":[]}')
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

    public function testTheOccurrencesOfAPlaceAndAPeriod(): void
    {
        $page = $this->source()->occurrences(new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => '4CGXP'], radius: 100, limit: 3));

        self::assertSame(['https://api.gbif.org/v1/occurrence/search?taxonKey=4CGXP&checklistKey='.GbifSource::CHECKLIST.'&hasCoordinate=true&occurrenceStatus=PRESENT&eventDate=2025-01-01,2025-12-31&limit=3&geoDistance=-24.99,31.59,100km'], $this->urls, 'one call, placed, present and dated only');
        self::assertSame([3, 412, '3'], [\count($page), $page->total, $page->next]);
        $lion = $page->first();
        self::assertSame('gbif.occurrence:5007681734', $lion->id->key());
        self::assertSame('Panthera leo melanochaita - South Africa, 2025-01-03', $lion->label);
        self::assertSame([Domain::TAXONOMY, 'occurrence'], [$lion->domain, $lion->kind]);
        self::assertSame('2025-01-03T17:38:00+02:00', $lion->time->format(\DATE_ATOM), 'the date as given, at the offset of its eventTime');
        self::assertEquals(new Point(-24.205889, 31.287023), $lion->place);
        self::assertSame(['gbif:7KGW9', 'gbif:5219411'], $lion->related('taxon')->keys(), 'what it is of: related, its key and its legacy number');
        self::assertSame(['inaturalist.observation:257284120'], $lion->identifiers->keys(), 'an iNaturalist observation shared with GBIF: the same record, which the Collector can follow');
        self::assertSame(['Panthera leo melanochaita', 'Panthera leo melanochaita (C. E. H. Smith, 1858)', 'subspecies', 'Animalia', 'Felidae', 'Panthera'], [$lion->value('taxon_name'), $lion->value('scientific_name'), $lion->value('taxon_rank'), $lion->value('kingdom'), $lion->value('family'), $lion->value('genus')]);
        self::assertSame(['human observation', '2025-01-03T17:38', 'ZA', 'Mpumalanga', 'iNaturalist research-grade observations'], [$lion->value('basis_of_record'), $lion->value('event_date'), $lion->value('country_code'), $lion->value('state_province'), $lion->value('dataset')]);
        self::assertSame('30090 m', (string) $lion->property('coordinate_uncertainty'));
        self::assertSame('Coordinate uncertainty increased to 30090m to protect threatened taxon', $lion->value('information_withheld'));
        self::assertSame('https://www.gbif.org/occurrence/5007681734', $lion->url);
        self::assertSame('https://inaturalist-open-data.s3.amazonaws.com/photos/461450172/original.jpeg', $lion->image);
        foreach ($page as $occurrence) {
            self::assertTrue(null !== $occurrence->time && null !== $occurrence->place && (new Point(-24.99, 31.59))->around(100)->contains($occurrence->place), 'dated, placed, within 100 km');
        }
    }

    public function testTheNextPageByOffset(): void
    {
        $source = $this->source();
        $query = new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => Identifier::of(Scheme::GBIF, '4CGXP')], radius: 100, limit: 3);
        $second = $source->occurrences($query->with(['cursor' => $source->occurrences($query)->next]));

        self::assertStringEndsWith('&limit=3&offset=3&geoDistance=-24.99,31.59,100km', $this->urls[1]);
        self::assertSame(['gbif.occurrence:5063147373', 'gbif.occurrence:5063158538', 'gbif.occurrence:5063617884'], array_map(static fn ($o) => $o->id->key(), $second->items));
        self::assertSame('6', $second->next);
        self::assertSame('2025-01-11T05:24:10+02:00', $second->first()->time->format(\DATE_ATOM));
    }

    public function testThePlaceThePeriodAndTheFiltersAsGbifTakesThem(): void
    {
        $source = $this->source(null, ['radius_km' => 25]);
        $source->occurrences(new OccurrenceQuery(new BBox(30.8, -25.6, 32.1, -22.3), new \DateTimeImmutable('2025-01-01T12:00:00'), filters: [OccurrenceQuery::TAXON => 'Panthera leo', OccurrenceQuery::COUNTRY => ['za', 'MZ']], limit: 1000));
        $source->occurrences(new OccurrenceQuery(new BBox(170, -20, -170, -15), to: new \DateTimeImmutable('2025-01-31'), filters: [OccurrenceQuery::TAXON => '5219404', OccurrenceQuery::KIND => 'machine observation']));
        $source->occurrences(new OccurrenceQuery(new Point(48.5734, 7.7521)));

        self::assertSame('https://api.gbif.org/v1/occurrence/search?scientificName=Panthera leo&checklistKey='.GbifSource::CHECKLIST.'&country=ZA&country=MZ&hasCoordinate=true&occurrenceStatus=PRESENT&eventDate=2025-01-01,*&limit=300&decimalLatitude=-25.6,-22.3&decimalLongitude=30.8,32.1', $this->urls[0], 'a name matched in the checklist; whole days, an open end; 300 a page at most');
        self::assertSame('https://api.gbif.org/v1/occurrence/search?taxonKey=5219404&basisOfRecord=MACHINE_OBSERVATION&hasCoordinate=true&occurrenceStatus=PRESENT&eventDate=*,2025-01-31&limit=25&geometry=POLYGON((170 -20,180 -20,180 -15,170 -15,170 -20))&geometry=POLYGON((-180 -20,-170 -20,-170 -15,-180 -15,-180 -20))', $this->urls[1], 'a legacy number in the Backbone, no checklist named; a box across the antimeridian in two polygons');
        self::assertStringEndsWith('&eventDate=*,*&limit=25&geoDistance=48.5734,7.7521,25km', $this->urls[2], 'the configured radius; dated, whenever');
    }

    public function testAnOccurrenceByItsKeyInOneCall(): void
    {
        $lion = $this->source()->entity('gbif.occurrence:5007681734');

        self::assertSame(['v1/occurrence/5007681734'], $this->calls);
        self::assertSame('Panthera leo melanochaita - South Africa, 2025-01-03', $lion?->label);
        self::assertSame($this->source()->occurrences(new OccurrenceQuery(new Point(-24.99, 31.59), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), [OccurrenceQuery::TAXON => '4CGXP'], radius: 100, limit: 3))->first()->keys(), $lion->keys(), 'a page holds what the record holds');
        self::assertSame('gbif.occurrence:5007681734', $this->source()->entity('https://www.gbif.org/occurrence/5007681734')?->id->key());
        self::assertNull($this->source()->entity(Identifier::of(Scheme::GBIF_OCCURRENCE, '1')), 'unknown');
    }

    public function testASpecimenDatedByTheDay(): void
    {
        $page = $this->source()->occurrences(new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => '4CGXP', OccurrenceQuery::KIND => ['preserved_specimen']], limit: 2));
        $specimen = $page->first();

        self::assertSame([317, 'preserved specimen', 'MSB', 'Panthera leo'], [$page->total, $specimen->value('basis_of_record'), $specimen->value('institution_code'), $specimen->value('taxon_name')]);
        self::assertSame(['2023-02-22', '2023-02-22T00:00:00+00:00'], [$specimen->value('event_date'), $specimen->time->format(\DATE_ATOM)], 'a day without a time: its midnight, UTC');
        self::assertStringContainsString('&basisOfRecord=PRESERVED_SPECIMEN&', $this->urls[0]);
    }

    public function testWhatOccurrencesRefuseBeforeAnyCall(): void
    {
        $refused = [
            'a sort: GBIF has none' => [new OccurrenceQuery(sort: OccurrenceQuery::NEWEST), NotSupportedException::class],
            'a filter of another domain' => [new OccurrenceQuery(filters: [OccurrenceQuery::MIN_MAGNITUDE => 4]), NotSupportedException::class],
            'a taxon of another base' => [new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => Identifier::of(Scheme::NCBI_TAXON, '9689')]), NotSupportedException::class],
            'a country of three letters' => [new OccurrenceQuery(filters: [OccurrenceQuery::COUNTRY => 'ZAF']), \InvalidArgumentException::class],
            'past GBIF\'s 100,000' => [new OccurrenceQuery(cursor: '100000'), NotSupportedException::class],
        ];
        foreach ($refused as $what => [$query, $exception]) {
            try {
                $this->source()->occurrences($query);
                self::fail($what);
            } catch (\Exception $e) {
                self::assertInstanceOf($exception, $e, $what);
            }
        }
        self::assertSame([], $this->calls);
        $this->source()->occurrences(new OccurrenceQuery(limit: 300, cursor: '99900'));
        self::assertStringContainsString('&limit=100&offset=99900', $this->urls[0], 'the last page GBIF answers');
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
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::OCCURRENCES, Capability::AREA, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }
}
